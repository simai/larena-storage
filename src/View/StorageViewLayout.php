<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use JsonException;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The shape of one storage's витрина for one schema version: which field lives in
 * which column, and the next free column ordinal (ordinals are never reused, so a
 * removed field's column name never comes back with another meaning).
 */
final readonly class StorageViewLayout
{
    /** @var list<StorageViewColumn> */
    public array $columns;
    public string $hash;

    /** @param list<StorageViewColumn> $columns */
    public function __construct(
        public int $schemaVersion,
        public int $nextOrdinal,
        array $columns,
    ) {
        if ($schemaVersion < 1 || $nextOrdinal < 1) {
            throw new StorageRejected('storage_view_layout_invalid');
        }
        usort($columns, static fn (StorageViewColumn $left, StorageViewColumn $right): int => [
            $left->column === null ? 1 : 0, $left->ordinal(), $left->fieldKey, $left->state,
        ] <=> [
            $right->column === null ? 1 : 0, $right->ordinal(), $right->fieldKey, $right->state,
        ]);
        $names = [];
        $written = [];
        foreach ($columns as $column) {
            if ($column->column !== null) {
                if (isset($names[$column->column]) || $column->ordinal() >= $nextOrdinal) {
                    throw new StorageRejected('storage_view_layout_invalid');
                }
                $names[$column->column] = true;
            }
            if ($column->state !== StorageViewColumn::STATE_DROPPING && $column->state !== StorageViewColumn::STATE_ADDING) {
                if (isset($written[$column->fieldKey])) {
                    throw new StorageRejected('storage_view_layout_invalid');
                }
                $written[$column->fieldKey] = true;
            }
        }
        $this->columns = $columns;
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
        return new self($schemaVersion, $nextOrdinal, $columns);
    }

    /** Every reserved column becomes written: the table has it now. */
    public function promoted(): self
    {
        return new self($this->schemaVersion, $this->nextOrdinal, array_map(
            static fn (StorageViewColumn $column): StorageViewColumn => $column->state === StorageViewColumn::STATE_ADDING
                ? $column->withState(StorageViewColumn::STATE_READY)
                : $column,
            $this->columns,
        ));
    }

    /** The layout once the dropped columns are gone from the table. */
    public function withoutDropped(): self
    {
        return new self($this->schemaVersion, $this->nextOrdinal, array_values(array_filter(
            $this->columns,
            static fn (StorageViewColumn $column): bool => $column->state !== StorageViewColumn::STATE_DROPPING,
        )));
    }

    /**
     * This layout plus the columns another layout reserves (state `adding`) that
     * this one does not have yet. Writers keep writing this layout's columns; the
     * reserved ones only exist so the table can gain them ahead of a schema change.
     */
    public function reserving(self $target): self
    {
        $columns = $this->columns;
        $known = [];
        foreach ($this->columns as $column) {
            $known[$column->column ?? ('side:' . $column->fieldKey . ':' . $column->state)] = true;
        }
        foreach ($target->inState(StorageViewColumn::STATE_ADDING) as $column) {
            $key = $column->column ?? ('side:' . $column->fieldKey . ':' . $column->state);
            if (!isset($known[$key])) {
                $columns[] = $column;
                $known[$key] = true;
            }
        }

        return new self($this->schemaVersion, max($this->nextOrdinal, $target->nextOrdinal), $columns);
    }

    /** @return array{schema_version: int, next_ordinal: int, columns: list<array{field_key: string, column: ?string, kind: string, sql_type: string, indexed: bool, state: string}>} */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'next_ordinal' => $this->nextOrdinal,
            'columns' => array_map(static fn (StorageViewColumn $column): array => $column->toArray(), $this->columns),
        ];
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
            || !is_array($data['columns'] ?? null) || !array_is_list($data['columns']) || count($data) !== 3) {
            throw new StorageRejected('storage_view_layout_invalid');
        }
        $columns = [];
        foreach ($data['columns'] as $column) {
            if (!is_array($column)) {
                throw new StorageRejected('storage_view_layout_invalid');
            }
            $columns[] = StorageViewColumn::fromArray($column);
        }

        return new self($data['schema_version'], $data['next_ordinal'], $columns);
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
