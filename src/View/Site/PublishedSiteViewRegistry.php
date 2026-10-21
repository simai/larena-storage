<?php

declare(strict_types=1);

namespace Larena\Storage\View\Site;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\View\StorageViewLayout;
use Larena\Storage\View\StorageViewWriter;
use stdClass;

/**
 * Reads and writes the site витрина registry: `larena_storage_site_views` (one row
 * per published storage) and `larena_storage_site_view_slots` (one row per scope and
 * locale of it).
 *
 * Rows read without a lock are memoized for a short time, as the admin registry does
 * (a write through this registry updates the memo, a write inside a transaction that
 * may roll back is not remembered). Writers read fresh, with a lock.
 *
 * A slot is a row, never DDL: `slotFor()` inserts it the first time a record is
 * published in its scope and locale. Silencing a scope or a whole storage turns its
 * slots pending, in the caller's transaction: they stop answering until the rebuild
 * fills them again.
 */
final class PublishedSiteViewRegistry
{
    public const TABLE = 'larena_storage_site_views';
    public const SLOTS_TABLE = 'larena_storage_site_view_slots';

    private ?bool $available = null;
    /** @var array<string, array{registration: ?PublishedSiteViewRegistration, at: float}> */
    private array $memo = [];
    /** @var array<string, array{slot: ?PublishedSiteViewSlot, at: float}> */
    private array $slotMemo = [];
    private bool $dirty = false;
    private readonly StorageViewWriter $writer;

    /** @param float $memoSeconds How long a row read without a lock is reused; 0 reads every time. */
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly float $memoSeconds = 1.0,
    ) {
        $this->writer = new StorageViewWriter($database);
    }

    public function connection(): ConnectionInterface
    {
        return $this->database;
    }

    public function memoSeconds(): float
    {
        return $this->memoSeconds;
    }

    /**
     * False until both registry tables are migrated; nothing writes a site витрина
     * then. Only a yes is remembered.
     */
    public function available(): bool
    {
        if ($this->available !== true) {
            $this->available = !$this->database instanceof Connection
                || ($this->database->getSchemaBuilder()->hasTable(self::TABLE) && $this->database->getSchemaBuilder()->hasTable(self::SLOTS_TABLE));
        }

        return $this->available;
    }

    /** A fresh read inside a transaction is a locking (shared) read: the latest committed row. */
    public function find(string $schemaId, bool $fresh = false): ?PublishedSiteViewRegistration
    {
        if (!$this->available()) {
            return null;
        }
        $memo = $this->memo[$schemaId] ?? null;
        if (!$fresh && !$this->dirtyNow() && $memo !== null && $this->memoSeconds > 0 && microtime(true) - $memo['at'] < $this->memoSeconds) {
            return $memo['registration'];
        }
        $query = $this->database->table(self::TABLE)->where('schema_id', $schemaId);
        if ($fresh && $this->database->transactionLevel() > 0) {
            $query->sharedLock();
        }
        $registration = $this->hydrate($query->first());
        $this->remember($schemaId, $registration);

        return $registration;
    }

    /** Reads the row as the caller's transaction sees it, without a lock and without the memo. */
    public function peek(string $schemaId): ?PublishedSiteViewRegistration
    {
        if (!$this->available()) {
            return null;
        }

        return $this->hydrate($this->database->table(self::TABLE)->where('schema_id', $schemaId)->first());
    }

    /** Reads and locks the row for a change made in the caller's transaction. */
    public function findForUpdate(string $schemaId): ?PublishedSiteViewRegistration
    {
        if (!$this->available()) {
            return null;
        }

        return $this->hydrate($this->database->table(self::TABLE)->where('schema_id', $schemaId)->lockForUpdate()->first());
    }

    /** @return list<PublishedSiteViewRegistration> */
    public function all(): array
    {
        if (!$this->available()) {
            return [];
        }
        $registrations = [];
        foreach ($this->database->table(self::TABLE)->orderBy('schema_id')->get() as $row) {
            $registration = $this->hydrate($row);
            if ($registration !== null) {
                $registrations[] = $registration;
            }
        }

        return $registrations;
    }

    public function put(PublishedSiteViewRegistration $registration): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'table_name' => $registration->tableName,
            'layout_json' => $registration->layout->toJson(),
            'layout_hash' => $registration->layout->hash,
            'public_fields_hash' => $registration->publicFieldsHash,
            'state' => $registration->state,
            'built_schema_version' => $registration->builtSchemaVersion,
            'updated_at' => $now,
        ];
        $updated = $this->database->table(self::TABLE)->where('schema_id', $registration->schemaId)->update($values);
        if ($updated === 0 && !$this->database->table(self::TABLE)->where('schema_id', $registration->schemaId)->exists()) {
            $this->database->table(self::TABLE)->insert(['schema_id' => $registration->schemaId] + $values + ['created_at' => $now]);
        }
        $this->changed();
        $this->remember($registration->schemaId, $registration);
    }

    /**
     * Inserts the registration unless another process registered the storage first;
     * returns the row that is registered.
     */
    public function register(PublishedSiteViewRegistration $registration): ?PublishedSiteViewRegistration
    {
        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->database->transaction(function () use ($registration, $now): void {
                $this->database->table(self::TABLE)->insert([
                    'schema_id' => $registration->schemaId,
                    'table_name' => $registration->tableName,
                    'layout_json' => $registration->layout->toJson(),
                    'layout_hash' => $registration->layout->hash,
                    'public_fields_hash' => $registration->publicFieldsHash,
                    'state' => $registration->state,
                    'built_schema_version' => $registration->builtSchemaVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (QueryException $failure) {
            if (!self::isUniqueViolation($failure)) {
                throw $failure;
            }
        }
        $this->changed();

        return $this->find($registration->schemaId, true);
    }

    /** The storage's registration and every slot of it go (a structure purge, a storage without public fields). */
    public function delete(string $schemaId): void
    {
        if ($this->available()) {
            $this->database->table(self::SLOTS_TABLE)->where('schema_id', $schemaId)->delete();
            $this->database->table(self::TABLE)->where('schema_id', $schemaId)->delete();
        }
        $this->changed();
        $this->remember($schemaId, null);
    }

    /**
     * One slot. `$lock` is null (a memoized read unless `$fresh`), `shared` or
     * `update` (inside the caller's transaction).
     *
     * @param 'shared'|'update'|null $lock
     */
    public function slot(string $schemaId, string $scopeRef, string $locale, ?string $lock = null, bool $fresh = false): ?PublishedSiteViewSlot
    {
        if (!$this->available()) {
            return null;
        }
        $key = PublishedSiteViewSlot::keyOf($schemaId, $scopeRef, $locale);
        $memo = $this->slotMemo[$key] ?? null;
        if ($lock === null && !$fresh && !$this->dirtyNow() && $memo !== null && $this->memoSeconds > 0 && microtime(true) - $memo['at'] < $this->memoSeconds) {
            return $memo['slot'];
        }
        $query = $this->slotQuery($schemaId, $scopeRef, $locale);
        if ($lock === 'update') {
            $query->lockForUpdate();
        } elseif ($lock === 'shared' && $this->database->transactionLevel() > 0) {
            $query->sharedLock();
        }
        $slot = $this->hydrateSlot($query->first());
        if (!$this->dirtyNow()) {
            $this->slotMemo[$key] = ['slot' => $slot, 'at' => microtime(true)];
        }

        return $slot;
    }

    /**
     * The slot of a scope and locale, inserted `pending` when it has none: a new scope
     * or locale is a row, never DDL. Read with a shared lock when it exists; a slot
     * this call inserted is held by the insert. Returns the slot and whether it is new.
     *
     * @return array{0: PublishedSiteViewSlot, 1: bool}
     */
    public function slotFor(string $schemaId, string $scopeRef, string $locale): array
    {
        if (!$this->available()) {
            throw new StorageRejected('storage_site_view_unavailable');
        }
        $existing = $this->slotQuery($schemaId, $scopeRef, $locale)->first();
        if ($existing instanceof stdClass) {
            $slot = $this->slot($schemaId, $scopeRef, $locale, 'shared');
            if ($slot !== null) {
                return [$slot, false];
            }
        }
        $created = false;
        try {
            // A savepoint, so that a slot another publication inserted first leaves the transaction usable.
            $this->database->transaction(function () use ($schemaId, $scopeRef, $locale): void {
                $this->database->table(self::SLOTS_TABLE)->insert([
                    'schema_id' => $schemaId,
                    'scope_ref' => $scopeRef,
                    'locale' => $locale,
                    'locale_chain' => null,
                    'state' => PublishedSiteViewSlot::STATE_PENDING,
                    'build_cursor' => null,
                    'build_run_ref' => null,
                    'built_at' => null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            });
            $created = true;
        } catch (QueryException $failure) {
            if (!self::isUniqueViolation($failure)) {
                throw $failure;
            }
        }
        $this->changed();
        $slot = $this->slot($schemaId, $scopeRef, $locale, $created ? 'update' : 'shared');
        if ($slot === null) {
            throw new StorageRejected('storage_site_view_slot_missing');
        }

        return [$slot, $created];
    }

    /**
     * What a read needs to know whether a slot may answer, in one query and without
     * the memo: the registration, the slot, the storage's current schema definition
     * and, with `$withOptions`, the `fields_json` of its current workbench structure
     * version (the витрина options). Null when the storage has no registration or no
     * slot in this scope and locale. A missing table fails the query.
     *
     * @return array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot, definition: ?string, options_json: ?string}|null
     */
    public function readable(string $schemaId, string $scopeRef, string $locale, bool $withOptions = true): ?array
    {
        $query = $this->database->table(self::TABLE . ' as v')
            ->join(self::SLOTS_TABLE . ' as s', 's.schema_id', '=', 'v.schema_id')
            ->where('v.schema_id', $schemaId)
            ->where('s.scope_ref', $scopeRef)
            ->where('s.locale', $locale)
            ->select([
                'v.schema_id', 'v.table_name', 'v.layout_json', 'v.layout_hash', 'v.public_fields_hash', 'v.state as view_state', 'v.built_schema_version',
                's.id as slot_id', 's.scope_ref', 's.locale', 's.state as slot_state', 's.locale_chain', 's.build_cursor', 's.build_run_ref', 's.built_at',
            ])
            ->selectSub(fn (Builder $sub) => $sub->from('larena_storage_schema_versions')->select('definition')
                ->where('schema_id', $schemaId)->orderByDesc('version')->limit(1), 'schema_definition');
        if ($withOptions) {
            $query->selectSub(fn (Builder $sub) => $sub->from('larena_storage_workbench_structure_versions as wv')
                ->join('larena_storage_workbench_structures as ws', static function ($join): void {
                    $join->on('wv.scope_ref', '=', 'ws.scope_ref')
                        ->on('wv.structure_id', '=', 'ws.structure_id')
                        ->on('wv.version', '=', 'ws.current_version');
                })
                ->where('ws.storage_schema_id', $schemaId)
                ->select('wv.fields_json')
                ->limit(1), 'options_fields_json');
        }
        $row = $query->first();
        if (!$row instanceof stdClass) {
            return null;
        }
        $registration = $this->hydrate((object) [
            'schema_id' => $row->schema_id,
            'table_name' => $row->table_name,
            'layout_json' => $row->layout_json,
            'layout_hash' => $row->layout_hash,
            'public_fields_hash' => $row->public_fields_hash,
            'state' => $row->view_state,
            'built_schema_version' => $row->built_schema_version,
        ]);
        $slot = $this->hydrateSlot((object) [
            'id' => $row->slot_id,
            'schema_id' => $row->schema_id,
            'scope_ref' => $row->scope_ref,
            'locale' => $row->locale,
            'state' => $row->slot_state,
            'locale_chain' => $row->locale_chain,
            'build_cursor' => $row->build_cursor,
            'build_run_ref' => $row->build_run_ref,
            'built_at' => $row->built_at,
        ]);
        if ($registration === null || $slot === null) {
            return null;
        }

        return [
            'registration' => $registration,
            'slot' => $slot,
            'definition' => $row->schema_definition === null ? null : (string) $row->schema_definition,
            'options_json' => $withOptions && ($row->options_fields_json ?? null) !== null ? (string) $row->options_fields_json : null,
        ];
    }

    /** @return list<PublishedSiteViewSlot> Every slot of a storage, by scope and locale. */
    public function slots(string $schemaId): array
    {
        if (!$this->available()) {
            return [];
        }
        $slots = [];
        foreach ($this->database->table(self::SLOTS_TABLE)->where('schema_id', $schemaId)->orderBy('scope_ref')->orderBy('locale')->get() as $row) {
            $slot = $this->hydrateSlot($row);
            if ($slot !== null) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /** Reads one slot by its id, locked for a change in the caller's transaction. */
    public function slotForUpdate(int $id): ?PublishedSiteViewSlot
    {
        if (!$this->available()) {
            return null;
        }

        return $this->hydrateSlot($this->database->table(self::SLOTS_TABLE)->where('id', $id)->lockForUpdate()->first());
    }

    /** Reads one slot by its id as the caller's transaction sees it. */
    public function peekSlot(int $id): ?PublishedSiteViewSlot
    {
        if (!$this->available()) {
            return null;
        }

        return $this->hydrateSlot($this->database->table(self::SLOTS_TABLE)->where('id', $id)->first());
    }

    public function putSlot(PublishedSiteViewSlot $slot): void
    {
        $this->database->table(self::SLOTS_TABLE)->where('id', $slot->id)->update([
            'state' => $slot->state,
            'locale_chain' => $slot->localeChain,
            'build_cursor' => $slot->buildCursor,
            'build_run_ref' => $slot->buildRunRef,
            'built_at' => $slot->builtAt,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->changed();
    }

    /** A slot stops answering until it is rebuilt; a build under way stops at its next chunk. */
    public function silenceSlot(PublishedSiteViewSlot $slot): void
    {
        $this->silence($this->database->table(self::SLOTS_TABLE)->where('id', $slot->id));
    }

    /**
     * A publication written without the site index may change what any slot of this
     * storage and scope holds (in any locale a fallback chain may reach): they stop
     * answering until they are rebuilt.
     */
    public function silenceScope(string $schemaId, string $scopeRef): void
    {
        if (!$this->available()) {
            return;
        }
        $this->silence($this->database->table(self::SLOTS_TABLE)->where('schema_id', $schemaId)->where('scope_ref', $scopeRef));
    }

    /**
     * A translation written without the site index, or a change of the public fields:
     * every slot of the storage stops answering until it is rebuilt.
     */
    public function silenceSchema(string $schemaId): void
    {
        if (!$this->available()) {
            return;
        }
        $this->silence($this->database->table(self::SLOTS_TABLE)->where('schema_id', $schemaId));
    }

    /**
     * The slots where these records have a publication state stop answering (a change
     * of their rows nobody could follow, such as a history prune without the index).
     *
     * @param list<string> $recordIds
     */
    public function silenceRecords(string $schemaId, array $recordIds): void
    {
        if (!$this->available() || $recordIds === [] || !$this->hasStates()) {
            return;
        }
        $placements = [];
        foreach (array_chunk($recordIds, 500) as $chunk) {
            foreach ($this->database->table('larena_storage_publication_states')
                ->where('schema_id', $schemaId)->whereIn('record_id', $chunk)
                ->distinct()->get(['scope_ref', 'locale']) as $row) {
                $placements[(string) $row->scope_ref . "\0" . (string) $row->locale] = [(string) $row->scope_ref, (string) $row->locale];
            }
        }
        foreach ($placements as [$scopeRef, $locale]) {
            $this->silence($this->slotQuery($schemaId, $scopeRef, $locale));
        }
    }

    /**
     * Removes these records from every slot of the storage's site table, in the
     * caller's transaction (a purge, whatever path runs it).
     *
     * @param list<string> $recordIds
     */
    public function deleteRecords(string $schemaId, array $recordIds): void
    {
        if ($recordIds === [] || !$this->available()) {
            return;
        }
        $registration = $this->peek($schemaId);
        if ($registration === null || !$this->tableExists($registration->tableName)) {
            return;
        }
        $this->writer->deleteRecordsInAllSlots($registration->tableName, $recordIds, $registration->layout->searchTable());
    }

    public function forget(): void
    {
        $this->memo = [];
        $this->slotMemo = [];
        $this->dirty = false;
        $this->available = null;
    }

    private function silence(Builder $slots): void
    {
        $slots->where('state', '!=', PublishedSiteViewSlot::STATE_PENDING)->update([
            'state' => PublishedSiteViewSlot::STATE_PENDING,
            'build_cursor' => null,
            'build_run_ref' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->changed();
    }

    private function slotQuery(string $schemaId, string $scopeRef, string $locale): Builder
    {
        return $this->database->table(self::SLOTS_TABLE)
            ->where('schema_id', $schemaId)
            ->where('scope_ref', $scopeRef)
            ->where('locale', $locale);
    }

    private function tableExists(string $table): bool
    {
        return !$this->database instanceof Connection || $this->database->getSchemaBuilder()->hasTable($table);
    }

    private function hasStates(): bool
    {
        return !$this->database instanceof Connection || $this->database->getSchemaBuilder()->hasTable('larena_storage_publication_states');
    }

    /** Something changed inside a transaction that may still roll back: the memo is not trusted until it ends. */
    private function changed(): void
    {
        $this->slotMemo = [];
        if ($this->database->transactionLevel() > 0) {
            $this->dirty = true;
            $this->memo = [];
        }
    }

    private function dirtyNow(): bool
    {
        if ($this->dirty && $this->database->transactionLevel() === 0) {
            $this->dirty = false;
            $this->memo = [];
            $this->slotMemo = [];
        }

        return $this->dirty;
    }

    private function remember(string $schemaId, ?PublishedSiteViewRegistration $registration): void
    {
        if ($this->dirtyNow()) {
            unset($this->memo[$schemaId]);

            return;
        }
        $this->memo[$schemaId] = ['registration' => $registration, 'at' => microtime(true)];
    }

    private function hydrate(mixed $row): ?PublishedSiteViewRegistration
    {
        if (!$row instanceof stdClass) {
            return null;
        }
        $layout = StorageViewLayout::fromJson((string) $row->layout_json);
        if (!hash_equals((string) $row->layout_hash, $layout->hash)) {
            throw new StorageRejected('storage_site_view_layout_corrupt');
        }

        return new PublishedSiteViewRegistration(
            (string) $row->schema_id,
            (string) $row->table_name,
            $layout,
            (string) $row->public_fields_hash,
            (string) $row->state,
            $row->built_schema_version === null ? null : (int) $row->built_schema_version,
        );
    }

    private function hydrateSlot(mixed $row): ?PublishedSiteViewSlot
    {
        if (!$row instanceof stdClass) {
            return null;
        }

        return new PublishedSiteViewSlot(
            (int) $row->id,
            (string) $row->schema_id,
            (string) $row->scope_ref,
            (string) $row->locale,
            (string) $row->state,
            $row->locale_chain === null ? null : (string) $row->locale_chain,
            $row->build_cursor === null ? null : (string) $row->build_cursor,
            $row->build_run_ref === null ? null : (string) $row->build_run_ref,
            $row->built_at === null ? null : (string) $row->built_at,
        );
    }

    private static function isUniqueViolation(QueryException $failure): bool
    {
        $message = strtolower($failure->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate') || str_contains($message, '1062');
    }
}
