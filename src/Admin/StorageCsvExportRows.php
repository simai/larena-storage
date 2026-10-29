<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use InvalidArgumentException;
use Larena\Admin\Runtime\AdminDataviewCells;
use Larena\Property\Contracts\PropertyFieldDefinition;
use Larena\Property\Contracts\PropertyReferenceLabelResolver;
use Larena\Property\Contracts\PropertyRenderContext;
use Larena\Property\Runtime\PropertyDisplayProjector;
use Larena\Property\Runtime\PropertyTypeRegistry;

/**
 * A storage's records as CSV lines, in the format of the list's «Export CSV»: a byte order mark, then the record's
 * ID first (so an edited file comes back through the import and updates the same records), then the shown fields
 * by their labels; a value as the list shows it, a chosen option by its label, «1» / «0» for yes and no, and a value
 * a spreadsheet would run as a formula behind an apostrophe. The list's query (search, filters, sort) is read from
 * the data view's shape into Storage's.
 *
 * An encrypted field («Шифровать») is left out of the file, its column too: the export never holds its plaintext nor
 * its stored form, and a file brought back through the import leaves its value as it is.
 */
final readonly class StorageCsvExportRows
{
    public const BOM = "\u{FEFF}";
    private const RELATIVE_DATE_OPERATORS = ['today', 'week', 'month'];

    private PropertyDisplayProjector $projector;

    public function __construct(
        private ?PropertyReferenceLabelResolver $labels = null,
        ?PropertyDisplayProjector $projector = null,
    ) {
        $this->projector = $projector ?? new PropertyDisplayProjector(PropertyTypeRegistry::builtIns());
    }

    /**
     * The fields the file carries: every field the list shows but the hidden and the encrypted ones, in the storage's
     * order.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<string>
     */
    public static function shownKeys(array $fields): array
    {
        $keys = [];
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null) && ($field['hidden'] ?? false) !== true && !StorageEncryptedFields::isEncrypted($field)) {
                $keys[] = $field['key'];
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     * @param list<array<string, mixed>> $fields
     */
    public function header(array $keys, array $fields): string
    {
        $byKey = self::byKey($fields);

        return self::line(['ID', ...array_map(static fn (string $key): string => self::safe(
            is_string($byKey[$key]['label'] ?? null) && $byKey[$key]['label'] !== '' ? $byKey[$key]['label'] : $key), $keys)]);
    }

    /**
     * One record as a CSV line.
     *
     * @param list<string> $keys
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     */
    public function row(string $recordId, array $values, array $keys, array $fields, string $locale, string $actor, string $scope): string
    {
        $byKey = self::byKey($fields);
        $context = PropertyRenderContext::admin($locale, false, $actor, $scope);
        $cells = [$recordId];
        foreach ($keys as $key) {
            $field = $byKey[$key] ?? null;
            if ($field === null) {
                $cells[] = '';
                continue;
            }
            $raw = $values[$key] ?? null;
            $projection = $this->projector->project(
                PropertyFieldDefinition::make($key, is_string($field['type'] ?? null) ? $field['type'] : '', 'lang:'.$key,
                    is_array($field['constraints'] ?? null) ? $field['constraints'] : [], ($field['required'] ?? false) === true),
                is_int($field['type_version'] ?? null) ? $field['type_version'] : 1,
                $raw, $context, $this->labels,
            );
            $cells[] = self::safe(self::text(AdminDataviewCells::value($field, $raw, $projection->status === 'present' ? $projection->text : null),
                self::optionLabels($field)));
        }

        return self::line($cells);
    }

    /**
     * The list's query as the data view sends it ({search, filters, sort: [{key, direction}]}) in Storage's shape,
     * against the fields the list shows; without a sort, the list's own order (StorageListOrder::NEWEST_FIRST). A filter the background export cannot read the same way the list does
     * (a relative date range) refuses the query instead of exporting more than the list shows.
     *
     * @param list<string> $known
     * @return array{search: string, filters: array<string, array<string, mixed>>, sort: list<array{field: string, direction: string}>}
     */
    public static function query(mixed $raw, array $known): array
    {
        $query = is_string($raw) && strlen($raw) <= 65_536 ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        $query = is_array($query) ? $query : [];
        $search = is_string($query['search'] ?? null) ? trim(mb_substr($query['search'], 0, 100)) : '';
        $filters = [];
        $chosen = is_array($query['filters'] ?? null) && !array_is_list($query['filters']) ? $query['filters'] : [];
        if (count($chosen) > 20) {
            throw new InvalidArgumentException('storage_export_filter_invalid');
        }
        foreach ($chosen as $key => $value) {
            if (!is_string($key) || !in_array($key, $known, true) || !is_array($value) || $value === []) {
                throw new InvalidArgumentException('storage_export_filter_invalid');
            }
            $filters[$key] = self::filter($value);
        }
        $sort = [];
        foreach (is_array($query['sort'] ?? null) && array_is_list($query['sort']) ? array_slice($query['sort'], 0, 3) : [] as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? $entry['field'] ?? null) : null;
            $direction = is_array($entry) ? ($entry['direction'] ?? null) : null;
            if (!is_string($key) || !in_array($direction, ['asc', 'desc'], true)
                || (!in_array($key, $known, true) && !StorageListOrder::isSystem($key))) {
                throw new InvalidArgumentException('storage_export_sort_invalid');
            }
            $sort[] = ['field' => $key, 'direction' => $direction];
        }

        // Without a sort the list shows its own order, newest first: the file keeps it, and the job names it.
        return ['search' => $search, 'filters' => $filters, 'sort' => $sort === [] ? StorageListOrder::NEWEST_FIRST : $sort];
    }

    /**
     * One filter in Storage's shape: a list of values is «any of»; a range or a one-item choice is read as Storage
     * expects it (`values` for in and between, `value` for the other operators).
     *
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function filter(array $value): array
    {
        if (array_is_list($value)) {
            return ['operator' => 'in', 'values' => $value];
        }
        $operator = $value['operator'] ?? null;
        if (!is_string($operator) || in_array($operator, self::RELATIVE_DATE_OPERATORS, true)) {
            throw new InvalidArgumentException('storage_export_filter_unsupported');
        }
        if (in_array($operator, ['in', 'between'], true)) {
            if (!array_key_exists('values', $value) && is_array($value['value'] ?? null) && array_is_list($value['value'])) {
                $value['values'] = $value['value'];
            }
            unset($value['value']);
        } else {
            if (!array_key_exists('value', $value) && is_array($value['values'] ?? null) && count($value['values']) === 1
                && array_is_list($value['values'])) {
                $value['value'] = $value['values'][0];
            }
            unset($value['values']);
        }

        return $value;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    private static function byKey(array $fields): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $byKey[$field['key']] = $field;
            }
        }

        return $byKey;
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, string>
     */
    private static function optionLabels(array $field): array
    {
        $labels = [];
        $options = is_array($field['constraints'] ?? null) && is_array($field['constraints']['options'] ?? null) ? $field['constraints']['options'] : [];
        foreach ($options as $option) {
            if (is_array($option) && is_scalar($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                $labels[(string) $option['value']] = $option['label'];
            }
        }

        return $labels;
    }

    /**
     * A cell as text: the plain value, the label of a chosen option, or the text a formatted cell shows.
     *
     * @param array<string, string> $labels
     */
    private static function text(mixed $value, array $labels = []): string
    {
        return match (true) {
            is_string($value), is_int($value), is_float($value) => $labels[(string) $value] ?? (string) $value,
            is_bool($value) => $value ? '1' : '0',
            is_array($value) && array_is_list($value) => implode(', ', array_map(static fn (mixed $item): string => self::text($item, $labels), $value)),
            is_array($value) => self::text($value['text'] ?? $value['label'] ?? $value['value'] ?? '', $labels),
            default => '',
        };
    }

    /** A value a spreadsheet would read as a formula starts with an apostrophe. */
    private static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** @param list<string> $cells */
    private static function line(array $cells): string
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new InvalidArgumentException('storage_export_line_unwritable');
        }
        fputcsv($stream, $cells, ',', '"', '');
        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);

        return is_string($line) ? $line : '';
    }
}
