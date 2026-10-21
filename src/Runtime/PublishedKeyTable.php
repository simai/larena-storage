<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

/**
 * The published-key table and the state of its sets.
 *
 * A set is one structure, scope, locale and key field. A row says that a record's
 * published revision shows this key in that locale, translations along the locale
 * chain included. The key is unique within its set by a database index, so a lookup
 * is one indexed read instead of a scan of every published record.
 *
 * Only a `ready` set answers, and only for the locale chain it was built along: a set
 * that is being built, that met two holders of one key, or whose chain has changed
 * since, says nothing, and the reader scans as it always did.
 */
final class PublishedKeyTable
{
    public const KEYS_TABLE = 'larena_storage_published_keys';

    public const SETS_TABLE = 'larena_storage_published_key_sets';

    public const STATE_BUILDING = 'building';

    public const STATE_READY = 'ready';

    public const STATE_CONFLICTED = 'conflicted';

    private bool $available = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Whether both tables are migrated. Only a positive answer is remembered. */
    public function available(): bool
    {
        if (!$this->available) {
            $schema = $this->connection->getSchemaBuilder();
            $this->available = $schema->hasTable(self::KEYS_TABLE) && $schema->hasTable(self::SETS_TABLE);
        }

        return $this->available;
    }

    public static function keyHash(string $keyValue): string
    {
        return hash('sha256', $keyValue);
    }

    /**
     * The holders of a key in a ready set built along this locale chain, or null when
     * the set cannot answer.
     *
     * @return list<array{record_id: string, revision: int}>|null
     * @phpstan-impure
     */
    public function holders(string $schemaId, string $scopeRef, string $locale, string $keyField, string $keyValue, string $chainKey): ?array
    {
        if (!$this->available()) {
            return null;
        }

        $set = $this->set($schemaId, $scopeRef, $locale, $keyField);
        if ($set === null || $set['state'] !== self::STATE_READY || $set['locale_chain'] !== $chainKey) {
            return null;
        }

        $holder = $this->holder($schemaId, $scopeRef, $locale, $keyField, $keyValue);
        if ($holder === null) {
            return [];
        }

        return $holder['record_id'] === '' ? null : [['record_id' => $holder['record_id'], 'revision' => $holder['revision']]];
    }

    /**
     * @return array{state: string, locale_chain: string, build_cursor: ?string, conflicts: int}|null
     * @phpstan-impure
     */
    public function set(string $schemaId, string $scopeRef, string $locale, string $keyField): ?array
    {
        $row = $this->setQuery($schemaId, $scopeRef, $locale, $keyField)->first();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;

        return [
            'state' => (string) $row['state'],
            'locale_chain' => (string) $row['locale_chain'],
            'build_cursor' => $row['build_cursor'] === null ? null : (string) $row['build_cursor'],
            'conflicts' => (int) $row['conflicts'],
        ];
    }

    /**
     * The key fields that have a set for a structure, scope and locale.
     *
     * @return list<string>
     * @phpstan-impure
     */
    public function setFields(string $schemaId, string $scopeRef, string $locale): array
    {
        $fields = [];
        foreach ($this->connection->table(self::SETS_TABLE)
            ->where('schema_id', $schemaId)
            ->where('scope_ref', $scopeRef)
            ->where('locale', $locale)
            ->orderBy('key_field')
            ->pluck('key_field') as $field) {
            $fields[] = (string) $field;
        }

        return $fields;
    }

    /**
     * Every set, for the rebuild to visit sets whose records are no longer published.
     *
     * @return list<array{schema_id: string, scope_ref: string, locale: string, key_field: string}>
     * @phpstan-impure
     */
    public function sets(): array
    {
        $sets = [];
        foreach ($this->connection->table(self::SETS_TABLE)
            ->orderBy('schema_id')->orderBy('scope_ref')->orderBy('locale')->orderBy('key_field')
            ->get(['schema_id', 'scope_ref', 'locale', 'key_field']) as $row) {
            $row = (array) $row;
            $sets[] = [
                'schema_id' => (string) $row['schema_id'],
                'scope_ref' => (string) $row['scope_ref'],
                'locale' => (string) $row['locale'],
                'key_field' => (string) $row['key_field'],
            ];
        }

        return $sets;
    }

    /** Starts a set from nothing: its rows go, and it is `building` along this chain. */
    public function startSet(string $schemaId, string $scopeRef, string $locale, string $keyField, string $chainKey): void
    {
        $this->rowsQuery($schemaId, $scopeRef, $locale, $keyField)->delete();
        $this->setQuery($schemaId, $scopeRef, $locale, $keyField)->delete();
        $this->connection->table(self::SETS_TABLE)->insert([
            'schema_id' => $schemaId,
            'scope_ref' => $scopeRef,
            'locale' => $locale,
            'key_field' => $keyField,
            'locale_chain' => $chainKey,
            'state' => self::STATE_BUILDING,
            'build_cursor' => null,
            'conflicts' => 0,
            'built_at' => null,
            'updated_at' => $this->now(),
        ]);
    }

    /** Records how far a build got, so that the next run resumes after it, and the conflicts it met. */
    public function advanceSet(string $schemaId, string $scopeRef, string $locale, string $keyField, ?string $cursor, int $newConflicts): void
    {
        $this->setQuery($schemaId, $scopeRef, $locale, $keyField)->increment('conflicts', max(0, $newConflicts), [
            'build_cursor' => $cursor,
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * Ends a build: ready, or conflicted when two published records showed one key,
     * counting what the maintenance met while the build ran.
     *
     * @return array{state: string, conflicts: int}
     * @phpstan-impure
     */
    public function finishSet(string $schemaId, string $scopeRef, string $locale, string $keyField): array
    {
        $conflicts = $this->set($schemaId, $scopeRef, $locale, $keyField)['conflicts'] ?? 0;
        $state = $conflicts > 0 ? self::STATE_CONFLICTED : self::STATE_READY;
        $now = $this->now();
        $this->setQuery($schemaId, $scopeRef, $locale, $keyField)->update([
            'state' => $state,
            'build_cursor' => null,
            'built_at' => $now,
            'updated_at' => $now,
        ]);

        return ['state' => $state, 'conflicts' => $conflicts];
    }

    /** A set that met two holders of one key stops answering until it is rebuilt. */
    public function markConflicted(string $schemaId, string $scopeRef, string $locale, string $keyField): void
    {
        $this->setQuery($schemaId, $scopeRef, $locale, $keyField)
            ->where('state', '!=', self::STATE_BUILDING)
            ->update(['state' => self::STATE_CONFLICTED, 'updated_at' => $this->now()]);
        // A build under way counts it, so that it finishes conflicted.
        $this->setQuery($schemaId, $scopeRef, $locale, $keyField)
            ->where('state', self::STATE_BUILDING)
            ->increment('conflicts');
    }

    /**
     * A publication written without the index (a lifecycle built without it) may hold
     * a key the index does not know: every set of the storage and scope stops
     * answering, in every locale a fallback chain may reach, until it is rebuilt.
     */
    public function silenceScope(string $schemaId, string $scopeRef): void
    {
        if (!$this->available()) {
            return;
        }
        $sets = $this->connection->table(self::SETS_TABLE)->where('schema_id', $schemaId)->where('scope_ref', $scopeRef);
        (clone $sets)->where('state', '!=', self::STATE_BUILDING)
            ->update(['state' => self::STATE_CONFLICTED, 'updated_at' => $this->now()]);
        (clone $sets)->where('state', self::STATE_BUILDING)->increment('conflicts');
    }

    /**
     * A translation written without the index may move a key the index does not know,
     * in any scope and any locale a fallback chain may reach: every set of the storage
     * stops answering until it is rebuilt.
     */
    public function silenceSchema(string $schemaId): void
    {
        if (!$this->available()) {
            return;
        }
        $sets = $this->connection->table(self::SETS_TABLE)->where('schema_id', $schemaId);
        (clone $sets)->where('state', '!=', self::STATE_BUILDING)->where('state', '!=', self::STATE_CONFLICTED)
            ->update(['state' => self::STATE_CONFLICTED, 'updated_at' => $this->now()]);
        (clone $sets)->where('state', self::STATE_BUILDING)->increment('conflicts');
    }

    /** Removes a set and its rows. */
    public function dropSet(string $schemaId, string $scopeRef, string $locale, string $keyField): void
    {
        $this->rowsQuery($schemaId, $scopeRef, $locale, $keyField)->delete();
        $this->setQuery($schemaId, $scopeRef, $locale, $keyField)->delete();
    }

    /**
     * The row that holds a key in a set, if any.
     *
     * @return array{id: int, record_id: string, revision: int}|null
     * @phpstan-impure
     */
    public function holder(string $schemaId, string $scopeRef, string $locale, string $keyField, string $keyValue): ?array
    {
        $row = $this->rowsQuery($schemaId, $scopeRef, $locale, $keyField)
            ->where('key_hash', self::keyHash($keyValue))
            ->first(['id', 'record_id', 'revision', 'key_value']);
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        if ((string) $row['key_value'] !== $keyValue) {
            // Two keys with one hash: the index cannot tell them apart, so it names
            // no holder (an empty record id) and claims nothing.
            return ['id' => (int) $row['id'], 'record_id' => '', 'revision' => 0];
        }

        return ['id' => (int) $row['id'], 'record_id' => (string) $row['record_id'], 'revision' => (int) $row['revision']];
    }

    /**
     * Adds a record's key to a set. False when another row holds it: the unique index
     * refused, which a concurrent publish can cause after every check passed.
     */
    public function insert(string $schemaId, string $scopeRef, string $locale, string $keyField, string $keyValue, string $recordId, int $revision): bool
    {
        try {
            // A savepoint, so that a refused insert leaves the surrounding
            // transaction usable.
            $this->connection->transaction(function () use ($schemaId, $scopeRef, $locale, $keyField, $keyValue, $recordId, $revision): void {
                $this->connection->table(self::KEYS_TABLE)->insert([
                    'schema_id' => $schemaId,
                    'scope_ref' => $scopeRef,
                    'locale' => $locale,
                    'key_field' => $keyField,
                    'key_hash' => self::keyHash($keyValue),
                    'key_value' => $keyValue,
                    'record_id' => $recordId,
                    'revision' => $revision,
                    'updated_at' => $this->now(),
                ]);
            });
        } catch (QueryException $failure) {
            if (self::isUniqueViolation($failure)) {
                return false;
            }

            throw $failure;
        }

        return true;
    }

    public function deleteRow(int $id): void
    {
        $this->connection->table(self::KEYS_TABLE)->where('id', $id)->delete();
    }

    /** Removes a record's keys in a scope and locale, under every key field. */
    public function forgetRecord(string $schemaId, string $scopeRef, string $locale, string $recordId): void
    {
        $this->connection->table(self::KEYS_TABLE)
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('scope_ref', $scopeRef)
            ->where('locale', $locale)
            ->delete();
    }

    private function setQuery(string $schemaId, string $scopeRef, string $locale, string $keyField): Builder
    {
        return $this->connection->table(self::SETS_TABLE)
            ->where('schema_id', $schemaId)
            ->where('scope_ref', $scopeRef)
            ->where('locale', $locale)
            ->where('key_field', $keyField);
    }

    private function rowsQuery(string $schemaId, string $scopeRef, string $locale, string $keyField): Builder
    {
        return $this->connection->table(self::KEYS_TABLE)
            ->where('schema_id', $schemaId)
            ->where('scope_ref', $scopeRef)
            ->where('locale', $locale)
            ->where('key_field', $keyField);
    }

    private static function isUniqueViolation(QueryException $failure): bool
    {
        $message = strtolower($failure->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, '1062');
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
