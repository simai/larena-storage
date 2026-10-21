<?php

declare(strict_types=1);

namespace Larena\Storage\View\Site;

use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\View\StorageViewFieldOptions;
use Larena\Storage\View\StorageViewLayout;
use Larena\Storage\View\StorageViewLayoutPlanner;
use Larena\Storage\View\StorageViewProjector;

/**
 * Turns one published projection entry into its site витрина row.
 *
 * The entry is the one the read contracts return for the record in the slot's scope
 * and locale: the public fields of the published revision, with the locale fallback
 * applied, their sources and the projection version. The row keeps it exactly
 * (`entry_json`), and maps its values onto the typed columns of the public fields, the
 * side rows of public multi-values and the search text of public searchable fields.
 * Nothing else reaches a site table: the layout is planned from the public fields
 * alone, and the values come from the entry alone.
 */
final readonly class PublishedSiteViewProjector
{
    public function __construct(
        private StorageViewProjector $projector = new StorageViewProjector(),
        private StorageViewLayoutPlanner $planner = new StorageViewLayoutPlanner(),
    ) {
    }

    /**
     * The public fields of a schema version, in their order. A field whose visibility
     * is absent or unrecognised is not public, as for the read contracts.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array<string, mixed>>
     */
    public static function publicFields(array $fields): array
    {
        $public = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if (!is_string($key) || str_starts_with($key, 'larena_')) {
                continue;
            }
            $visibility = is_string($field['visibility'] ?? null) ? FieldVisibility::tryFrom($field['visibility']) : null;
            if ($visibility === FieldVisibility::Public) {
                $public[] = $field;
            }
        }

        return $public;
    }

    /**
     * The search and index options of the public fields: the storage's own (its
     * workbench structure's), or the defaults of its fields.
     *
     * @param list<array<string, mixed>> $fields
     */
    public static function options(array $fields, ?StorageViewFieldOptions $options): StorageViewFieldOptions
    {
        $options ??= StorageViewFieldOptions::resolve($fields);
        $keys = array_map(static fn (array $field): string => (string) $field['key'], self::publicFields($fields));

        return new StorageViewFieldOptions(
            array_values(array_intersect($options->searchable, $keys)),
            array_values(array_intersect($options->indexed, $keys)),
        );
    }

    /**
     * What the site витрина of a storage depends on: its public fields (key, type,
     * constraints, localized) and their search and index options. A schema or option
     * change that alters it leaves every row to be rebuilt.
     *
     * @param list<array<string, mixed>> $fields
     */
    public static function publicFieldsHash(array $fields, ?StorageViewFieldOptions $options): string
    {
        $public = [];
        foreach (self::publicFields($fields) as $field) {
            $public[(string) $field['key']] = [
                'type' => $field['type'] ?? null,
                'type_version' => $field['type_version'] ?? null,
                'constraints' => self::sorted(is_array($field['constraints'] ?? null) ? $field['constraints'] : []),
                'localized' => ($field['localized'] ?? false) === true,
            ];
        }
        ksort($public, SORT_STRING);
        $options = self::options($fields, $options);

        return hash('sha256', json_encode([
            'fields' => $public,
            'searchable' => $options->searchable,
            'indexed' => $options->indexed,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The layout of a new site table: the public fields only, every column filled.
     *
     * @param list<array<string, mixed>> $fields
     */
    public function layout(array $fields, int $schemaVersion, ?StorageViewFieldOptions $options): StorageViewLayout
    {
        return $this->planner->plan(self::publicFields($fields), $schemaVersion, null, self::options($fields, $options))->promoted();
    }

    /**
     * The row, the side rows and the search row of one entry in one slot.
     *
     * @param array{record_id: string, revision: int, locale: string, projection_version: int, values: array<string, mixed>, value_sources: array<string, string>} $entry
     * @return array{row: array<string, mixed>, multi: list<array<string, mixed>>, search: array<string, mixed>|null}
     */
    public function project(StorageViewLayout $layout, int $slotId, array $entry, string $updatedAt): array
    {
        $json = self::encodeEntry($entry);
        $values = $entry['values'];
        $projected = $this->projector->project(
            $layout,
            $entry['record_id'],
            $entry['revision'],
            $layout->schemaVersion,
            str_repeat('0', 64),
            $values,
            $updatedAt,
            $updatedAt,
        );
        $row = [
            'slot_id' => $slotId,
            'record_id' => $entry['record_id'],
            'revision' => $entry['revision'],
            'schema_version' => $layout->schemaVersion,
            'projection_version' => $entry['projection_version'],
            'entry_hash' => hash('sha256', $json),
            'entry_json' => $json,
            'title' => $projected['row']['title'] ?? null,
            'updated_at' => $updatedAt,
        ];
        foreach ($layout->written() as $column) {
            if ($column->column !== null) {
                $row[$column->column] = $projected['row'][$column->column] ?? null;
            }
        }
        $multi = [];
        foreach ($projected['multi'] as $item) {
            $multi[] = ['slot_id' => $slotId] + $item;
        }
        $search = $projected['search'] === null ? null : ['slot_id' => $slotId] + $projected['search'];

        return ['row' => $row, 'multi' => $multi, 'search' => $search];
    }

    /**
     * The entry as stored: decoding it gives back the same array (keys in the same
     * order, the same scalars).
     *
     * @param array<string, mixed> $entry
     */
    public static function encodeEntry(array $entry): string
    {
        return json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    public static function decodeEntry(string $json): array
    {
        $entry = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($entry) ? $entry : [];
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }
}
