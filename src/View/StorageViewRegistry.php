<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Exceptions\StorageRejected;
use stdClass;

/**
 * Reads and writes `larena_storage_views`. Rows are memoized for the request:
 * a write through this registry updates the memo, and an entry expires after a
 * short time, so a long-running worker sees changes other processes made.
 */
final class StorageViewRegistry
{
    public const TABLE = 'larena_storage_views';

    private ?bool $available = null;
    /** @var array<string, array{registration: ?StorageViewRegistration, at: float}> */
    private array $memo = [];
    /** @var array<string, true> Rows this registry changed inside a transaction that may still roll back. */
    private array $dirty = [];

    /** @param float $memoSeconds How long a row read is reused; 0 reads every time. */
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly float $memoSeconds = 1.0,
    ) {
    }

    public function memoSeconds(): float
    {
        return $this->memoSeconds;
    }

    /**
     * False until the registry table is migrated; nothing writes a витрина then.
     * Only a yes is remembered, so a process that migrates sees the table at once.
     */
    public function available(): bool
    {
        if ($this->available !== true) {
            $this->available = !$this->database instanceof Connection
                || $this->database->getSchemaBuilder()->hasTable(self::TABLE);
        }

        return $this->available;
    }

    /**
     * A fresh read inside a transaction is a locking (shared) read, so it sees the
     * latest committed row rather than the transaction's snapshot.
     */
    public function find(string $schemaId, bool $fresh = false): ?StorageViewRegistration
    {
        if (!$this->available()) {
            return null;
        }
        $memo = $this->memo[$schemaId] ?? null;
        if (!$fresh && $memo !== null && $this->memoSeconds > 0 && microtime(true) - $memo['at'] < $this->memoSeconds) {
            return $memo['registration'];
        }
        $query = $this->database->table(self::TABLE)->where('schema_id', $schemaId);
        if ($fresh && $this->database->transactionLevel() > 0) {
            $query->sharedLock();
        }

        return $this->load($schemaId, $query->first());
    }

    /** Reads the row as the caller's transaction sees it, without a lock and without the memo. */
    public function peek(string $schemaId): ?StorageViewRegistration
    {
        if (!$this->available()) {
            return null;
        }
        $row = $this->database->table(self::TABLE)->where('schema_id', $schemaId)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    /** Reads and locks the row for a change made in the caller's transaction. */
    public function findForUpdate(string $schemaId): ?StorageViewRegistration
    {
        if (!$this->available()) {
            return null;
        }

        return $this->load($schemaId, $this->database->table(self::TABLE)->where('schema_id', $schemaId)->lockForUpdate()->first());
    }

    private function load(string $schemaId, mixed $row): ?StorageViewRegistration
    {
        $registration = $row instanceof stdClass ? $this->hydrate($row) : null;
        if ($this->database->transactionLevel() === 0) {
            unset($this->dirty[$schemaId]);
        }
        if (!isset($this->dirty[$schemaId])) {
            $this->memo[$schemaId] = ['registration' => $registration, 'at' => microtime(true)];
        }

        return $registration;
    }

    /** @return list<StorageViewRegistration> */
    public function all(): array
    {
        if (!$this->available()) {
            return [];
        }
        $registrations = [];
        foreach ($this->database->table(self::TABLE)->orderBy('schema_id')->get() as $row) {
            $registrations[] = $this->hydrate($row);
        }

        return $registrations;
    }

    public function put(StorageViewRegistration $registration): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'table_name' => $registration->tableName,
            'layout_json' => $registration->layout->toJson(),
            'layout_hash' => $registration->layout->hash,
            'state' => $registration->state,
            'built_schema_version' => $registration->builtSchemaVersion,
            'build_cursor' => $registration->buildCursor,
            'build_run_ref' => $registration->buildRunRef,
            'updated_at' => $now,
        ];
        $updated = $this->database->table(self::TABLE)->where('schema_id', $registration->schemaId)->update($values);
        if ($updated === 0 && !$this->database->table(self::TABLE)->where('schema_id', $registration->schemaId)->exists()) {
            $this->database->table(self::TABLE)->insert(['schema_id' => $registration->schemaId] + $values + ['created_at' => $now]);
        }
        $this->remember($registration->schemaId, $registration);
    }

    public function delete(string $schemaId): void
    {
        if ($this->available()) {
            $this->database->table(self::TABLE)->where('schema_id', $schemaId)->delete();
        }
        $this->remember($schemaId, null);
    }

    public function forget(?string $schemaId = null): void
    {
        if ($schemaId === null) {
            $this->memo = [];
            $this->dirty = [];
            $this->available = null;

            return;
        }
        unset($this->memo[$schemaId]);
    }

    // A write inside a transaction may still roll back, so it is not remembered.
    private function remember(string $schemaId, ?StorageViewRegistration $registration): void
    {
        if ($this->database->transactionLevel() > 0) {
            unset($this->memo[$schemaId]);
            $this->dirty[$schemaId] = true;

            return;
        }
        $this->memo[$schemaId] = ['registration' => $registration, 'at' => microtime(true)];
    }

    private function hydrate(stdClass $row): StorageViewRegistration
    {
        $layout = StorageViewLayout::fromJson((string) $row->layout_json);
        if (!hash_equals((string) $row->layout_hash, $layout->hash)) {
            throw new StorageRejected('storage_view_layout_corrupt');
        }

        return new StorageViewRegistration(
            (string) $row->schema_id,
            (string) $row->table_name,
            $layout,
            (string) $row->state,
            $row->built_schema_version === null ? null : (int) $row->built_schema_version,
            $row->build_cursor === null ? null : (string) $row->build_cursor,
            $row->build_run_ref === null ? null : (string) $row->build_run_ref,
        );
    }
}
