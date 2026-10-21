<?php

declare(strict_types=1);

namespace Larena\Storage\Encryption;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use JsonException;
use Larena\Storage\Contracts\StorageWorkbenchEncryptionRun;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\View\StorageViewSync;
use stdClass;

/**
 * Rewrites the stored form of a storage's fields in place, for one encryption run (larena-specs
 * docs/architecture/2026-10-22-storage-field-encryption.md, «Turning encryption on and off»). The schema switched
 * before the run was queued, so every write since follows the new setting; the run brings what was stored before
 * to it. This is the one audited exception to version immutability: a version's meaning never changes, only its
 * stored form.
 *
 * 1. `versions`: every row of `larena_storage_record_versions` of the storage, every revision and not only the
 *    heads, in (record id, revision) order, in chunks of their own transactions. Per chunk the storage's schema row
 *    is held for share (as writers do; a structure change waits), then the record heads for update, then the
 *    versions for update (the writers' order). Each version's content hash is checked against its values before
 *    anything changes (`storage_field_encryption_record_corrupt`), the values are rewritten (`encrypt` seals the
 *    plaintext of the run's fields under the target key, `decrypt` opens their envelopes, `rotate` seals again
 *    every envelope of the retiring key, whatever field it is in) and `values_json` and `content_hash` written
 *    again. When the version is its record's head, the head's `current_hash` and the витрина row's hash move with
 *    it (StorageViewSync::recordRehashed), so the витрина checker and the SitePack export's hash check stay clean.
 * 2. `localized`: every row of `larena_storage_localized_values` of the storage (of the run's fields; every field
 *    for a rotation), by row id, the same way, with the locale in the associated data.
 * 3. `final`: a catch-up pass over the rows written since the run was asked for (written under the new setting
 *    already; this is the safety net), then one transaction that holds the schema row for update (every writer has
 *    committed), checks those rows once more and ends the run (`done`); the caller's finish runs inside it (the audit
 *    event; for a rotation the retiring key is deleted, once no row names it).
 *
 * The position is saved with every chunk: a run that stops (a queue cancel, a timeout, a crash) resumes there. A
 * newer walk takes the run over; the older one stops at its next chunk (`storage_field_encryption_interrupted`). A
 * row that cannot be rewritten (a key that does not unwrap, an envelope that does not open) fails the run with that
 * reason; a retry resumes it. Nothing here carries a value or key material in a message.
 */
final readonly class StorageFieldEncryptionRunner
{
    public const DEFAULT_CHUNK = 500;
    public const MAX_CHUNK = 10000;
    public const REASON_INTERRUPTED = 'storage_field_encryption_interrupted';
    public const REASON_CORRUPT = 'storage_field_encryption_record_corrupt';
    public const REASON_KEY_STILL_USED = 'storage_field_encryption_key_still_used';
    // The rows the last pass checks again: written since the run was asked for, with a margin for clocks.
    private const CATCH_UP_MARGIN_SECONDS = 300;
    private const VERSIONS = 'larena_storage_record_versions';
    private const RECORDS = 'larena_storage_records';
    private const LOCALIZED = 'larena_storage_localized_values';
    private const SCHEMAS = 'larena_storage_schemas';

    public function __construct(
        private ConnectionInterface $database,
        private StorageFieldEncryptionRuns $runs,
        private StorageDataKeys $keys,
        private SchemaDefinitionNormalizer $normalizer,
        private ?StorageViewSync $views = null,
        private StorageFieldCipher $cipher = new StorageFieldCipher(),
    ) {
    }

    /**
     * Runs a run to its end. $finish runs inside the final transaction, after the last rows were checked. A run
     * that fails is marked failed with its reason, then its StorageRejected is thrown; a walk another one took over
     * throws StorageConflict (storage_field_encryption_interrupted); a database error is the caller's.
     *
     * @param Closure(): void|null $checkpoint runs between chunks (the queue job's heartbeat)
     * @param Closure(StorageFieldEncryptionRunRow): void $finish
     * @return array{state: string, rewritten: int, skipped: int, resumed: bool}
     */
    public function run(string $runRef, int $chunk, ?Closure $checkpoint, Closure $finish): array
    {
        if ($chunk < 1 || $chunk > self::MAX_CHUNK) {
            throw new StorageRejected('storage_field_encryption_chunk_invalid');
        }
        $run = $this->runs->find($runRef);
        if ($run === null) {
            throw new StorageRejected('storage_field_encryption_run_unknown');
        }
        if (!$run->running()) {
            return ['state' => $run->state, 'rewritten' => $run->rewritten, 'skipped' => $run->skipped, 'resumed' => false];
        }
        $workerRef = 'storage-encryption-walk-' . bin2hex(random_bytes(12));
        $resumed = $run->cursor !== null || $run->phase !== StorageFieldEncryptionRunRow::PHASE_VERSIONS;
        $taken = $this->database->transaction(function () use ($runRef, $workerRef): ?StorageFieldEncryptionRunRow {
            $current = $this->runs->find($runRef, true);
            if ($current === null || !$current->running()) {
                return null;
            }
            $taken = $current->taken($workerRef, self::now());
            $this->runs->put($taken);

            return $taken;
        });
        if (!$taken instanceof StorageFieldEncryptionRunRow) {
            $ended = $this->runs->find($runRef);

            return ['state' => $ended->state ?? StorageWorkbenchEncryptionRun::STATE_FAILED, 'rewritten' => $ended->rewritten ?? 0, 'skipped' => $ended->skipped ?? 0, 'resumed' => false];
        }

        try {
            $first = true;
            while (true) {
                if (!$first && $checkpoint !== null) {
                    $checkpoint();
                }
                $first = false;
                $phase = $this->database->transaction(fn (): string => $this->step($runRef, $workerRef, $chunk));
                if ($phase === StorageFieldEncryptionRunRow::PHASE_FINAL) {
                    break;
                }
            }
            // The safety net, outside the final transaction: rows written since the run was asked for.
            if ($checkpoint !== null) {
                $checkpoint();
            }
            $this->catchUp($runRef, $workerRef, $chunk, false);
            $done = $this->database->transaction(function () use ($runRef, $workerRef, $chunk, $finish): StorageFieldEncryptionRunRow {
                // Writers hold the schema row for share: once this is taken, every write has committed.
                $this->database->table(self::SCHEMAS)->where('schema_id', $this->current($runRef, $workerRef)->schemaId)->lockForUpdate()->first();
                $this->catchUp($runRef, $workerRef, $chunk, true);
                $current = $this->current($runRef, $workerRef);
                if ($current->mode === StorageWorkbenchEncryptionRun::MODE_ROTATE && $current->sourceKeyId !== null) {
                    if ($this->stillUsed($current->schemaId, $current->sourceKeyId)) {
                        throw new StorageRejected(self::REASON_KEY_STILL_USED);
                    }
                    $this->keys->dropRetiring($current->schemaId, $current->sourceKeyId);
                }
                $done = $current->ended(StorageWorkbenchEncryptionRun::STATE_DONE, null, self::now());
                $finish($done);
                $this->runs->put($done);

                return $done;
            });
        } catch (StorageConflict $exception) {
            throw $exception;
        } catch (StorageRejected $exception) {
            $this->fail($runRef, $workerRef, $exception->reasonCode);
            throw $exception;
        }

        return ['state' => $done->state, 'rewritten' => $done->rewritten, 'skipped' => $done->skipped, 'resumed' => $resumed];
    }

    /**
     * One chunk of the walk, in the caller's transaction. Returns the phase the run is in after it.
     */
    private function step(string $runRef, string $workerRef, int $chunk): string
    {
        $run = $this->current($runRef, $workerRef);
        $this->database->table(self::SCHEMAS)->where('schema_id', $run->schemaId)->sharedLock()->first();
        if ($run->phase === StorageFieldEncryptionRunRow::PHASE_VERSIONS) {
            [$count, $last, $rewritten] = $this->versionsChunk($run, $run->cursor, null, $chunk);
            $done = $count < $chunk;
            $this->runs->put($run->withProgress(
                $done ? StorageFieldEncryptionRunRow::PHASE_LOCALIZED : StorageFieldEncryptionRunRow::PHASE_VERSIONS,
                $done ? null : $last,
                $run->rewritten + $rewritten,
                $run->skipped + $count - $rewritten,
                self::now(),
            ));

            return $done ? StorageFieldEncryptionRunRow::PHASE_LOCALIZED : StorageFieldEncryptionRunRow::PHASE_VERSIONS;
        }
        if ($run->phase === StorageFieldEncryptionRunRow::PHASE_LOCALIZED) {
            [$count, $last, $rewritten] = $this->localizedChunk($run, $run->cursor === null ? 0 : (int) $run->cursor, null, $chunk);
            $done = $count < $chunk;
            $this->runs->put($run->withProgress(
                $done ? StorageFieldEncryptionRunRow::PHASE_FINAL : StorageFieldEncryptionRunRow::PHASE_LOCALIZED,
                $done ? null : (string) $last,
                $run->rewritten + $rewritten,
                $run->skipped + $count - $rewritten,
                self::now(),
            ));

            return $done ? StorageFieldEncryptionRunRow::PHASE_FINAL : StorageFieldEncryptionRunRow::PHASE_LOCALIZED;
        }

        return StorageFieldEncryptionRunRow::PHASE_FINAL;
    }

    /**
     * The rows written since the run was asked for, checked again: in chunks of their own transactions, or (inside
     * the final transaction) all of them in the caller's.
     */
    private function catchUp(string $runRef, string $workerRef, int $chunk, bool $inTransaction): void
    {
        $run = $this->runs->find($runRef);
        if ($run === null) {
            throw new StorageRejected('storage_field_encryption_run_unknown');
        }
        $since = gmdate('Y-m-d H:i:s', max(0, (int) strtotime($run->createdAt . ' UTC') - self::CATCH_UP_MARGIN_SECONDS));
        foreach ([true, false] as $versions) {
            $cursor = null;
            while (true) {
                $pass = function () use ($runRef, $workerRef, $versions, $cursor, $since, $chunk): array {
                    $current = $this->current($runRef, $workerRef);
                    [$count, $last, $rewritten] = $versions
                        ? $this->versionsChunk($current, $cursor, $since, $chunk)
                        : $this->localizedChunk($current, $cursor === null ? 0 : (int) $cursor, $since, $chunk);
                    if ($count > 0) {
                        $this->runs->put($current->withProgress(
                            $current->phase,
                            $current->cursor,
                            $current->rewritten + $rewritten,
                            $current->skipped,
                            self::now(),
                        ));
                    }

                    return [$count, $last];
                };
                [$count, $last] = $inTransaction ? $pass() : $this->database->transaction($pass);
                if ($count < $chunk) {
                    break;
                }
                $cursor = (string) $last;
            }
        }
    }

    /**
     * Rewrites one chunk of record versions after $cursor (`<record id>#<revision>`); with $since only those
     * written since then. Returns the rows read, the cursor of the last one and how many were rewritten.
     *
     * @return array{0: int, 1: ?string, 2: int}
     */
    private function versionsChunk(StorageFieldEncryptionRunRow $run, ?string $cursor, ?string $since, int $chunk): array
    {
        $select = function () use ($run, $cursor, $since, $chunk): Builder {
            $query = $this->database->table(self::VERSIONS)
                ->where('schema_id', $run->schemaId)
                ->orderBy('record_id')
                ->orderBy('revision')
                ->limit($chunk);
            if ($since !== null) {
                $query->where('created_at', '>=', $since);
            }
            if ($cursor !== null) {
                [$recordId, $revision] = self::cursor($cursor);
                $query->where(static function (Builder $after) use ($recordId, $revision): void {
                    $after->where('record_id', '>', $recordId)
                        ->orWhere(static function (Builder $same) use ($recordId, $revision): void {
                            $same->where('record_id', '=', $recordId)->where('revision', '>', $revision);
                        });
                });
            }

            return $query;
        };
        $keys = $select()->get(['record_id', 'revision'])->all();
        if ($keys === []) {
            return [0, $cursor, 0];
        }
        $recordIds = array_values(array_unique(array_map(static fn (stdClass $row): string => (string) $row->record_id, $keys)));
        sort($recordIds, SORT_STRING);
        // The writers' order: the heads first, then their versions.
        $heads = [];
        foreach ($this->database->table(self::RECORDS)
            ->where('schema_id', $run->schemaId)
            ->whereIn('record_id', $recordIds)
            ->orderBy('record_id')
            ->lockForUpdate()
            ->get(['record_id', 'current_revision', 'current_hash']) as $head) {
            $heads[(string) $head->record_id] = $head;
        }
        $rows = $select()->lockForUpdate()->get(['record_id', 'revision', 'values_json', 'content_hash'])->all();
        $rewritten = 0;
        $last = $cursor;
        foreach ($rows as $row) {
            $recordId = (string) $row->record_id;
            $revision = (int) $row->revision;
            $last = $recordId . '#' . $revision;
            $values = $this->decode((string) $row->values_json);
            $before = (string) $row->content_hash;
            if (!hash_equals($before, hash('sha256', $this->normalizer->canonicalJson($values)))) {
                throw new StorageRejected(self::REASON_CORRUPT);
            }
            $changed = false;
            foreach ($values as $fieldKey => $value) {
                $next = $this->transform($run, $recordId, null, (string) $fieldKey, $value);
                if ($next !== null) {
                    $values[$fieldKey] = $next[0];
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }
            $json = $this->normalizer->canonicalJson($values);
            $after = hash('sha256', $json);
            $updated = $this->database->table(self::VERSIONS)
                ->where('schema_id', $run->schemaId)
                ->where('record_id', $recordId)
                ->where('revision', $revision)
                ->where('content_hash', $before)
                ->update(['values_json' => $json, 'content_hash' => $after]);
            if ($updated !== 1) {
                throw new StorageConflict(self::REASON_INTERRUPTED);
            }
            $head = $heads[$recordId] ?? null;
            if ($head instanceof stdClass && (int) $head->current_revision === $revision) {
                if (!hash_equals((string) $head->current_hash, $before)) {
                    throw new StorageRejected(self::REASON_CORRUPT);
                }
                $this->database->table(self::RECORDS)
                    ->where('schema_id', $run->schemaId)
                    ->where('record_id', $recordId)
                    ->where('current_revision', $revision)
                    ->update(['current_hash' => $after]);
                $this->views?->recordRehashed($run->schemaId, $recordId, $revision, $after);
            }
            $rewritten++;
        }

        return [count($rows), $last, $rewritten];
    }

    /**
     * Rewrites one chunk of translations after row id $after; with $since only those written since then.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function localizedChunk(StorageFieldEncryptionRunRow $run, int $after, ?string $since, int $chunk): array
    {
        if (!$this->hasLocalized()) {
            return [0, $after, 0];
        }
        $query = $this->database->table(self::LOCALIZED)
            ->where('schema_id', $run->schemaId)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($chunk)
            ->lockForUpdate();
        if ($run->mode !== StorageWorkbenchEncryptionRun::MODE_ROTATE) {
            $query->whereIn('field_key', $run->fields === [] ? [''] : $run->fields);
        }
        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }
        $rows = $query->get(['id', 'record_id', 'locale', 'field_key', 'value_json'])->all();
        $rewritten = 0;
        $last = $after;
        foreach ($rows as $row) {
            $last = (int) $row->id;
            try {
                $value = json_decode((string) $row->value_json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new StorageRejected(self::REASON_CORRUPT);
            }
            $next = $this->transform($run, (string) $row->record_id, (string) $row->locale, (string) $row->field_key, $value);
            if ($next === null) {
                continue;
            }
            // Encoded as the translation writer encodes it; its hash is over that text.
            $encoded = json_encode($next[0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->database->table(self::LOCALIZED)->where('id', $last)->update([
                'value_json' => $encoded,
                'content_hash' => hash('sha256', $encoded),
            ]);
            $rewritten++;
        }

        return [count($rows), $last, $rewritten];
    }

    /**
     * The stored form of one value after the run, or null when it stays as it is.
     *
     * @return array{0: mixed}|null
     */
    private function transform(StorageFieldEncryptionRunRow $run, string $recordId, ?string $locale, string $fieldKey, #[\SensitiveParameter] mixed $value): ?array
    {
        $isEnvelope = StorageFieldEnvelope::isEnvelope($value);
        switch ($run->mode) {
            case StorageWorkbenchEncryptionRun::MODE_ENCRYPT:
                if ($value === null || $isEnvelope || !in_array($fieldKey, $run->fields, true)) {
                    return null;
                }

                return [$this->seal($run, $recordId, $locale, $fieldKey, $value)];
            case StorageWorkbenchEncryptionRun::MODE_DECRYPT:
                if (!$isEnvelope || !in_array($fieldKey, $run->fields, true)) {
                    return null;
                }

                return [$this->open($run->schemaId, $recordId, $locale, $fieldKey, $value)];
            default:
                if (!$isEnvelope || $run->sourceKeyId === null) {
                    return null;
                }
                $envelope = StorageFieldEnvelope::fromValue($value);
                if ($envelope->keyId !== $run->sourceKeyId) {
                    return null;
                }

                return [$this->seal($run, $recordId, $locale, $fieldKey, $this->open($run->schemaId, $recordId, $locale, $fieldKey, $value))];
        }
    }

    /** @return array{larena_enc: int, a: string, k: string, n: string, c: string} */
    private function seal(StorageFieldEncryptionRunRow $run, string $recordId, ?string $locale, string $fieldKey, #[\SensitiveParameter] mixed $value): array
    {
        $keyId = $run->targetKeyId;
        if ($keyId === null) {
            throw new StorageRejected(StorageDataKeys::REASON_KEY_UNAVAILABLE);
        }

        return $this->cipher->encrypt($this->keys->key($run->schemaId, $keyId), $keyId, $run->schemaId, $recordId, $locale, $fieldKey, $value)->toArray();
    }

    private function open(string $schemaId, string $recordId, ?string $locale, string $fieldKey, mixed $value): mixed
    {
        $envelope = StorageFieldEnvelope::fromValue($value);

        return $this->cipher->decrypt($this->keys->key($schemaId, $envelope->keyId), $envelope, $schemaId, $recordId, $locale, $fieldKey);
    }

    /** Whether a version or translation of the storage still names the key (its id is random: no other text has it). */
    private function stillUsed(string $schemaId, string $keyId): bool
    {
        if (preg_match(StorageFieldEnvelope::KEY_ID_PATTERN, $keyId) !== 1) {
            return true;
        }
        if ($this->database->table(self::VERSIONS)->where('schema_id', $schemaId)->where('values_json', 'like', '%' . $keyId . '%')->exists()) {
            return true;
        }

        return $this->hasLocalized()
            && $this->database->table(self::LOCALIZED)->where('schema_id', $schemaId)->where('value_json', 'like', '%' . $keyId . '%')->exists();
    }

    /** The run as this walk holds it, locked; another walk or a purge stops this one. */
    private function current(string $runRef, string $workerRef): StorageFieldEncryptionRunRow
    {
        $current = $this->runs->find($runRef, $this->database->transactionLevel() > 0);
        if ($current === null || !$current->running() || $current->workerRef !== $workerRef) {
            throw new StorageConflict(self::REASON_INTERRUPTED);
        }

        return $current;
    }

    private function fail(string $runRef, string $workerRef, string $reason): void
    {
        $this->database->transaction(function () use ($runRef, $workerRef, $reason): void {
            $current = $this->runs->find($runRef, true);
            if ($current !== null && $current->running() && $current->workerRef === $workerRef) {
                $this->runs->put($current->ended(StorageWorkbenchEncryptionRun::STATE_FAILED, self::reason($reason), self::now()));
            }
        });
    }

    private function hasLocalized(): bool
    {
        try {
            return !$this->database instanceof \Illuminate\Database\Connection || $this->database->getSchemaBuilder()->hasTable(self::LOCALIZED);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{0: string, 1: int} */
    private static function cursor(string $cursor): array
    {
        if (preg_match('/\A(record-[a-f0-9]{32})#([1-9][0-9]{0,18})\z/D', $cursor, $match) !== 1) {
            throw new StorageRejected('storage_field_encryption_run_corrupt');
        }

        return [$match[1], (int) $match[2]];
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StorageRejected(self::REASON_CORRUPT);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new StorageRejected(self::REASON_CORRUPT);
        }
        $values = [];
        foreach ($decoded as $key => $value) {
            $values[(string) $key] = $value;
        }

        return $values;
    }

    private static function reason(string $code): string
    {
        return preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $code) === 1 ? $code : 'storage_field_encryption_failed';
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
