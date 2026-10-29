<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Larena\Storage\Encryption\StorageFieldEnvelope;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * Turns one record version into its витрина row and side rows, by the layout.
 * The record's values were normalized by their Property types already; this
 * only maps them onto columns.
 */
final readonly class StorageViewProjector
{
    public const SCOPE_FIELD = 'larena_scope_ref';
    public const STATE_FIELD = 'larena_record_state';
    private const STATE_ACTIVE = 'active';
    public const SEARCH_SEPARATOR = StorageViewSearch::FIELD_SEPARATOR;
    // DECIMAL(65,30): 35 digits before the point, 30 after.
    private const DECIMAL_INTEGER_DIGITS = 35;
    private const DECIMAL_FRACTION_DIGITS = 30;
    private const TITLE_KEYS = ['title', 'name'];
    private const TITLE_LENGTH = 255;

    /**
     * The row, the side rows of multi-values and the search row. A layout with search
     * fields writes the search text to the search table and leaves the main table's
     * `search_text` empty; one stored before them keeps writing it there.
     *
     * @param array<string, mixed> $values
     * @return array{row: array<string, mixed>, multi: list<array{record_id: string, field_key: string, value: string}>, search: array{record_id: string, search_text: ?string}|null}
     */
    public function project(
        StorageViewLayout $layout,
        string $recordId,
        int $revision,
        int $schemaVersion,
        string $contentHash,
        array $values,
        string $createdAt,
        string $updatedAt,
    ): array {
        $scope = $values[self::SCOPE_FIELD] ?? null;
        $state = $values[self::STATE_FIELD] ?? null;
        $row = [
            'record_id' => $recordId,
            'revision' => $revision,
            'schema_version' => $schemaVersion,
            'content_hash' => $contentHash,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'scope_ref' => is_string($scope) ? $scope : null,
            // A record without the reserved state (one of an adopted schema, written before the adoption) is not
            // archived: it lists as active.
            'state' => is_string($state) ? $state : self::STATE_ACTIVE,
            'title' => $this->title($layout, $values),
            'search_text' => null,
        ];
        $search = null;
        if ($layout->searchTable()) {
            $search = ['record_id' => $recordId, 'search_text' => $this->searchText($layout, $values)];
        } else {
            $row['search_text'] = $this->searchText($layout, $values);
        }
        $multi = [];
        foreach ($layout->written() as $column) {
            $value = $values[$column->fieldKey] ?? null;
            if ($column->column === null) {
                foreach ($this->multiValues($value) as $item) {
                    $multi[] = ['record_id' => $recordId, 'field_key' => $column->fieldKey, 'value' => $item];
                }
                continue;
            }
            $row[$column->column] = $this->columnValue($column, $value);
        }

        return ['row' => $row, 'multi' => $multi, 'search' => $search];
    }

    /** @param array<string, mixed> $values */
    public function title(StorageViewLayout $layout, array $values): ?string
    {
        $key = $this->titleKey($layout);
        $value = $key === null ? null : ($values[$key] ?? null);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, self::TITLE_LENGTH, 'UTF-8') : substr($value, 0, self::TITLE_LENGTH);
    }

    /** The field whose value is the row's title: `title`, `name`, or the first string or text field. */
    public function titleKey(StorageViewLayout $layout): ?string
    {
        $candidates = [];
        foreach ($layout->written() as $column) {
            if (in_array($column->kind, ['string', 'text'], true)) {
                $candidates[] = $column->fieldKey;
            }
        }
        foreach (self::TITLE_KEYS as $preferred) {
            if (in_array($preferred, $candidates, true)) {
                return $preferred;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * One column's value, for a walk that fills a column a layout has not started
     * writing yet (a field change fills the new column of a retyped field).
     */
    public function cell(StorageViewColumn $column, mixed $value): mixed
    {
        return $this->columnValue($column, $value);
    }

    /**
     * The side rows one multi-value field holds for a record.
     *
     * @return list<array{record_id: string, field_key: string, value: string}>
     */
    public function sideRows(string $recordId, string $fieldKey, mixed $value): array
    {
        return array_map(
            static fn (string $item): array => ['record_id' => $recordId, 'field_key' => $fieldKey, 'value' => $item],
            $this->multiValues($value),
        );
    }

    /**
     * The search text (StorageViewSearch::text) of the layout's search fields, in
     * key order. A layout stored before the search fields existed takes every string
     * and text column.
     *
     * @param array<string, mixed> $values
     */
    public function searchText(StorageViewLayout $layout, array $values): ?string
    {
        $keys = $layout->searchFields;
        if ($keys === null) {
            $keys = [];
            foreach ($layout->written() as $column) {
                if (in_array($column->kind, ['string', 'text'], true) && !in_array($column->fieldKey, $keys, true)) {
                    $keys[] = $column->fieldKey;
                }
            }
        }
        $texts = [];
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_string($value)) {
                $texts[] = $value;
            }
        }

        return StorageViewSearch::text($texts);
    }

    private function columnValue(StorageViewColumn $column, mixed $value): mixed
    {
        // An envelope (a field a decrypt run has not opened yet) is never a cell.
        if ($value === null || StorageFieldEnvelope::isEnvelope($value)) {
            return null;
        }

        return match ($column->sqlType) {
            StorageViewColumn::SQL_BIGINT => is_int($value) ? $value : throw new StorageRejected('storage_view_value_invalid'),
            StorageViewColumn::SQL_DECIMAL => $this->decimal($value),
            StorageViewColumn::SQL_DATE => is_string($value) ? $value : throw new StorageRejected('storage_view_value_invalid'),
            // Property datetimes are YYYY-MM-DDTHH:MM:SS; the column takes a space.
            StorageViewColumn::SQL_DATETIME => is_string($value) ? str_replace('T', ' ', $value) : throw new StorageRejected('storage_view_value_invalid'),
            StorageViewColumn::SQL_TINYINT => is_bool($value) ? ($value ? 1 : 0) : throw new StorageRejected('storage_view_value_invalid'),
            StorageViewColumn::SQL_REF, StorageViewColumn::SQL_USER_REF => $this->bounded($column, $value),
            default => match (true) {
                $column->isVarchar() => $this->bounded($column, $value),
                is_string($value) => $value,
                default => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            },
        };
    }

    private function decimal(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A-?([0-9]+)(?:\.([0-9]+))?\z/D', $value, $parts) !== 1) {
            throw new StorageRejected('storage_view_value_invalid');
        }
        $integer = ltrim($parts[1], '0');
        if (strlen($integer) > self::DECIMAL_INTEGER_DIGITS || strlen($parts[2] ?? '') > self::DECIMAL_FRACTION_DIGITS) {
            throw new StorageRejected('storage_view_number_out_of_range');
        }

        return $value;
    }

    private function bounded(StorageViewColumn $column, mixed $value): string
    {
        if (!is_string($value)) {
            throw new StorageRejected('storage_view_value_invalid');
        }
        $limit = match ($column->sqlType) {
            StorageViewColumn::SQL_REF => 64,
            StorageViewColumn::SQL_USER_REF => 96,
            default => StorageViewColumn::varcharLength($column->sqlType) ?? StorageViewColumn::VARCHAR_MAX,
        };
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $limit) {
            throw new StorageRejected('storage_view_value_too_long');
        }

        return $value;
    }

    /** @return list<string> */
    private function multiValues(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                if (strlen($item) > 191) {
                    throw new StorageRejected('storage_view_value_too_long');
                }
                $items[$item] = true;
            }
        }

        return array_map('strval', array_keys($items));
    }
}
