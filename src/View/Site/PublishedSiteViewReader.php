<?php

declare(strict_types=1);

namespace Larena\Storage\View\Site;

use Closure;
use Illuminate\Database\Connection;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewFieldOptions;
use stdClass;
use Throwable;

/**
 * Reads the public projection from a ready site витрина slot, for DatabaseReadContracts.
 *
 * A slot answers only when, in one query read without the memo, its registration is
 * ready, the slot is ready, the registration's public fields hash equals the hash of
 * the storage's current public fields and their витрина options, and the slot was
 * built along the locale chain the caller resolves along. Anything else is null and the
 * caller takes the exact path; a reader with another fallback resolver (an exact-locale
 * reader) never matches a chain with fallbacks and stays on the exact path.
 *
 * Each row's entry is checked before it is used (its hash, its record, revision and
 * locale); the caller checks the page against the publication states, and reports a
 * mismatch here (`mismatch`): the slot is silenced, as every other silencing does, and
 * its rebuild is queued after the commit.
 *
 * A rebuild is queued, at most once per storage per QUEUE_SECONDS in this process and
 * never inside a transaction, when a ready registration's public fields changed under
 * it or a read found a mismatch. A pending slot is left to whoever silenced it and to
 * the scheduled `--missing` run: queueing a build from every read of a slot under
 * construction would only start competing runs.
 */
final class PublishedSiteViewReader
{
    /** How many rows one walk of a slot reads at a time. */
    public const PAGE = 500;
    /** A storage's rebuild is asked for at most once in this many seconds by one reader. */
    public const QUEUE_SECONDS = 60.0;
    /** After a failed state read (tables not migrated), the reader keeps to the exact path this long. */
    public const UNAVAILABLE_SECONDS = 60.0;

    private const ENTRY_KEYS = ['record_id', 'revision', 'locale', 'projection_version', 'values', 'value_sources'];
    private const HASH_MEMO_LIMIT = 64;

    private readonly PublishedSiteViewRegistry $registry;
    private ?bool $supported = null;
    private float $unavailableUntil = 0.0;
    private bool $withOptions = true;
    /** @var array<string, ?string> Public fields hashes by the definition and options they were computed from. */
    private array $hashes = [];
    /** @var array<string, float> When each storage's rebuild may be asked for again. */
    private array $queued = [];

    /**
     * @param (Closure(string): void)|null $rebuilds Queues the site rebuild of a storage.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?Closure $rebuilds = null,
        ?PublishedSiteViewRegistry $registry = null,
    ) {
        $this->registry = $registry ?? new PublishedSiteViewRegistry($connection, 0.0);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * The registration and slot that answer for a storage in a scope and locale along
     * this locale chain, or null.
     *
     * @return array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot}|null
     */
    public function source(string $schemaId, string $scopeRef, string $locale, string $localeChain): ?array
    {
        if (!$this->supported()) {
            return null;
        }
        $state = $this->state($schemaId, $scopeRef, $locale);
        if ($state === null) {
            return null;
        }
        $registration = $state['registration'];
        $slot = $state['slot'];
        if (!$registration->ready() || !$slot->answers($localeChain)) {
            return null;
        }
        $options = $this->withOptions
            ? StorageViewFieldOptions::fromFieldsJson($state['options_json'])
            : StorageViewFieldOptions::load($this->connection, $schemaId);
        if ($this->hash($state['definition'], $options) !== $registration->publicFieldsHash) {
            // Public fields changed under a ready registration (a change no hook saw): the next
            // write silences it, the rebuild makes it again.
            $this->queue($schemaId);

            return null;
        }

        return ['registration' => $registration, 'slot' => $slot];
    }

    /**
     * The entries of up to `$limit` rows of the slot after a record id, in record id
     * order; null when a row is corrupt.
     *
     * @param array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot} $source
     * @return list<array<string, mixed>>|null
     */
    public function page(array $source, int $limit, ?string $afterRecordId = null): ?array
    {
        $query = $this->rows($source)->orderBy('record_id')->limit($limit);
        if ($afterRecordId !== null) {
            $query->where('record_id', '>', $afterRecordId);
        }

        return $this->entries($source['slot'], $query->get()->all());
    }

    /**
     * The entry of one record in the slot: a list of none or one; null when its row is corrupt.
     *
     * @param array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot} $source
     * @return list<array<string, mixed>>|null
     */
    public function record(array $source, string $recordId): ?array
    {
        $row = $this->rows($source)->where('record_id', $recordId)->first();

        return $this->entries($source['slot'], $row === null ? [] : [$row]);
    }

    /** @param array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot} $source */
    public function count(array $source): int
    {
        return $this->connection->table($source['registration']->tableName)->where('slot_id', $source['slot']->id)->count();
    }

    /**
     * Up to `$limit` record ids of the slot after a record id, in order.
     *
     * @param array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot} $source
     * @return list<string>
     */
    public function recordIds(array $source, int $limit, ?string $afterRecordId = null): array
    {
        $query = $this->connection->table($source['registration']->tableName)
            ->where('slot_id', $source['slot']->id)->orderBy('record_id')->limit($limit);
        if ($afterRecordId !== null) {
            $query->where('record_id', '>', $afterRecordId);
        }

        return array_map(static fn (mixed $id): string => (string) $id, $query->pluck('record_id')->all());
    }

    /**
     * The entries of the slot whose value of `$keyField` is exactly `$keyValue`, in
     * record id order, at most `$stopAfter`; null when a row is corrupt. A string column
     * of the field narrows the rows in SQL (through its index when it has one; a
     * case-insensitive collation only widens what is then compared exactly); a field
     * without such a column is compared over the slot's entries page by page.
     *
     * @param array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot} $source
     * @return list<array<string, mixed>>|null
     */
    public function keyHolders(array $source, string $keyField, string $keyValue, int $stopAfter): ?array
    {
        $column = $this->keyColumn($source['registration'], $keyField);
        $holders = [];
        $after = null;
        do {
            $query = $this->rows($source)->orderBy('record_id')->limit(self::PAGE);
            if ($column !== null) {
                $query->where($column, $keyValue);
            }
            if ($after !== null) {
                $query->where('record_id', '>', $after);
            }
            $rows = $query->get()->all();
            $entries = $this->entries($source['slot'], $rows);
            if ($entries === null) {
                return null;
            }
            foreach ($entries as $entry) {
                if (is_array($entry['values']) && ($entry['values'][$keyField] ?? null) === $keyValue) {
                    $holders[] = $entry;
                    if (count($holders) >= $stopAfter) {
                        return $holders;
                    }
                }
            }
            $after = $entries === [] ? null : (string) $entries[array_key_last($entries)]['record_id'];
        } while (count($rows) === self::PAGE);

        return $holders;
    }

    /**
     * A read found the slot disagreeing with the publication states: outside a
     * transaction it stops answering until it is rebuilt, and the rebuild is queued.
     * Inside one the caller only takes the exact path (what the caller's own
     * transaction wrote may not have reached the slot yet).
     *
     * @param array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot} $source
     */
    public function mismatch(array $source): void
    {
        if ($this->connection->transactionLevel() > 0) {
            return;
        }
        try {
            $this->registry->silenceSlot($source['slot']);
        } catch (Throwable) {
            return;
        }
        $this->queue($source['slot']->schemaId);
    }

    /** The column a key lookup can narrow on: a ready column holding the field's string as written. */
    private function keyColumn(PublishedSiteViewRegistration $registration, string $keyField): ?string
    {
        foreach ($registration->layout->columns as $column) {
            if ($column->fieldKey !== $keyField || $column->column === null || $column->state !== StorageViewColumn::STATE_READY) {
                continue;
            }
            if ($column->isVarchar() || in_array($column->sqlType, [StorageViewColumn::SQL_TEXT, StorageViewColumn::SQL_REF, StorageViewColumn::SQL_USER_REF], true)) {
                return $column->column;
            }
        }

        return null;
    }

    /** @param array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot} $source */
    private function rows(array $source): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table($source['registration']->tableName)
            ->where('slot_id', $source['slot']->id)
            ->select(['record_id', 'revision', 'entry_hash', 'entry_json']);
    }

    /**
     * The checked entries of rows, in their order; null when any is corrupt.
     *
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>|null
     */
    private function entries(PublishedSiteViewSlot $slot, array $rows): ?array
    {
        $entries = [];
        foreach ($rows as $row) {
            $entry = $row instanceof stdClass ? $this->entry($slot, $row) : null;
            if ($entry === null) {
                return null;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * A row's entry exactly as it was written, or null when the row does not vouch for
     * it: its hash, keys, record, revision and locale must agree with the row and slot.
     *
     * @return array<string, mixed>|null
     */
    private function entry(PublishedSiteViewSlot $slot, stdClass $row): ?array
    {
        $json = $row->entry_json ?? null;
        if (!is_string($json) || !is_string($row->entry_hash ?? null) || !hash_equals((string) $row->entry_hash, hash('sha256', $json))) {
            return null;
        }
        try {
            $entry = PublishedSiteViewProjector::decodeEntry($json);
        } catch (Throwable) {
            return null;
        }
        if (array_keys($entry) !== self::ENTRY_KEYS
            || $entry['record_id'] !== (string) $row->record_id
            || !is_int($entry['revision']) || $entry['revision'] !== (int) $row->revision
            || $entry['locale'] !== $slot->locale
            || !is_int($entry['projection_version'])
            || !is_array($entry['values']) || !is_array($entry['value_sources'])) {
            return null;
        }

        return $entry;
    }

    /**
     * The registry state in one query; null when the storage has no slot there, or the
     * tables are not there (remembered for UNAVAILABLE_SECONDS).
     *
     * @return array{registration: PublishedSiteViewRegistration, slot: PublishedSiteViewSlot, definition: ?string, options_json: ?string}|null
     */
    private function state(string $schemaId, string $scopeRef, string $locale): ?array
    {
        if ($this->unavailableUntil > microtime(true)) {
            return null;
        }
        try {
            return $this->registry->readable($schemaId, $scopeRef, $locale, $this->withOptions);
        } catch (Throwable) {
        }
        if ($this->withOptions) {
            // Without the workbench tables the options are the fields' defaults, as `load` gives.
            try {
                $state = $this->registry->readable($schemaId, $scopeRef, $locale, false);
                $this->withOptions = false;

                return $state;
            } catch (Throwable) {
            }
        }
        $this->unavailableUntil = microtime(true) + self::UNAVAILABLE_SECONDS;

        return null;
    }

    private function hash(?string $definition, ?StorageViewFieldOptions $options): ?string
    {
        if ($definition === null) {
            return null;
        }
        $key = hash('sha256', $definition . "\0" . json_encode($options === null ? null : [$options->searchable, $options->indexed]));
        if (array_key_exists($key, $this->hashes)) {
            return $this->hashes[$key];
        }
        $fields = PublishedSiteViewIndex::definitionFields($definition);
        $hash = $fields === null ? null : PublishedSiteViewIndex::hashOf($fields, $options);
        if (count($this->hashes) >= self::HASH_MEMO_LIMIT) {
            $this->hashes = [];
        }

        return $this->hashes[$key] = $hash;
    }

    private function queue(string $schemaId): void
    {
        if ($this->rebuilds === null) {
            return;
        }
        $now = microtime(true);
        if (($this->queued[$schemaId] ?? 0.0) > $now) {
            return;
        }
        if ($this->connection->transactionLevel() > 0) {
            // After the commit, never inside it: a rolled-back read asks for nothing.
            try {
                $this->connection->afterCommit(fn () => $this->queue($schemaId));
            } catch (Throwable) {
            }

            return;
        }
        $this->queued[$schemaId] = $now + self::QUEUE_SECONDS;
        try {
            ($this->rebuilds)($schemaId);
        } catch (Throwable) {
        }
    }

    private function supported(): bool
    {
        return $this->supported ??= StorageViewDdl::for($this->connection) !== null;
    }
}
