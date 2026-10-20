<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use Larena\Storage\Contracts\LocalizedValueObserver;
use Larena\Storage\Contracts\PublicationIndex;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Contracts\PublishedKeyPolicy;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Exceptions\PublicationRejected;

/**
 * Keeps the published-key table in step with publication.
 *
 * Every transition rewrites the record's row in its own transaction: a published
 * head gets the key its revision shows in that locale, anything else loses its row.
 * A translation written to a published revision rewrites the row of every locale
 * whose chain reaches it. Which field is the key is the application's word, through
 * `PublishedKeyPolicy`.
 *
 * A set becomes `ready` when it holds every published record: at once when the
 * scope and locale have at most a page of them, otherwise through `build`, which
 * the `larena:storage:published-keys:rebuild` command runs in resumable chunks. A
 * change of the locale fallback leaves the sets built along the old chain silent
 * until that command rebuilds them.
 *
 * A publication that bypasses this index — a lifecycle built without it — is not
 * seen until the rebuild; the composed application builds its lifecycle with it.
 */
final class PublishedKeyIndex implements PublicationIndex, LocalizedValueObserver
{
    /** A set with at most this many other published records is built inline, inside the first publish. */
    public const INLINE_BUILD_LIMIT = 500;

    public const DEFAULT_CHUNK = 500;

    public const MAX_CHUNK = 5000;

    private readonly PublishedKeyTable $table;

    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseReadContracts $readContracts,
        private readonly PublishedKeyPolicy $keys,
    ) {
        $this->table = new PublishedKeyTable($connection);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /** @phpstan-impure */
    public function publicationWritten(PublicationState $state): void
    {
        if (!$this->table->available()) {
            return;
        }

        $this->table->forgetRecord($state->schemaId, $state->scopeRef, $state->locale, $state->recordId);
        if (!$state->isPublished() || $state->publishedRevision === null) {
            return;
        }

        $this->place($state->schemaId, $state->scopeRef, $state->locale, $state->recordId, $state->publishedRevision, true);
    }

    /** @phpstan-impure */
    public function localizedValuesWritten(string $schemaId, string $recordId, int $revision, string $locale): void
    {
        if (!$this->table->available()) {
            return;
        }

        foreach ($this->readContracts->publishedPlacements($schemaId, $recordId, $revision) as $placement) {
            if (!in_array($locale, $this->readContracts->localeChain($placement['locale'])->locales, true)) {
                continue;
            }
            $this->table->forgetRecord($schemaId, $placement['scope_ref'], $placement['locale'], $recordId);
            // A translation is not refused for its key: a duplicate it causes stops
            // the set from answering, and a lookup reports it as ambiguous.
            $this->place($schemaId, $placement['scope_ref'], $placement['locale'], $recordId, $revision, false);
        }
    }

    /**
     * Builds one set from the published records, chunk by chunk, each chunk in its
     * own transaction. A ready set built along the current chain is left alone
     * unless `$fresh`; a build under way resumes after its cursor.
     *
     * @param (callable(string): void)|null $progress
     * @return array{state: string, key_field: ?string, records: int, conflicts: int, resumed: bool, skipped: bool}
     * @phpstan-impure
     */
    public function build(string $schemaId, string $scopeRef, string $locale, int $chunk = self::DEFAULT_CHUNK, bool $fresh = false, ?callable $progress = null): array
    {
        if ($chunk < 1 || $chunk > self::MAX_CHUNK) {
            throw new InvalidArgumentException('A published-key chunk must be from 1 to ' . self::MAX_CHUNK . '.');
        }
        if (!$this->table->available()) {
            throw new InvalidArgumentException('The published-key tables are not migrated.');
        }

        $keyField = $this->keys->keyFieldFor($schemaId, $scopeRef);
        // Sets of a field that is no longer the key go with their rows.
        foreach ($this->table->setFields($schemaId, $scopeRef, $locale) as $field) {
            if ($field !== $keyField) {
                $this->connection->transaction(fn () => $this->table->dropSet($schemaId, $scopeRef, $locale, $field));
            }
        }
        if ($keyField === null) {
            return ['state' => 'none', 'key_field' => null, 'records' => 0, 'conflicts' => 0, 'resumed' => false, 'skipped' => false];
        }

        $chainKey = $this->readContracts->localeChainKey($locale);
        $set = $this->table->set($schemaId, $scopeRef, $locale, $keyField);
        if (!$fresh && $set !== null && $set['state'] === PublishedKeyTable::STATE_READY && $set['locale_chain'] === $chainKey) {
            return ['state' => $set['state'], 'key_field' => $keyField, 'records' => 0, 'conflicts' => 0, 'resumed' => false, 'skipped' => true];
        }

        $resumed = !$fresh && $set !== null && $set['state'] === PublishedKeyTable::STATE_BUILDING && $set['locale_chain'] === $chainKey;
        if (!$resumed) {
            $this->connection->transaction(fn () => $this->table->startSet($schemaId, $scopeRef, $locale, $keyField, $chainKey));
        }
        $cursor = $resumed ? $set['build_cursor'] : null;
        $records = 0;

        do {
            $count = 0;
            $this->connection->transaction(function () use ($schemaId, $scopeRef, $locale, $keyField, $chunk, &$cursor, &$records, &$count): void {
                $heads = $this->readContracts->publishedHeadPage($schemaId, $scopeRef, $locale, $chunk, $cursor);
                $count = count($heads);
                $conflicts = $this->placeHeads($schemaId, $scopeRef, $locale, $keyField, $heads);
                $records += $count;
                $cursor = $heads === [] ? $cursor : $heads[array_key_last($heads)]['record_id'];
                $this->table->advanceSet($schemaId, $scopeRef, $locale, $keyField, $cursor, $conflicts);
            });
            if ($progress !== null) {
                $progress(sprintf('%s %s/%s: %d records', $schemaId, $scopeRef, $locale, $records));
            }
        } while ($count === $chunk);

        $finished = $this->connection->transaction(fn (): array => $this->table->finishSet($schemaId, $scopeRef, $locale, $keyField));

        return ['state' => $finished['state'], 'key_field' => $keyField, 'records' => $records, 'conflicts' => $finished['conflicts'], 'resumed' => $resumed, 'skipped' => false];
    }

    /**
     * Every structure, scope and locale that has a published record or a set: what a
     * full rebuild visits.
     *
     * @return list<array{schema_id: string, scope_ref: string, locale: string}>
     * @phpstan-impure
     */
    public function targets(?string $schemaId = null, ?string $scopeRef = null, ?string $locale = null): array
    {
        $targets = [];
        $query = $this->connection->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->select(['schema_id', 'scope_ref', 'locale'])
            ->where('state', PublicationStateValue::Published->value)
            ->whereNotNull('published_revision')
            ->distinct();
        foreach (['schema_id' => $schemaId, 'scope_ref' => $scopeRef, 'locale' => $locale] as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }
        foreach ($query->get() as $row) {
            $row = (array) $row;
            $target = ['schema_id' => (string) $row['schema_id'], 'scope_ref' => (string) $row['scope_ref'], 'locale' => (string) $row['locale']];
            $targets[implode("\0", $target)] = $target;
        }
        if ($this->table->available()) {
            foreach ($this->table->sets() as $set) {
                if (($schemaId !== null && $set['schema_id'] !== $schemaId)
                    || ($scopeRef !== null && $set['scope_ref'] !== $scopeRef)
                    || ($locale !== null && $set['locale'] !== $locale)) {
                    continue;
                }
                $target = ['schema_id' => $set['schema_id'], 'scope_ref' => $set['scope_ref'], 'locale' => $set['locale']];
                $targets[implode("\0", $target)] = $target;
            }
        }
        ksort($targets);

        return array_values($targets);
    }

    public function available(): bool
    {
        return $this->table->available();
    }

    /**
     * Writes one record's key into its set, building the set first when it is new
     * and small. A conflict on a publish whose key a reader can see is refused; any
     * other conflict silences the set.
     *
     * @phpstan-impure
     */
    private function place(string $schemaId, string $scopeRef, string $locale, string $recordId, int $revision, bool $refuse): void
    {
        $keyField = $this->keys->keyFieldFor($schemaId, $scopeRef);
        if ($keyField === null) {
            return;
        }
        if ($this->table->set($schemaId, $scopeRef, $locale, $keyField) === null && !$this->buildInline($schemaId, $scopeRef, $locale, $keyField, $recordId)) {
            // Too many published records to build here; the rebuild command does it.
            return;
        }

        $keyValue = $this->readContracts->keyValuesAt($schemaId, [['record_id' => $recordId, 'revision' => $revision]], $locale, $keyField)[$recordId] ?? '';
        if ($keyValue === '') {
            return;
        }

        $holder = $this->claim($schemaId, $scopeRef, $locale, $keyField, $keyValue, $recordId, $revision);
        if ($holder === null) {
            return;
        }

        // The guard reads the public key before the transition; a holder found here
        // means another publish of the same key committed in between.
        $visible = $this->readContracts->publicValuesAt($schemaId, $recordId, $revision, $locale)[$keyField] ?? null;
        if ($refuse && $visible === $keyValue) {
            throw new PublicationRejected(
                'key_conflict',
                $keyField . '=' . $keyValue . ' is already published in ' . $scopeRef . '/' . $locale . ' by record ' . ($holder === '' ? 'another record' : $holder) . '.',
            );
        }
        $this->table->markConflicted($schemaId, $scopeRef, $locale, $keyField);
    }

    /**
     * Builds a new set inside the current transaction when its scope and locale hold
     * at most a page of other published records. False when there are more.
     *
     * @phpstan-impure
     */
    private function buildInline(string $schemaId, string $scopeRef, string $locale, string $keyField, string $recordId): bool
    {
        $heads = $this->readContracts->publishedHeadPage($schemaId, $scopeRef, $locale, self::INLINE_BUILD_LIMIT + 2);
        $others = array_values(array_filter($heads, static fn (array $head): bool => $head['record_id'] !== $recordId));
        if (count($others) > self::INLINE_BUILD_LIMIT) {
            return false;
        }

        $this->table->startSet($schemaId, $scopeRef, $locale, $keyField, $this->readContracts->localeChainKey($locale));
        $this->table->advanceSet($schemaId, $scopeRef, $locale, $keyField, null, $this->placeHeads($schemaId, $scopeRef, $locale, $keyField, $others));
        $this->table->finishSet($schemaId, $scopeRef, $locale, $keyField);

        return true;
    }

    /**
     * Writes the keys of a page of published heads: two reads for the values, then
     * one indexed claim per key. Returns how many met another holder.
     *
     * @param list<array{record_id: string, revision: int}> $heads
     * @phpstan-impure
     */
    private function placeHeads(string $schemaId, string $scopeRef, string $locale, string $keyField, array $heads): int
    {
        if ($heads === []) {
            return 0;
        }

        $values = $this->readContracts->keyValuesAt($schemaId, $heads, $locale, $keyField);
        $conflicts = 0;
        foreach ($heads as $head) {
            $this->table->forgetRecord($schemaId, $scopeRef, $locale, $head['record_id']);
            $keyValue = $values[$head['record_id']] ?? '';
            if ($keyValue === '') {
                continue;
            }
            if ($this->claim($schemaId, $scopeRef, $locale, $keyField, $keyValue, $head['record_id'], $head['revision']) !== null) {
                ++$conflicts;
            }
        }

        return $conflicts;
    }

    /**
     * Takes a key for a record. Null when it was taken; otherwise the id of the
     * record that still holds it ('' when it cannot be named). A row whose record is
     * no longer published at that revision is stale and gives way.
     *
     * @phpstan-impure
     */
    private function claim(string $schemaId, string $scopeRef, string $locale, string $keyField, string $keyValue, string $recordId, int $revision): ?string
    {
        $holder = $this->table->holder($schemaId, $scopeRef, $locale, $keyField, $keyValue);
        if ($holder !== null && $holder['record_id'] !== $recordId) {
            if ($holder['record_id'] === '') {
                return '';
            }
            $published = $this->readContracts->publishedRevisions($schemaId, $scopeRef, $locale, [$holder['record_id']]);
            if (($published[$holder['record_id']] ?? null) === $holder['revision']) {
                return $holder['record_id'];
            }
            $this->table->deleteRow($holder['id']);
        }

        return $this->table->insert($schemaId, $scopeRef, $locale, $keyField, $keyValue, $recordId, $revision) ? null : '';
    }
}
