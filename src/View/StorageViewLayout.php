<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use JsonException;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The shape of one storage's витрина for one schema version: which field lives in
 * which column, and the next free column ordinal (ordinals are never reused, so a
 * removed field's column name never comes back with another meaning).
 *
 * A field has one written column, except while its column is replaced without a
 * schema change (a string that becomes indexed): the old column stays `ready`
 * and answers lists while the new one is `backfilling`, and the old one is
 * dropped once the new one is filled.
 *
 * The search: the fields whose values make the search text (sorted keys), and
 * whether every record's row in the search table holds the text of those fields
 * (`ready`) or the rebuild is still refilling it (`backfilling`). A layout stored
 * before the search was configurable has no search fields (null): its search
 * text is every string and text column, in the main table's `search_text`, and
 * lists no longer search it (a search waits until it is backfilled).
 */
final readonly class StorageViewLayout
{
    public const SEARCH_READY = 'ready';
    public const SEARCH_BACKFILLING = 'backfilling';
    public const SEARCH_STATES = [self::SEARCH_READY, self::SEARCH_BACKFILLING];

    /** @var list<StorageViewColumn> */
    public array $columns;
    /** @var list<string>|null */
    public ?array $searchFields;
    public string $hash;

    /**
     * @param list<StorageViewColumn> $columns
     * @param list<string>|null $searchFields
     */
    public function __construct(
        public int $schemaVersion,
        public int $nextOrdinal,
        array $columns,
        ?array $searchFields = null,
        public string $searchState = self::SEARCH_READY,
    ) {
        if ($schemaVersion < 1 || $nextOrdinal < 1 || !in_array($searchState, self::SEARCH_STATES, true)
            || ($searchFields === null && $searchState !== self::SEARCH_READY)) {
            throw new StorageRejected('storage_view_layout_invalid');
        }
        if ($searchFields !== null) {
            foreach ($searchFields as $key) {
                if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $key) !== 1) {
                    throw new StorageRejected('storage_view_layout_invalid');
                }
            }
            $searchFields = array_values(array_unique($searchFields));
            sort($searchFields, SORT_STRING);
        }
        usort($columns, static fn (StorageViewColumn $left, StorageViewColumn $right): int => [
            $left->column === null ? 1 : 0, $left->ordinal(), $left->fieldKey, $left->state,
        ] <=> [
            $right->column === null ? 1 : 0, $right->ordinal(), $right->fieldKey, $right->state,
        ]);
        $names = [];
        $ready = [];
        $incoming = [];
        foreach ($columns as $column) {
            if ($column->column !== null) {
                if (isset($names[$column->column]) || $column->ordinal() >= $nextOrdinal) {
                    throw new StorageRejected('storage_view_layout_invalid');
                }
                $names[$column->column] = true;
            }
            if ($column->state === StorageViewColumn::STATE_READY) {
                if (isset($ready[$column->fieldKey])) {
                    throw new StorageRejected('storage_view_layout_invalid');
                }
                $ready[$column->fieldKey] = true;
            } elseif ($column->state !== StorageViewColumn::STATE_DROPPING) {
                // One column on its way in (reserved or backfilling) per field.
                if (isset($incoming[$column->fieldKey])) {
                    throw new StorageRejected('storage_view_layout_invalid');
                }
                $incoming[$column->fieldKey] = true;
            }
        }
        $this->columns = $columns;
        $this->searchFields = $searchFields;
        $this->hash = hash('sha256', self::encode($this->toArray()));
    }

    /** @return list<StorageViewColumn> Columns and side fields every write sets. */
    public function written(): array
    {
        return array_values(array_filter($this->columns, static fn (StorageViewColumn $column): bool => $column->written()));
    }

    /** @return list<StorageViewColumn> */
    public function inState(string $state): array
    {
        return array_values(array_filter($this->columns, static fn (StorageViewColumn $column): bool => $column->state === $state));
    }

    /** @return list<StorageViewColumn> Typed columns that exist or should exist in the table. */
    public function physical(): array
    {
        return array_values(array_filter($this->columns, static fn (StorageViewColumn $column): bool => $column->column !== null));
    }

    public function column(string $name): ?StorageViewColumn
    {
        foreach ($this->columns as $column) {
            if ($column->column === $name) {
                return $column;
            }
        }

        return null;
    }

    /** @param list<StorageViewColumn> $columns */
    public function with(int $schemaVersion, int $nextOrdinal, array $columns): self
    {
        return new self($schemaVersion, $nextOrdinal, $columns, $this->searchFields, $this->searchState);
    }

    /**
     * Every row holds this layout: reserved and backfilling columns become ready (the
     * column they replace is dropped), and the search text is complete. For a table
     * every row of which is written under this layout (a new table, a build, a schema
     * apply that rewrites every record) or whose backfill reached the end.
     */
    public function promoted(): self
    {
        $incoming = [];
        foreach ($this->columns as $column) {
            if ($column->state === StorageViewColumn::STATE_ADDING || $column->state === StorageViewColumn::STATE_BACKFILLING) {
                $incoming[$column->fieldKey] = true;
            }
        }

        return new self($this->schemaVersion, $this->nextOrdinal, array_map(
            static fn (StorageViewColumn $column): StorageViewColumn => match (true) {
                $column->state === StorageViewColumn::STATE_ADDING, $column->state === StorageViewColumn::STATE_BACKFILLING
                    => $column->withState(StorageViewColumn::STATE_READY),
                $column->state === StorageViewColumn::STATE_READY && isset($incoming[$column->fieldKey])
                    => $column->withState(StorageViewColumn::STATE_DROPPING),
                default => $column,
            },
            $this->columns,
        ), $this->searchFields, self::SEARCH_READY);
    }

    /**
     * Reserved columns become backfilling: written from now on, while the rebuild
     * fills the rows written before. For a change that rewrites no record.
     */
    public function backfilling(): self
    {
        return new self($this->schemaVersion, $this->nextOrdinal, array_map(
            static fn (StorageViewColumn $column): StorageViewColumn => $column->state === StorageViewColumn::STATE_ADDING
                ? $column->withState(StorageViewColumn::STATE_BACKFILLING)
                : $column,
            $this->columns,
        ), $this->searchFields, $this->searchState);
    }

    /** True while rows written earlier still lack a column or their search text. */
    public function needsBackfill(): bool
    {
        return $this->searchState === self::SEARCH_BACKFILLING || $this->inState(StorageViewColumn::STATE_BACKFILLING) !== [];
    }

    /** Search answered from `search_text` when its rows hold exactly these fields' text. @param list<string> $fields */
    public function searchesExactly(array $fields): bool
    {
        sort($fields, SORT_STRING);

        return $this->searchFields !== null && $this->searchState === self::SEARCH_READY && $this->searchFields === array_values(array_unique($fields));
    }

    /** @param list<string>|null $fields */
    public function withSearch(?array $fields, string $state): self
    {
        return new self($this->schemaVersion, $this->nextOrdinal, $this->columns, $fields, $state);
    }

    /** Whether records have a row in the search table (every layout but one stored before it). */
    public function searchTable(): bool
    {
        return $this->searchFields !== null;
    }

    /** The layout once the dropped columns are gone from the table. */
    public function withoutDropped(): self
    {
        return new self($this->schemaVersion, $this->nextOrdinal, array_values(array_filter(
            $this->columns,
            static fn (StorageViewColumn $column): bool => $column->state !== StorageViewColumn::STATE_DROPPING,
        )), $this->searchFields, $this->searchState);
    }

    /**
     * This layout plus the columns another layout reserves (state `adding`) that
     * this one does not have yet. Writers keep writing this layout's columns; the
     * reserved ones only exist so the table can gain them ahead of a schema change.
     *
     * A reservation nothing uses any more (left by a change that failed or was
     * cancelled) gives way to the target's: it is dropping, and goes once settled.
     */
    public function reserving(self $target): self
    {
        $columns = $this->columns;
        $known = [];
        $incoming = [];
        $reserved = [];
        foreach ($this->columns as $position => $column) {
            $known[$column->column ?? ('side:' . $column->fieldKey . ':' . $column->state)] = true;
            if ($column->state === StorageViewColumn::STATE_ADDING || $column->state === StorageViewColumn::STATE_BACKFILLING) {
                $incoming[$column->fieldKey] = true;
            }
            if ($column->state === StorageViewColumn::STATE_ADDING && $column->column !== null) {
                $reserved[$column->fieldKey] = $position;
            }
        }
        foreach ($target->inState(StorageViewColumn::STATE_ADDING) as $column) {
            $key = $column->column ?? ('side:' . $column->fieldKey . ':' . $column->state);
            $stale = $reserved[$column->fieldKey] ?? null;
            if ($stale !== null && !isset($known[$key])
                && ($columns[$stale]->kind !== $column->kind || $columns[$stale]->sqlType !== $column->sqlType)) {
                $columns[$stale] = $columns[$stale]->withState(StorageViewColumn::STATE_DROPPING);
                unset($incoming[$column->fieldKey], $reserved[$column->fieldKey]);
            }
            // A field already taking a new column gets the next one with the change itself.
            if (!isset($known[$key]) && !isset($incoming[$column->fieldKey])) {
                $incoming[$column->fieldKey] = true;
                $columns[] = $column;
                $known[$key] = true;
            }
        }
        $columns = array_values($columns);

        return new self($this->schemaVersion, max($this->nextOrdinal, $target->nextOrdinal), $columns, $this->searchFields, $this->searchState);
    }

    /**
     * A layout without search fields keeps the shape it was stored with, so its hash
     * does not change.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'next_ordinal' => $this->nextOrdinal,
            'columns' => array_map(static fn (StorageViewColumn $column): array => $column->toArray(), $this->columns),
        ] + ($this->searchFields === null ? [] : ['search' => [
            'fields' => $this->searchFields,
            'state' => $this->searchState,
        ]]);
    }

    public function toJson(): string
    {
        return self::encode($this->toArray());
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StorageRejected('storage_view_layout_invalid');
        }
        if (!is_array($data) || !is_int($data['schema_version'] ?? null) || !is_int($data['next_ordinal'] ?? null)
            || !is_array($data['columns'] ?? null) || !array_is_list($data['columns'])
            || count($data) !== (array_key_exists('search', $data) ? 4 : 3)) {
            throw new StorageRejected('storage_view_layout_invalid');
        }
        $searchFields = null;
        $searchState = self::SEARCH_READY;
        if (array_key_exists('search', $data)) {
            $search = $data['search'];
            if (!is_array($search) || count($search) !== 2 || !is_array($search['fields'] ?? null) || !array_is_list($search['fields'])
                || !is_string($search['state'] ?? null)) {
                throw new StorageRejected('storage_view_layout_invalid');
            }
            $searchFields = [];
            foreach ($search['fields'] as $key) {
                if (!is_string($key)) {
                    throw new StorageRejected('storage_view_layout_invalid');
                }
                $searchFields[] = $key;
            }
            $searchState = $search['state'];
        }
        $columns = [];
        foreach ($data['columns'] as $column) {
            if (!is_array($column)) {
                throw new StorageRejected('storage_view_layout_invalid');
            }
            $columns[] = StorageViewColumn::fromArray($column);
        }

        return new self($data['schema_version'], $data['next_ordinal'], $columns, $searchFields, $searchState);
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
