<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Dataview\Enums\DataviewViewType;
use Larena\Setting\ViewConfig\ViewConfigDocument;

/**
 * A saved view of a Storage list as a `simai.view-config/1` document, and back.
 *
 * The lists read a saved view as they always did (saved_view_id, structure_id, name, query, roles, layout, settings,
 * revision); the store keeps it as the contract's document:
 *
 * - `id` is the view's id, `name` its name, `source` the storage (its id, or a digest of it when it is longer than the
 *   contract's 64 characters: the list's address in the store names the storage in full);
 * - `layout` is the view type of its data view (a kanban is a `board`); a view without a data view opens as a table;
 * - `query.filter` holds the toolbar filters (`values` of an `in` or `between` become the condition's `value` list; the
 *   single filter of the oldest lists becomes an `eq`); archived records shown are the pseudo field `_archived`;
 *   `query.sort` the sort; `query.per_page` the page size (10/20/50/100). The search text is never saved (contract §2);
 * - `head.filter_fields` the filter fields the person put in the list head (the view's `settings.filter_fields`);
 * - `layouts.<layout>` keeps what is Storage's own: the column layout (`columns`), the field roles (`roles`), the rest of
 *   the view's settings (`settings`) and, for a view type without a layout of its own (gantt), the `view_type`.
 */
final class StorageViewConfigCodec
{
    public const ARCHIVED = '_archived';
    private const SOURCE = '/^[A-Za-z0-9_.-]{1,64}$/D';

    /**
     * @param array<string, mixed> $view a saved view as the lists read it
     * @return array<string, mixed>
     */
    public static function document(array $view): array
    {
        $structureId = (string) $view['structure_id'];
        $query = is_array($view['query'] ?? null) ? $view['query'] : [];
        $dataviewId = is_string($query['dataview_id'] ?? null) ? $query['dataview_id'] : null;
        $type = $dataviewId !== null ? DataviewViewType::tryFrom(substr($dataviewId, (int) strrpos($dataviewId, '.') + 1)) : null;
        $layout = $type?->layout() ?? 'table';
        $filter = [];
        foreach (is_array($query['filters'] ?? null) ? $query['filters'] : [] as $field => $condition) {
            if (is_array($condition) && is_string($condition['operator'] ?? null)) {
                $filter[(string) $field] = ['operator' => $condition['operator']]
                    + (array_key_exists('values', $condition) ? ['value' => $condition['values']] : [])
                    + (array_key_exists('value', $condition) ? ['value' => $condition['value']] : []);
            }
        }
        if (is_string($query['filter_field'] ?? null) && is_string($query['filter_value'] ?? null) && !isset($filter[$query['filter_field']])) {
            $filter[$query['filter_field']] = ['operator' => 'eq', 'value' => $query['filter_value']];
        }
        if (($query['include_deleted'] ?? false) === true) {
            $filter[self::ARCHIVED] = ['operator' => 'eq', 'value' => true];
        }
        ksort($filter, SORT_STRING);
        $documentQuery = [];
        if ($filter !== []) {
            $documentQuery['filter'] = $filter;
        }
        if (is_string($query['sort_field'] ?? null) && in_array($query['sort_direction'] ?? null, ['asc', 'desc'], true)) {
            $documentQuery['sort'] = [['field' => $query['sort_field'], 'dir' => $query['sort_direction']]];
        }
        $documentQuery['per_page'] = in_array($query['per_page'] ?? null, ViewConfigDocument::PER_PAGE, true)
            ? $query['per_page'] : ViewConfigDocument::PER_PAGE_DEFAULT;
        $settings = is_array($view['settings'] ?? null) ? $view['settings'] : [];
        $head = [];
        if (is_array($settings['filter_fields'] ?? null) && array_is_list($settings['filter_fields'])
            && array_filter($settings['filter_fields'], static fn (mixed $key): bool => !is_string($key) || preg_match(self::SOURCE, $key) !== 1) === []) {
            $head['filter_fields'] = $settings['filter_fields'];
            unset($settings['filter_fields']);
        }
        $own = ['columns' => is_array($view['layout']['columns'] ?? null) ? $view['layout']['columns'] : [],
            'roles' => is_array($view['roles'] ?? null) ? $view['roles'] : [], 'settings' => $settings]
            + ($type !== null && $type->layout() === null ? ['view_type' => $type->value] : []);

        return ['schema' => ViewConfigDocument::SCHEMA, 'id' => (string) $view['saved_view_id'], 'name' => (string) $view['name'],
            'source' => self::source($structureId), 'layout' => $layout, 'query' => $documentQuery]
            + ($head !== [] ? ['head' => $head] : [])
            + ['layouts' => [$layout => $own], 'revision' => max(1, (int) ($view['revision'] ?? 1))];
    }

    /**
     * A stored document as the lists read a saved view of the storage.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function view(array $document, string $structureId): array
    {
        $layout = is_string($document['layout'] ?? null) ? $document['layout'] : 'table';
        $own = self::map($document['layouts'][$layout] ?? []);
        $type = is_string($own['view_type'] ?? null) ? DataviewViewType::tryFrom($own['view_type']) : DataviewViewType::fromLayout($layout);
        $query = self::map($document['query'] ?? []);
        $filters = [];
        $archived = false;
        foreach (self::map($query['filter'] ?? []) as $field => $condition) {
            $condition = self::map($condition);
            if ($field === self::ARCHIVED) {
                $archived = ($condition['value'] ?? null) === true;
                continue;
            }
            if (!is_string($condition['operator'] ?? null)) {
                continue;
            }
            $value = $condition['value'] ?? null;
            $filters[(string) $field] = ['operator' => $condition['operator']]
                + (in_array($condition['operator'], ['in', 'between'], true) ? ['values' => is_array($value) ? array_values($value) : []]
                    : (array_key_exists('value', $condition) ? ['value' => $value] : []));
        }
        ksort($filters, SORT_STRING);
        $sort = is_array($query['sort'][0] ?? null) ? $query['sort'][0] : [];
        $sortField = is_string($sort['field'] ?? null) && in_array($sort['dir'] ?? null, ['asc', 'desc'], true) ? $sort['field'] : null;
        $columns = self::map($own['columns'] ?? []);
        if ($columns === [] && is_array($document['fields']['visible'] ?? null)) {
            // A document written by another host names its fields only: they are the visible columns, in their order.
            $order = is_array($document['fields']['order'] ?? null) ? array_values($document['fields']['order']) : $document['fields']['visible'];
            foreach ($document['fields']['visible'] as $key) {
                if (is_string($key)) {
                    $position = array_search($key, $order, true);
                    $columns[$key] = ['visible' => true] + (is_int($position) ? ['order' => $position * 10] : []);
                }
            }
        }
        $settings = self::map($own['settings'] ?? []);
        if (is_array($document['head']['filter_fields'] ?? null)) {
            $settings['filter_fields'] = array_values($document['head']['filter_fields']);
        }

        return [
            'saved_view_id' => (string) ($document['id'] ?? ''),
            'structure_id' => $structureId,
            'name' => (string) ($document['name'] ?? ''),
            'query' => [
                'dataview_id' => $type !== null && $type !== DataviewViewType::Table ? $structureId.'.'.$type->value : null,
                'search' => null,
                'filters' => $filters,
                'filter_field' => null,
                'filter_value' => null,
                'sort_field' => $sortField,
                'sort_direction' => $sortField !== null ? $sort['dir'] : null,
                'per_page' => in_array($query['per_page'] ?? null, ViewConfigDocument::PER_PAGE, true) ? $query['per_page'] : ViewConfigDocument::PER_PAGE_DEFAULT,
                'include_deleted' => $archived,
            ],
            'revision' => max(1, (int) ($document['revision'] ?? 1)),
            'roles' => self::map($own['roles'] ?? []),
            'layout' => ['columns' => $columns],
            'settings' => $settings,
        ];
    }

    /** The document's `source`: the storage's id, or a digest of it when the id is longer than the contract allows. */
    public static function source(string $structureId): string
    {
        return preg_match(self::SOURCE, $structureId) === 1 ? $structureId : 'workbench.h'.substr(hash('sha256', $structureId), 0, 40);
    }

    /** @return array<string, mixed> an object of the document (decoded JSON keeps an empty one as an empty list) */
    private static function map(mixed $value): array
    {
        return is_array($value) && ($value === [] || !array_is_list($value)) ? $value : [];
    }
}
