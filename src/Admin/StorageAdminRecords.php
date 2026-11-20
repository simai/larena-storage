<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use InvalidArgumentException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Access\Runtime\ScopeMembershipQueryScopeProvider;
use Larena\Dataview\Contracts\DataviewQuery;
use Larena\Dataview\Contracts\DataviewSourceDescriptor;
use Larena\Dataview\Enums\DataviewViewType;
use Larena\Dataview\Runtime\DataviewQueryCarryOver;
use Larena\Dataview\Runtime\DataviewRoleBinder;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The records part of the admin's records screen and records search: the list of a structure's records (filters,
 * search, sort, pages, the shared query every data view reads and the links between views), column settings and
 * saved views, structure and record create/update/delete/restore, bulk archive (selected and matching) and file
 * attachment. Moved here from Root's RootAdminRecordsGateway (block 5, 2026-11-16; split plan step
 * records_list_and_crud_to_storage in Root's presentation debt inventory); the Root bridge keeps the data view
 * descriptors and page layouts and delegates the rest to this class.
 *
 * Admin-only (under {@see StorageSectionBindings::adminInstalled()}): it names Dataview, Filesystem and Access
 * contracts that Storage's admin part already uses. Every call gets the scope the admin resolved for the actor.
 */
final readonly class StorageAdminRecords
{
    public const MAX_JSON_BYTES = 1_048_576;

    /** "For all" archives at most this many records at once, in one Storage transaction. */
    private const MAX_BULK_MATCHING = 1000;

    /** @var array<string, list<string>> */
    private const TABLE_INTENT_KEYS = [
        'record.create' => ['structure_id', 'values_json'],
        'record.update' => ['structure_id', 'record_id', 'expected_revision', 'values_json'],
        'record.delete' => ['structure_id', 'record_id', 'expected_revision'],
        'record.restore' => ['structure_id', 'record_id', 'expected_revision'],
        'record.bulk_delete' => ['structure_id', 'confirmed', 'expected_revisions'],
        'record.bulk_delete_matching' => ['structure_id', 'confirmed', 'expected_count', 'filters', 'search'],
        'dataview.preferences.save' => ['structure_id', 'base_revision', 'columns'],
        'dataview.preferences.save_shared' => ['structure_id', 'base_revision', 'columns'],
        'dataview.preferences.reset' => ['structure_id', 'base_revision'],
        'dataview.preferences.reset_shared' => ['structure_id', 'base_revision'],
        'dataview.saved_view.save' => ['structure_id', 'name', 'query', 'loaded_saved_view_id', 'base_revision', 'roles', 'layout', 'settings'],
        'dataview.saved_view.delete' => ['structure_id', 'saved_view_id'],
        'dataview.saved_view.layout' => ['saved_view_id', 'base_revision', 'columns'],
        'dataview.saved_view.reset_layout' => ['saved_view_id', 'base_revision'],
    ];

    /** @var array<string, list<string>> */
    private const TABLE_INTENT_OPTIONAL_KEYS = [
        'record.bulk_delete_matching' => ['search'],
        'dataview.saved_view.save' => ['query', 'loaded_saved_view_id', 'base_revision', 'roles', 'layout', 'settings'],
    ];

    public function __construct(
        private ActorOperationAuthorizer $authorizer,
        private ScopeMembershipQueryScopeProvider $scopes,
        private StorageWorkbench $storage,
        private PersistentLogicalFileInspector $fileInspector,
        // Column settings and saved views of the list; shared with the Storage section.
        private DataviewListPreferences $lists,
    ) {
    }

    /**
     * Applies a records or list-settings action of the admin's records screen.
     *
     * @param array<array-key, mixed> $payload
     * @return array{array<string, mixed>, array<string, mixed>|null}|null [redirect, mutation], or null when the action is not a records action
     */
    public function mutate(string $actor, string $scope, string $action, array $payload): ?array
    {
        $this->assertTableIntentKeys($action, $payload);

        return match ($action) {
            'structure.create' => $this->createStructure($actor, $scope, $payload),
            'structure.update' => $this->updateStructure($actor, $scope, $payload),
            'record.create' => $this->createRecord($actor, $scope, $payload),
            'record.update' => $this->updateRecord($actor, $scope, $payload),
            'record.delete' => $this->deleteRecord($actor, $scope, $payload),
            'record.bulk_delete' => $this->bulkDeleteRecords($actor, $scope, $payload),
            'record.bulk_delete_matching' => $this->bulkDeleteMatching($actor, $scope, $payload),
            'record.restore' => $this->restoreRecord($actor, $scope, $payload),
            'file.attach' => $this->attachFile($actor, $scope, $payload),
            'dataview.preferences.save' => $this->saveTablePreferences($actor, $scope, $payload),
            'dataview.preferences.save_shared' => $this->saveTablePreferences($actor, $scope, $payload, shared: true),
            'dataview.preferences.reset' => $this->saveTablePreferences($actor, $scope, [...$payload, 'columns' => []]),
            'dataview.preferences.reset_shared' => $this->saveTablePreferences($actor, $scope, [...$payload, 'columns' => []], shared: true),
            'dataview.saved_view.save' => $this->saveDataview($actor, $scope, $payload),
            'dataview.saved_view.delete' => $this->deleteDataview($actor, $scope, $payload),
            'dataview.saved_view.layout' => $this->saveSavedViewLayout($actor, $scope, $payload),
            'dataview.saved_view.reset_layout' => $this->saveSavedViewLayout($actor, $scope, [...$payload, 'columns' => []]),
            default => null,
        };
    }

    /**
     * Records of every structure in the actor's scope whose text fields contain the search, for the
     * admin search, in the scope the admin resolved for the actor. Each structure is listed with the actor's own access; a structure the actor
     * may not list is skipped rather than failing the whole answer.
     */
    public function search(string $actor, string $scope, string $search, int $limit): array
    {
        $search = trim($search);
        $limit = max(1, min(50, $limit));
        if ($search === '' || mb_strlen($search) > 100 || preg_match('/[\x00-\x1F\x7F]/', $search) === 1) {
            return [];
        }
        $found = [];
        foreach (array_slice($this->storage->listStructures($scope, $actor), 0, 50) as $structure) {
            if (count($found) >= $limit) {
                break;
            }
            $title = (new DataviewRoleBinder)->bind(DataviewViewType::Table, array_map('strval', array_column($structure->fields, 'type', 'key')), ['title' => 'title'])['bindings']['title'] ?? null;
            try {
                // Quick search shows the first matches only: no count.
                $page = $this->storage->listRecords(new StorageWorkbenchRecordQuery($scope, $structure->structureId, search: $search, limit: $limit - count($found),
                    countMode: StorageWorkbenchCountMode::Skip), $actor);
            } catch (AccessMutationRejected) {
                continue;
            }
            foreach ($page->items as $record) {
                $value = is_string($title) ? ($record->values[$title] ?? null) : null;
                $found[] = [
                    'structure_id' => $structure->structureId,
                    'structure_label' => $structure->label,
                    'record_id' => $record->recordId,
                    'title' => is_string($value) && trim($value) !== '' ? trim($value) : $record->recordId,
                ];
            }
        }

        return array_slice($found, 0, $limit);
    }
    /** @param array<array-key, mixed> $payload */
    private function assertTableIntentKeys(string $action, array $payload): void
    {
        $allowed = self::TABLE_INTENT_KEYS[$action] ?? null;
        if ($allowed !== null && ($payload !== [] && array_is_list($payload)
            || array_diff(array_keys($payload), $allowed) !== []
            || array_diff($allowed, self::TABLE_INTENT_OPTIONAL_KEYS[$action] ?? [], array_keys($payload)) !== [])) {
            throw new InvalidArgumentException('cms_intent_payload_keys_invalid');
        }
    }
    /**
     * Column settings resolve in layers (the package default, the shared layer, the person's own);
     * the list layer store is shared with the Storage section ({@see DataviewListPreferences}).
     *
     * @param array<string, mixed>|null $savedView
     * @return array<string, mixed>
     */
    public function tablePreferences(string $actor, string $scope, StorageWorkbenchStructure $structure, ?array $savedView = null): array
    {
        return $this->lists->columns($actor, $scope, $structure, $savedView);
    }
    private function saveTablePreferences(string $actor, string $scope, array $payload, bool $shared = false): array
    {
        $structureId = self::structureId($payload);
        $this->lists->saveColumns($actor, $scope, $structureId, $payload['base_revision'] ?? null, $payload['columns'] ?? null, $shared);

        return [['scope_ref' => $scope, 'structure_id' => $structureId], null];
    }
    /** @return array{array<string, mixed>, string|null} */
    public function applySavedView(array $query, array $savedViews): array
    {
        $savedViewId = self::nullableId($query['saved_view_id'] ?? null, DataviewListPreferences::SAVED_VIEW_ID);
        if ($savedViewId === null) {
            return [$query, null];
        }
        foreach ($savedViews as $view) {
            if (hash_equals((string) $view['saved_view_id'], $savedViewId)) {
                return [[...$query, ...$view['query'], 'structure_id' => $view['structure_id']], $savedViewId];
            }
        }

        throw new InvalidArgumentException('cms_saved_view_unknown');
    }
    /** @return array{revision:int, views:list<array<string, mixed>>} */
    public function savedViewObjects(string $actor, string $scope): array
    {
        return $this->lists->savedViews($actor, $scope);
    }
    /** @param list<array<string, mixed>> $views */
    public function activeSavedView(array $views, ?string $savedViewId): ?array
    {
        return $this->lists->savedView($views, $savedViewId);
    }
    /** @return array{array<string, string>, null} */
    private function saveDataview(string $actor, string $scope, array $payload): array
    {
        $savedViewId = $this->lists->saveView($actor, $scope, $payload);

        return [[
            'scope_ref' => $scope,
            'structure_id' => self::structureId($payload),
            'saved_view_id' => $savedViewId,
        ], null];
    }
    /** @return array{array<string, string>, null} */
    private function saveSavedViewLayout(string $actor, string $scope, array $payload): array
    {
        $target = $this->lists->saveViewLayout($actor, $scope, $payload['saved_view_id'] ?? null, $payload['base_revision'] ?? null, $payload['columns'] ?? null);

        return [['scope_ref' => $scope, ...$target], null];
    }
    /** @return array{array<string, string>, null} */
    private function deleteDataview(string $actor, string $scope, array $payload): array
    {
        $structureId = self::structureId($payload);
        $this->lists->deleteView($actor, $scope, $payload['saved_view_id'] ?? null);

        // The view no longer exists, so the list reopens on its collection rather than on that id.
        return [['scope_ref' => $scope, 'structure_id' => $structureId], null];
    }
    /** @return array<string, mixed> */
    /** @param array<string,bool> $permissions */
    public function records(string $actor, string $scope, ?StorageWorkbenchStructure $structure, array $query, array $permissions = []): array
    {
        $perPage = self::perPage($query['per_page'] ?? 20);
        $pageNumber = is_int($query['page'] ?? null) && $query['page'] >= 1 && $query['page'] <= 100_000
            ? $query['page']
            : 1;
        $search = self::nullableQueryString($query['search'] ?? null, 100);
        $includeArchived = ($query['include_deleted'] ?? false) === true;
        $queryState = ['search' => $search ?? '', 'per_page' => $perPage, 'page' => $pageNumber, 'include_deleted' => $includeArchived];
        if ($structure === null) {
            return ['items' => [], 'matched_count' => 0, 'matched_count_exact' => true, 'continuation' => null, 'history' => [], 'query' => $queryState];
        }
        $fieldKeys = array_fill_keys(array_map(static fn (array $field): string => (string) $field['key'], $structure->fields), true);
        $filterField = self::nullableId($query['filter_field'] ?? null, '/^[a-z][a-z0-9_.-]{0,119}$/');
        $filterValue = self::nullableQueryString($query['filter_value'] ?? null, 1000);
        if ($filterField !== null && ! isset($fieldKeys[$filterField])) {
            throw new InvalidArgumentException('cms_filter_invalid');
        }
        $sortField = self::nullableId($query['sort_field'] ?? null, '/^(?:_[a-z][a-z0-9_]{0,30}|[a-z][a-z0-9_.-]{0,119})$/');
        $sortDirection = self::nullableQueryString($query['sort_direction'] ?? null, 4);
        if (($sortField === null) !== ($sortDirection === null)
            || ($sortField !== null && ! isset($fieldKeys[$sortField]) && ! StorageListOrder::isSystem($sortField))
            || ($sortDirection !== null && ! in_array($sortDirection, ['asc', 'desc'], true))) {
            throw new InvalidArgumentException('cms_sort_invalid');
        }
        $continuation = self::nullableId($query['continuation'] ?? null, '/^[A-Za-z0-9_-]{1,2048}$/');
        // A filter from an old link or a changed field must not take the list down: it is dropped
        // and the page says so.
        $filtersRejected = false;
        try {
            $filters = $this->queryFilters($structure, $query['filters'] ?? null);
        } catch (InvalidArgumentException) {
            $filters = [];
            $filtersRejected = true;
        }
        if ($filterField !== null && $filterValue !== null && ! isset($filters[$filterField])) {
            $filters[$filterField] = ['operator' => 'eq', 'value' => $filterValue];
        }
        // Without a sort of the person's the list is in its own order, newest first; the echo keeps no sort, so the
        // order is not written into a saved view or a link as the person's choice.
        $sort = $sortField === null ? StorageListOrder::NEWEST_FIRST : [['field' => $sortField, 'direction' => $sortDirection]];
        $list = fn (array $filters) => $this->storage->listRecords(new StorageWorkbenchRecordQuery(
            $scope,
            $structure->structureId,
            filters: $filters,
            search: $search,
            sort: $sort,
            limit: $perPage,
            continuation: $continuation,
            includeArchived: $includeArchived,
            page: $continuation === null ? $pageNumber : null,
        ), $actor);
        try {
            $page = $list($filters);
        } catch (StorageRejected $exception) {
            if ($filters === [] || ! str_contains($exception->reasonCode, 'filter')) {
                throw $exception;
            }
            $filters = [];
            $filtersRejected = true;
            $page = $list([]);
        }
        $items = array_map(fn (StorageWorkbenchRecord $item): array => $this->record($item, $permissions), $page->items);
        $recordId = self::nullableId($query['record_id'] ?? null, '/^record-[a-f0-9]{32}$/');
        $history = $recordId === null ? [] : array_map(
            fn (StorageWorkbenchRecord $item): array => $this->record($item, $permissions),
            $this->storage->recordHistory($scope, $structure->structureId, $recordId, $actor),
        );

        return [
            'items' => $items,
            'matched_count' => $page->matchedCount,
            'matched_count_exact' => $page->countExact,
            'continuation' => $page->continuation,
            'history' => $history,
            'query' => [
                ...$queryState,
                'filter_field' => $filterField,
                'filter_value' => $filterValue,
                'filters' => $filters,
                'filters_rejected' => $filtersRejected,
                'sort_field' => $sortField,
                'sort_direction' => $sortDirection,
            ],
        ];
    }
    /**
     * The list query in the shared model every view reads: filters as chosen (a relative range stays
     * relative), search, sort and the window. A table, a board and a calendar built from it return
     * the same records. Parts that the shared model cannot name, such as the internal record order,
     * are returned separately so that a view switch can say it dropped them.
     *
     * @param array<string, mixed> $echo the validated query the list was read with
     * @return array{DataviewQuery, list<'filters'|'sort'>}
     */
    public function sharedQuery(StorageWorkbenchStructure $structure, array $query, array $echo): array
    {
        $unshared = [];
        $filters = [];
        $requested = $query['filters'] ?? [];
        if (is_string($requested)) {
            try {
                $requested = $requested === '' ? [] : json_decode($requested, true, 6, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $requested = [];
            }
        }
        foreach (($echo['filters_rejected'] ?? false) === true || ! is_array($requested) ? [] : $requested as $field => $filter) {
            // Only filters the list actually applied are shared.
            if (! is_string($field) || ! isset($echo['filters'][$field]) || ! is_array($filter)) {
                continue;
            }
            if (! DataviewSourceDescriptor::isStableKey($field)) {
                $unshared[] = 'filters';

                continue;
            }
            $operator = (string) ($filter['operator'] ?? '');
            $filters[] = ['field' => $field, 'operator' => $operator, 'value' => match (true) {
                in_array($operator, DataviewQuery::RELATIVE_OPERATORS, true) => null,
                in_array($operator, ['in', 'between'], true) => array_values((array) ($filter['values'] ?? [])),
                default => $filter['value'] ?? null,
            }];
        }
        $legacyField = $echo['filter_field'] ?? null;
        if (is_string($legacyField) && is_string($echo['filter_value'] ?? null) && ! isset($requested[$legacyField])) {
            DataviewSourceDescriptor::isStableKey($legacyField)
                ? $filters[] = ['field' => $legacyField, 'operator' => 'eq', 'value' => $echo['filter_value']]
                : $unshared[] = 'filters';
        }
        $sort = [];
        if (is_string($echo['sort_field'] ?? null)) {
            DataviewSourceDescriptor::isStableKey($echo['sort_field'])
                ? $sort[] = ['field' => $echo['sort_field'], 'direction' => $echo['sort_direction']]
                : $unshared[] = 'sort';
        }
        $search = is_string($echo['search'] ?? null) && trim($echo['search']) !== '' ? $echo['search'] : null;
        $shared = new DataviewQuery($filters, $sort, 1, (int) ($echo['per_page'] ?? 20), $search);
        if (! $shared->isValid()) {
            throw new InvalidArgumentException('cms_shared_query_invalid');
        }

        return [$shared, array_values(array_unique($unshared))];
    }
    /**
     * The link to another view carries the part of the query that view can honour and names what it
     * had to drop; the target page shows that list instead of silently losing a filter.
     *
     * @param list<'filters'|'sort'> $unshared
     * @param array<string, mixed> $echo
     * @return array{query: array<string, int|string>, dropped: list<string>}
     */
    public function viewSwitch(DataviewQuery $shared, array $unshared, mixed $type, array $echo): array
    {
        $target = is_string($type) ? DataviewViewType::tryFrom($type) : null;
        if ($target === null) {
            throw new InvalidArgumentException('cms_dataview_type_invalid');
        }
        ['query' => $kept, 'dropped' => $dropped] = (new DataviewQueryCarryOver)->carry($shared, $target);
        $supports = $target->queryCapabilities();
        foreach ($unshared as $part) {
            // The internal record order is a table detail; a view that sorts keeps it as it is.
            if ($part === 'sort' && $supports['sort'] && $target === DataviewViewType::Table) {
                continue;
            }
            $dropped[] = $part;
        }
        $params = ['per_page' => $kept->perPage];
        if ($kept->search !== null) {
            $params['search'] = $kept->search;
        }
        $map = $this->filterMap($kept);
        if ($map !== []) {
            $params['filters'] = json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $sort = $kept->sort[0] ?? (in_array('sort', $unshared, true) && $target === DataviewViewType::Table
            ? ['field' => $echo['sort_field'], 'direction' => $echo['sort_direction']] : null);
        if ($sort !== null) {
            $params['sort_field'] = $sort['field'];
            $params['sort_direction'] = $sort['direction'];
        }
        if (($echo['include_deleted'] ?? false) === true) {
            $params['include_deleted'] = 1;
        }
        $dropped = array_values(array_unique($dropped));
        if ($dropped !== []) {
            $params['dropped'] = implode(',', $dropped);
        }

        return ['query' => $params, 'dropped' => $dropped];
    }
    /**
     * The toolbar form of a shared query's filters, as chosen.
     *
     * @return array<string, array{operator: string, value?: mixed, values?: list<mixed>}>
     */
    public function filterMap(DataviewQuery $query): array
    {
        $map = [];
        foreach ($query->filters as $filter) {
            $operator = $filter['operator'];
            $map[$filter['field']] = match (true) {
                in_array($operator, DataviewQuery::RELATIVE_OPERATORS, true) => ['operator' => $operator],
                in_array($operator, ['in', 'between'], true) => ['operator' => $operator, 'values' => $filter['value']],
                default => ['operator' => $operator, 'value' => $filter['value']],
            };
        }

        return $map;
    }
    /** @return array<string, list<string>> */
    public function filterOperators(): array
    {
        return $this->lists->filterOperators();
    }
    /** @return array<string,array{operator:string,value?:string,values?:list<string>}> */
    private function queryFilters(StorageWorkbenchStructure $structure, mixed $filters): array
    {
        return $this->lists->queryFilters($structure, $filters);
    }
    private function createStructure(string $actor, string $scope, array $payload): array
    {
        $structure = $this->storage->createStructure($scope, self::jsonObject($payload['descriptor_json'] ?? null), $actor);

        return [['scope_ref' => $scope, 'structure_id' => $structure->structureId], $structure->receipt()->toArray()];
    }
    private function updateStructure(string $actor, string $scope, array $payload): array
    {
        $structure = $this->storage->updateStructure($scope, self::structureId($payload), self::positive($payload['expected_version'] ?? null), self::jsonObject($payload['descriptor_json'] ?? null), $actor);

        return [['scope_ref' => $scope, 'structure_id' => $structure->structureId], $structure->receipt()->toArray()];
    }
    private function createRecord(string $actor, string $scope, array $payload): array
    {
        $record = $this->storage->createRecord($scope, self::structureId($payload), self::jsonObject($payload['values_json'] ?? null), $actor);

        return [$this->recordRedirect($scope, $record), $record->receipt()->toArray()];
    }
    private function updateRecord(string $actor, string $scope, array $payload): array
    {
        $record = $this->storage->updateRecord($scope, self::structureId($payload), self::recordId($payload), self::positive($payload['expected_revision'] ?? null), self::jsonObject($payload['values_json'] ?? null), $actor);

        return [$this->recordRedirect($scope, $record), $record->receipt()->toArray()];
    }
    private function deleteRecord(string $actor, string $scope, array $payload): array
    {
        $record = $this->storage->archiveRecord($scope, self::structureId($payload), self::recordId($payload), self::positive($payload['expected_revision'] ?? null), $actor);

        return [$this->recordRedirect($scope, $record), $record->receipt()->toArray()];
    }
    /**
     * Archives the records selected in the list in one Storage transaction. Every record carries the
     * revision the list showed, so a record changed meanwhile rejects the whole batch.
     */
    private function bulkDeleteRecords(string $actor, string $scope, array $payload): array
    {
        if (($payload['confirmed'] ?? null) !== '1') {
            throw new InvalidArgumentException('cms_confirmation_required');
        }
        $revisions = $payload['expected_revisions'] ?? null;
        if (! is_array($revisions) || $revisions === [] || array_is_list($revisions) || count($revisions) > 100) {
            throw new InvalidArgumentException('cms_bulk_invalid');
        }
        $normalized = [];
        foreach ($revisions as $recordId => $revision) {
            $normalized[self::recordId(['record_id' => $recordId])] = self::positive($revision);
        }
        ksort($normalized, SORT_STRING);
        $structureId = self::structureId($payload);
        $records = $this->storage->bulkArchive($scope, $structureId, $normalized, $actor);

        return [['scope_ref' => $scope, 'structure_id' => $structureId], ['operation' => 'storage.workbench.record.bulk_archive',
            'records' => array_map(static fn (StorageWorkbenchRecord $record): array => $record->receipt()->toArray(), $records)]];
    }
    /**
     * "For all": archives every current record that matches the list filters and search the person
     * confirmed. The server runs the query again and refuses when the count differs from the confirmed
     * one, so records added or changed meanwhile are never archived unseen; all records go in one
     * Storage transaction with their current revisions.
     */
    private function bulkDeleteMatching(string $actor, string $scope, array $payload): array
    {
        if (($payload['confirmed'] ?? null) !== '1') {
            throw new InvalidArgumentException('cms_confirmation_required');
        }
        $expected = self::positive($payload['expected_count'] ?? null);
        if ($expected > self::MAX_BULK_MATCHING) {
            throw new InvalidArgumentException('cms_bulk_too_many');
        }
        $filters = $payload['filters'] ?? null;
        if (is_string($filters)) {
            try {
                $filters = $filters === '' ? [] : (strlen($filters) <= 8192 ? json_decode($filters, true, 6, JSON_THROW_ON_ERROR) : null);
            } catch (\JsonException) {
                $filters = null;
            }
        }
        $structureId = self::structureId($payload);
        $structure = $this->storage->readStructure($scope, $structureId, $actor);
        $query = [
            'filters' => $this->queryFilters($structure, $filters),
            'search' => self::nullableQueryString($payload['search'] ?? null, 100),
        ];
        $revisions = [];
        $matched = null;
        for ($page = 1; $page <= intdiv(self::MAX_BULK_MATCHING, 100); $page++) {
            $result = $this->storage->listRecords(new StorageWorkbenchRecordQuery(
                $scope, $structureId, filters: $query['filters'], search: $query['search'],
                sort: [['field' => '_record_id', 'direction' => 'asc']], limit: 100, page: $page,
                // The person confirmed a number: it is compared with an exact count.
                countMode: StorageWorkbenchCountMode::Exact,
            ), $actor);
            $matched ??= $result->matchedCount;
            if ($matched !== $expected) {
                throw new InvalidArgumentException('cms_bulk_count_changed');
            }
            foreach ($result->items as $record) {
                $revisions[$record->recordId] = $record->revision;
            }
            if (count($revisions) >= $matched || $result->items === []) {
                break;
            }
        }
        if ($revisions === [] || count($revisions) !== $expected) {
            throw new InvalidArgumentException('cms_bulk_count_changed');
        }
        ksort($revisions, SORT_STRING);
        $records = $this->storage->bulkArchive($scope, $structureId, $revisions, $actor);

        return [['scope_ref' => $scope, 'structure_id' => $structureId], ['operation' => 'storage.workbench.record.bulk_archive',
            'records' => array_map(static fn (StorageWorkbenchRecord $record): array => $record->receipt()->toArray(), $records)]];
    }
    private function restoreRecord(string $actor, string $scope, array $payload): array
    {
        $record = $this->storage->restoreRecord($scope, self::structureId($payload), self::recordId($payload), self::positive($payload['expected_revision'] ?? null), $actor);

        return [$this->recordRedirect($scope, $record), $record->receipt()->toArray()];
    }
    private function attachFile(string $actor, string $scope, array $payload): array
    {
        $this->authorizer->assertAllowed($actor, 'filesystem.file.attach');
        $logicalRef = self::id($payload['logical_ref'] ?? null, '/^[a-f0-9]{8}-[a-f0-9-]{27}$/i');
        $field = self::id($payload['field_key'] ?? null, '/^[a-z][a-z0-9_]{0,63}$/');
        $inspection = $this->fileInspector->inspect($logicalRef);
        if (! $inspection->isContentAttachable() || ! $this->scopes->fileBelongsToScope($logicalRef, $scope)) {
            throw new InvalidArgumentException('cms_file_unavailable');
        }
        $structureId = self::structureId($payload);
        $recordId = self::recordId($payload);
        $structure = $this->storage->readStructure($scope, $structureId, $actor);
        $definition = array_values(array_filter($structure->fields, static fn (array $candidate): bool => ($candidate['key'] ?? null) === $field));
        if (count($definition) !== 1 || ($definition[0]['type'] ?? null) !== 'file') {
            throw new InvalidArgumentException('cms_file_field_invalid');
        }
        $current = $this->storage->readRecord($scope, $structureId, $recordId, $actor);
        $values = $current->values;
        $values[$field] = $logicalRef;
        $record = $this->storage->updateRecord($scope, $structureId, $recordId, self::positive($payload['expected_revision'] ?? null), $values, $actor);

        return [$this->recordRedirect($scope, $record), $record->receipt()->toArray()];
    }
    public static function structureId(array $payload): string
    {
        return self::id($payload['structure_id'] ?? null, '/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/');
    }
    public static function recordId(array $payload): string
    {
        return self::id($payload['record_id'] ?? null, '/^record-[a-f0-9]{32}$/');
    }
    public static function id(mixed $value, string $pattern): string
    {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('cms_identity_invalid');
        }

        return $value;
    }
    public static function nullableId(mixed $value, string $pattern): ?string
    {
        return $value === null || $value === '' ? null : self::id($value, $pattern);
    }
    public static function positive(mixed $value): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('cms_revision_invalid');
        }

        return $value;
    }
    public static function perPage(mixed $value): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1 || $value > 100) {
            throw new InvalidArgumentException('cms_per_page_invalid');
        }

        return $value;
    }
    public static function nullableQueryString(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('cms_query_value_invalid');
        }

        return $value;
    }
    public static function jsonObject(mixed $value): array
    {
        if (! is_string($value) || strlen($value) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('cms_json_invalid');
        }
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('cms_json_invalid');
        }

        return $decoded;
    }
    public function structure(StorageWorkbenchStructure $structure): array
    {
        return ['structure_id' => $structure->structureId, 'version' => $structure->version, 'schema_version' => $structure->schema->version, 'label' => $structure->label, 'fields' => $structure->fields];
    }
    /**
     * The projection carries the actions this person may take on this record, so a view never has to
     * decide permissions itself: an archived record offers restore, a live one edit, view and delete.
     *
     * @param array<string,bool> $permissions
     * @return list<string>
     */
    private function recordActions(StorageWorkbenchRecord $record, array $permissions): array
    {
        $deleted = $record->state !== 'active';
        $allowed = $deleted
            ? ['record.restore' => 'record.restore']
            : ['record.view' => 'record.update', 'record.edit' => 'record.update', 'record.delete' => 'record.delete'];
        $actions = [];
        foreach ($allowed as $action => $permission) {
            if (($permissions[$permission] ?? false) === true || $action === 'record.view') {
                $actions[] = $action;
            }
        }

        return $actions;
    }
    /** @param array<string,bool> $permissions */
    public function record(StorageWorkbenchRecord $record, array $permissions = []): array
    {
        return ['structure_id' => $record->structureId, 'record_id' => $record->recordId, 'revision' => $record->revision,
            'schema_version' => $record->schemaVersion, 'deleted' => $record->state !== 'active', 'values' => $record->values,
            'actions' => $this->recordActions($record, $permissions)];
    }
    private function recordRedirect(string $scope, StorageWorkbenchRecord $record): array
    {
        return ['scope_ref' => $scope, 'structure_id' => $record->structureId, 'record_id' => $record->recordId];
    }
}
