<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Dataview\Enums\DataviewViewType;
use Larena\Dataview\Runtime\DataviewRoleBinder;
use Larena\Setting\Contracts\RevisionedSettingValue;
use Larena\Setting\Contracts\SettingContext;
use Larena\Setting\Contracts\SettingKey;
use Larena\Setting\Contracts\SettingRuntime;
use Larena\Setting\Contracts\SettingSchemaField;
use Larena\Setting\Contracts\SettingSchemaPack;
use Larena\Setting\Contracts\SettingValue;
use Larena\Setting\Enums\SettingScopeType;
use Larena\Setting\Enums\SettingValueKind;
use Larena\Setting\Exceptions\SettingRevisionConflict;
use Larena\Setting\Persistence\DatabaseSettingValueRepository;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Throwable;

/**
 * Column settings and saved views of a records list of one Storage structure, whichever admin
 * screen shows that list. Column settings resolve in layers (the package default, what an
 * administrator set for everyone, the person's own choice), each with its own revision; saved
 * views are whole objects (query, field roles, column layout, settings) with a revision each, kept
 * either as the person's own or, by an administrator, for everyone in the scope. A view keeps its id
 * for life, so it can be renamed.
 *
 * Both are kept per scope and structure, not per screen: the Storage section and the Larena CMS
 * list of the same structure share one layout and one list of saved views.
 */
final readonly class DataviewListPreferences
{
    public const NAMESPACE = 'larena_dataview';

    /**
     * Column settings and saved views for everyone need this right. A person's own saved views need
     * only the right to read the structure they belong to.
     */
    public const SHARED_RIGHT = 'storage.workbench.structure.update';

    public const SAVED_VIEW_ID = '/^saved-view-[a-f0-9]{24}$/';

    public const SAVED_VIEW_LIMIT = 20;

    /** The per-page sizes a saved view may keep. */
    public const PER_PAGE = [10, 20, 50, 100];

    public const COLUMN_OPTIONS = ['visible', 'order', 'width', 'min_width', 'priority', 'sticky', 'pinned'];

    private const LEGACY_SAVED_VIEWS_KEY = 'saved_views';

    /** The saved views of lists. Keys carry no version: until 2026-10-19 this list was kept under `saved_views.v2`. */
    private const SAVED_VIEWS_KEY = 'list_views';

    /** Where the list was kept before; a migration moves it, and a list not yet moved is still read from here. */
    private const PREVIOUS_SAVED_VIEWS_KEY = 'saved_views.v2';

    public function __construct(
        private ActorOperationAuthorizer $authorizer,
        private StorageWorkbench $storage,
        private SettingRuntime $settings,
        private DatabaseSettingValueRepository $values,
    ) {
        $this->settings->registerSchemaPack(new SettingSchemaPack(self::NAMESPACE, 'larena/storage', [
            new SettingSchemaField(
                SettingKey::make(self::NAMESPACE, self::LEGACY_SAVED_VIEWS_KEY),
                SettingValueKind::Array,
                SettingScopeType::User,
                'larena.dataview.saved_views',
                'property.array.readonly',
                new SettingValue(SettingValueKind::Array, []),
            ),
        ]));
    }

    public function maySaveShared(string $actor): bool
    {
        try {
            $this->authorizer->assertAllowed($actor, self::SHARED_RIGHT);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The column settings the list opens with. An open saved view carries its own layout; it takes
     * the place of the person's own columns, and a change is saved into that view.
     *
     * @param  array<string,mixed>|null  $savedView
     * @return array{revision:int,columns:array<string,array<string,mixed>>,personal:array{revision:int,columns:array<string,mixed>},shared:array{revision:int,columns:array<string,mixed>},target:array<string,mixed>}
     */
    public function columns(string $actor, string $scope, StorageWorkbenchStructure $structure, ?array $savedView = null): array
    {
        $key = $this->columnsKey($scope, $structure->structureId);
        $shared = $this->values->revisionedValue(self::NAMESPACE, $key, 'system', $scope);
        $stored = $this->values->revisionedValue(self::NAMESPACE, $key, 'user', $actor);
        $sharedColumns = $this->knownColumns(is_array($shared->value) ? $shared->value : [], $structure);
        $savedView = ($savedView['structure_id'] ?? null) === $structure->structureId ? $savedView : null;
        $personalColumns = $this->knownColumns($savedView !== null
            ? $savedView['layout']['columns']
            : (is_array($stored->value) ? $stored->value : []), $structure);
        $columns = $personalColumns;
        // The person's own values win over the shared layer, pinning included (data view port 1.4.0):
        // the shared layer only supplies defaults for what the person has not set.
        foreach ($sharedColumns as $field => $options) {
            $columns[$field] = is_array($columns[$field] ?? null) ? $columns[$field] + $options : $options;
        }
        if (count($structure->fields) > 9) {
            // A wide collection opens with a readable set: long text, files and links hidden and at
            // most nine columns shown; the rest stay in settings. A column keeps this default until the
            // person shows or hides it, so saving an order or a width does not reveal every column.
            $untouched = $columns === [];
            $fields = $structure->fields;
            usort($fields, static fn (array $left, array $right): int => ($left['position'] ?? 0) <=> ($right['position'] ?? 0));
            $shown = 0;
            foreach ($fields as $field) {
                $visible = ! in_array($field['type'] ?? null, ['text', 'file', 'relation'], true) && $shown < 9;
                $shown += $visible ? 1 : 0;
                $fieldKey = (string) $field['key'];
                if (! array_key_exists('visible', is_array($columns[$fieldKey] ?? null) ? $columns[$fieldKey] : [])) {
                    $columns[$fieldKey] = ['visible' => $visible] + (is_array($columns[$fieldKey] ?? null) ? $columns[$fieldKey] : []);
                }
            }
            // The leading name column opens the record, so it gets room for a readable name.
            $lead = (new DataviewRoleBinder)->bind(DataviewViewType::Table, array_map('strval', array_column($structure->fields, 'type', 'key')), ['title' => 'title'])['bindings']['title'] ?? null;
            if ($untouched && $lead !== null && ($columns[$lead]['visible'] ?? false) === true) {
                $columns[$lead]['width'] = '200px';
            }
        }

        return [
            'revision' => $stored->revision,
            'columns' => $columns,
            'personal' => ['revision' => $stored->revision, 'columns' => $personalColumns],
            'shared' => ['revision' => $shared->revision, 'columns' => $sharedColumns],
            // Where a column change is saved: into the open saved view, or into the person's own layer.
            'target' => $savedView === null
                ? ['kind' => 'personal', 'revision' => $stored->revision]
                : ['kind' => 'saved_view', 'saved_view_id' => $savedView['saved_view_id'], 'revision' => $savedView['revision']],
        ];
    }

    /**
     * Save the person's columns, or the columns for everyone, against the revision of that layer.
     * Empty columns reset the layer; a reset is a write like any other, never a deletion.
     */
    public function saveColumns(string $actor, string $scope, string $structureId, mixed $baseRevision, mixed $columns, bool $shared = false): void
    {
        // Reading the real structure enforces its access boundary.
        $structure = $this->storage->readStructure($scope, $this->structureId($structureId), $actor);
        // The list settings form posts the revision as text; the port sends a number.
        if (is_string($baseRevision) && preg_match('/\A(0|[1-9][0-9]{0,9})\z/', $baseRevision) === 1) {
            $baseRevision = (int) $baseRevision;
        }
        if (! is_int($baseRevision) || $baseRevision < 0) {
            throw new InvalidArgumentException('dataview_list_columns_invalid');
        }
        $columns = self::withoutResetWidths($columns);
        $this->assertColumns($structure, $columns);
        if ($shared) {
            // Settings for everyone are a different operation with a different permission; the subject
            // of a personal setting is the session actor and is never taken from the request.
            $this->authorizer->assertAllowed($actor, self::SHARED_RIGHT);
        }
        $this->values->compareAndSet(
            self::NAMESPACE,
            $this->columnsKey($scope, $structure->structureId),
            $shared ? 'system' : 'user',
            $shared ? $scope : $actor,
            $columns,
            $baseRevision,
        );
    }

    /**
     * A column edge reset with a double click or Enter sends {width: null}: no stored width, the column's own
     * again. The null is dropped before the settings are checked and kept.
     */
    private static function withoutResetWidths(mixed $columns): mixed
    {
        if (! is_array($columns)) {
            return $columns;
        }
        foreach ($columns as $key => $options) {
            if (is_array($options)) {
                foreach (['width', 'min_width'] as $option) {
                    if (array_key_exists($option, $options) && $options[$option] === null) {
                        unset($options[$option]);
                    }
                }
                $columns[$key] = $options;
            }
        }

        return $columns;
    }

    /** @phpstan-assert array<string, array<string, mixed>> $columns */
    public function assertColumns(StorageWorkbenchStructure $structure, mixed $columns): void
    {
        if (! is_array($columns) || ($columns !== [] && array_is_list($columns)) || count($columns) > count($structure->fields)) {
            throw new InvalidArgumentException('dataview_list_columns_invalid');
        }
        $known = array_column($structure->fields, 'key');
        foreach ($columns as $key => $options) {
            if (! in_array($key, $known, true) || ! is_array($options)
                || array_diff(array_keys($options), self::COLUMN_OPTIONS) !== []) {
                throw new InvalidArgumentException('dataview_list_column_invalid');
            }
            if ((array_key_exists('visible', $options) && ! is_bool($options['visible']))
                // The order is relative: a column moved before the first one gets a negative order.
                || (array_key_exists('order', $options)
                    && (! is_int($options['order']) || $options['order'] < -10000 || $options['order'] > 10000))
                || (array_key_exists('width', $options)
                    && (! is_string($options['width']) || preg_match('/^[1-9][0-9]{1,3}px$/', $options['width']) !== 1))
                || (array_key_exists('min_width', $options)
                    && (! is_string($options['min_width']) || preg_match('/^[1-9][0-9]{1,3}px$/', $options['min_width']) !== 1))
                || (array_key_exists('priority', $options) && (! is_int($options['priority']) || $options['priority'] < 0))
                || (array_key_exists('sticky', $options) && ! in_array($options['sticky'], ['none', 'start', 'end'], true))
                || (array_key_exists('pinned', $options) && ! is_bool($options['pinned']))) {
                throw new InvalidArgumentException('dataview_list_column_option_invalid');
            }
        }
    }

    /**
     * The person's saved views of every structure in the scope, with the revision of the list. The
     * list is written with compare-and-set, so two tabs cannot silently overwrite each other.
     *
     * @return array{revision:int, views:list<array<string, mixed>>}
     */
    public function savedViews(string $actor, string $scope): array
    {
        $stored = $this->values->revisionedValue(self::NAMESPACE, self::SAVED_VIEWS_KEY, 'user', $actor);

        return $stored->revision > 0 ? $this->storedViews($stored) : ($this->previousViews('user', $actor) ?? $this->legacyViews($actor, $scope));
    }

    /**
     * The saved views an administrator kept for everyone in the scope, with the revision of that list.
     *
     * @return array{revision:int, views:list<array<string, mixed>>}
     */
    public function sharedViews(string $scope): array
    {
        $stored = $this->values->revisionedValue(self::NAMESPACE, self::SAVED_VIEWS_KEY, 'system', $scope);

        return $stored->revision > 0 ? $this->storedViews($stored) : ($this->previousViews('system', $scope) ?? ['revision' => 0, 'views' => []]);
    }

    /**
     * Every saved view of one structure the person may open: the shared ones first, then their own,
     * each with its level ("shared" or "personal").
     *
     * @return list<array<string, mixed>>
     */
    public function structureViews(string $actor, string $scope, string $structureId): array
    {
        $views = [];
        foreach (['shared' => $this->sharedViews($scope)['views'], 'personal' => $this->savedViews($actor, $scope)['views']] as $level => $list) {
            foreach ($list as $view) {
                if ($view['structure_id'] === $structureId) {
                    $views[] = $view + ['level' => $level];
                }
            }
        }

        return $views;
    }

    /**
     * A list still kept under its previous key: read as it is, written under the new key on the next change
     * (revision 0, so that first write creates it there).
     *
     * @return array{revision:int, views:list<array<string, mixed>>}|null
     */
    private function previousViews(string $scope, string $scopeId): ?array
    {
        $previous = $this->values->revisionedValue(self::NAMESPACE, self::PREVIOUS_SAVED_VIEWS_KEY, $scope, $scopeId);

        return $previous->revision > 0 ? ['revision' => 0, 'views' => $this->storedViews($previous)['views']] : null;
    }

    /** @return array{revision:int, views:list<array<string, mixed>>} */
    private function storedViews(RevisionedSettingValue $stored): array
    {
        $views = $stored->value;
        if (! is_array($views) || ! array_is_list($views) || count($views) > self::SAVED_VIEW_LIMIT) {
            throw new InvalidArgumentException('dataview_list_saved_views_invalid');
        }
        foreach ($views as $view) {
            if (! $this->validSavedViewObject($view)) {
                throw new InvalidArgumentException('dataview_list_saved_view_invalid');
            }
        }

        return ['revision' => $stored->revision, 'views' => $views];
    }

    /**
     * Views saved before they carried a layout are read as objects here and written in the new form
     * on the next change. Their layout starts from the columns the person had arranged.
     *
     * @return array{revision:int, views:list<array<string, mixed>>}
     */
    private function legacyViews(string $actor, string $scope): array
    {
        $views = [];
        foreach ($this->legacySavedViews($actor) as $legacy) {
            $columns = $this->values->revisionedValue(
                self::NAMESPACE, $this->columnsKey($scope, (string) $legacy['structure_id']), 'user', $actor,
            )->value;
            $views[] = $legacy + [
                'revision' => 1,
                'roles' => [],
                'layout' => ['columns' => is_array($columns) && ! array_is_list($columns) ? $columns : []],
                'settings' => [],
            ];
        }

        return ['revision' => 0, 'views' => $views];
    }

    /**
     * @param  list<array<string, mixed>>  $views
     * @return array<string, mixed>|null
     */
    public function savedView(array $views, ?string $savedViewId): ?array
    {
        foreach ($savedViewId === null ? [] : $views as $view) {
            if (hash_equals((string) $view['saved_view_id'], $savedViewId)) {
                return $view;
            }
        }

        return null;
    }

    /**
     * Store the current query as a saved view, or write a stored one against its revision. A view
     * named by its id is that view, renamed when the name changed; without an id, saving under a
     * name the list already holds writes that view, and a new name makes a new view with an id of
     * its own. The person's own views need only the right to read the structure; views for
     * everyone need {@see self::SHARED_RIGHT}.
     *
     * @param  array<string, mixed>  $payload  structure_id, name, query and optionally saved_view_id,
     *                                         level ("personal" or "shared"), loaded_saved_view_id, base_revision, roles, layout, settings
     * @param  bool  $stored  the write is meant for a stored view: one deleted meanwhile is refused,
     *                        not created again
     */
    public function saveView(string $actor, string $scope, array $payload, bool $stored = false): string
    {
        $level = $payload['level'] ?? 'personal';
        if (! in_array($level, ['personal', 'shared'], true)) {
            throw new InvalidArgumentException('dataview_list_saved_view_level_invalid');
        }
        if ($level === 'shared') {
            $this->authorizer->assertAllowed($actor, self::SHARED_RIGHT);
        }
        $structureId = $this->structureId($payload['structure_id'] ?? null);
        // Reading the real structure enforces its access boundary.
        $structure = $this->storage->readStructure($scope, $structureId, $actor);
        $name = $this->nullableQueryString($payload['name'] ?? null, 80);
        if ($name === null || trim($name) === '') {
            throw new InvalidArgumentException('dataview_list_saved_view_name_invalid');
        }
        $name = trim($name);
        $query = $this->savedViewQuery($structure, is_array($payload['query'] ?? null) ? $payload['query'] : []);
        ['revision' => $listRevision, 'views' => $views] = $level === 'shared'
            ? $this->sharedViews($scope) : $this->savedViews($actor, $scope);
        $named = null;
        foreach ($views as $view) {
            if ($view['structure_id'] === $structureId && mb_strtolower($view['name']) === mb_strtolower($name)) {
                $named = $view;
            }
        }
        $savedViewId = $this->nullableId($payload['saved_view_id'] ?? null, self::SAVED_VIEW_ID);
        if ($savedViewId !== null) {
            $existing = $this->savedView($views, $savedViewId);
            if ($existing === null || $existing['structure_id'] !== $structureId) {
                throw new InvalidArgumentException('dataview_list_saved_view_unknown');
            }
            // A rename may not take the name of another view of the list.
            if ($named !== null && $named['saved_view_id'] !== $savedViewId) {
                throw new InvalidArgumentException('dataview_list_saved_view_name_taken');
            }
        } else {
            $existing = $named;
            if ($existing === null && $stored) {
                throw new InvalidArgumentException('dataview_list_saved_view_unknown');
            }
            $savedViewId = $existing['saved_view_id'] ?? 'saved-view-'.bin2hex(random_bytes(12));
        }
        if ($existing !== null) {
            $baseRevision = $payload['base_revision'] ?? null;
            if (! is_int($baseRevision) || $baseRevision < 1) {
                throw new InvalidArgumentException('dataview_list_saved_view_revision_invalid');
            }
            if ($existing['revision'] !== $baseRevision) {
                throw new SettingRevisionConflict($baseRevision, $existing['revision']);
            }
        }
        // The layout is saved with the view: what the person sees now is what the view reopens with.
        $open = $this->columns($actor, $scope, $structure, $this->savedView(
            $this->structureViews($actor, $scope, $structureId),
            $this->nullableId($payload['loaded_saved_view_id'] ?? null, self::SAVED_VIEW_ID),
        ));
        $roles = array_key_exists('roles', $payload) ? $payload['roles'] : ($existing['roles'] ?? []);
        if (! is_array($roles) || ($roles !== [] && array_is_list($roles))
            || count(array_filter($roles, static fn (mixed $field, mixed $role): bool => ! is_string($role) || ! is_string($field), ARRAY_FILTER_USE_BOTH)) > 0
            || (new DataviewRoleBinder)->problems(DataviewViewType::Table,
                array_map('strval', array_column($structure->fields, 'type', 'key')), $roles) !== []) {
            throw new InvalidArgumentException('dataview_list_saved_view_roles_invalid');
        }
        $layout = array_key_exists('layout', $payload) ? $payload['layout']
            : (($existing !== null && ! isset($payload['loaded_saved_view_id']))
                ? $existing['layout'] : ['columns' => $open['personal']['columns']]);
        if (! is_array($layout) || array_keys($layout) !== ['columns'] || ! is_array($layout['columns'])) {
            throw new InvalidArgumentException('dataview_list_saved_view_layout_invalid');
        }
        $layout['columns'] = self::withoutResetWidths($layout['columns']);
        $this->assertColumns($structure, $layout['columns']);
        $settings = array_key_exists('settings', $payload) ? $payload['settings'] : ($existing['settings'] ?? []);
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))
            || strlen(json_encode($settings, JSON_THROW_ON_ERROR)) > 8192) {
            throw new InvalidArgumentException('dataview_list_saved_view_settings_invalid');
        }
        $written = [
            'saved_view_id' => $savedViewId,
            'structure_id' => $structureId,
            'name' => $name,
            'query' => $query,
            'revision' => ($existing['revision'] ?? 0) + 1,
            'roles' => $roles,
            'layout' => $layout,
            'settings' => $settings,
        ];
        // A view keeps its place in the list when it is written again.
        $replaced = false;
        foreach ($views as $index => $view) {
            if ($view['saved_view_id'] === $savedViewId) {
                $views[$index] = $written;
                $replaced = true;
            }
        }
        if (! $replaced) {
            $views[] = $written;
        }
        $this->writeSavedViews($level, $actor, $scope, $listRevision, $views);

        return $savedViewId;
    }

    /**
     * Column changes made while a saved view is open belong to that view. The request names the
     * revision of the view it changed; a view changed meanwhile rejects the write. A view for
     * everyone is changed only with {@see self::SHARED_RIGHT}.
     *
     * @return array{structure_id:string,saved_view_id:string}
     */
    public function saveViewLayout(string $actor, string $scope, mixed $savedViewId, mixed $baseRevision, mixed $columns): array
    {
        $savedViewId = $this->nullableId($savedViewId, self::SAVED_VIEW_ID);
        if ($savedViewId === null || ! is_int($baseRevision) || $baseRevision < 1) {
            throw new InvalidArgumentException('dataview_list_saved_view_layout_invalid');
        }
        [$level, $listRevision, $views] = $this->listHolding($actor, $scope, $savedViewId);
        $view = $this->savedView($views, $savedViewId);
        if ($view['revision'] !== $baseRevision) {
            throw new SettingRevisionConflict($baseRevision, $view['revision']);
        }
        $structure = $this->storage->readStructure($scope, (string) $view['structure_id'], $actor);
        $columns = self::withoutResetWidths($columns);
        $this->assertColumns($structure, $columns);
        foreach ($views as $index => $candidate) {
            if ($candidate['saved_view_id'] === $savedViewId) {
                $views[$index]['layout'] = ['columns' => $columns];
                $views[$index]['revision'] = $baseRevision + 1;
            }
        }
        $this->writeSavedViews($level, $actor, $scope, $listRevision, $views);

        return ['structure_id' => (string) $view['structure_id'], 'saved_view_id' => $savedViewId];
    }

    /**
     * Dropping a saved view is a write like any other: it names the view it removes, refuses an
     * unknown one instead of silently doing nothing, and leaves the remaining views untouched.
     */
    public function deleteView(string $actor, string $scope, mixed $savedViewId): void
    {
        $savedViewId = $this->nullableId($savedViewId, self::SAVED_VIEW_ID);
        if ($savedViewId === null) {
            throw new InvalidArgumentException('dataview_list_saved_view_unknown');
        }
        [$level, $listRevision, $views] = $this->listHolding($actor, $scope, $savedViewId);
        $view = $this->savedView($views, $savedViewId);
        // Reading the structure keeps a person who lost access to it from changing its views.
        $this->storage->readStructure($scope, (string) $view['structure_id'], $actor);
        $this->writeSavedViews($level, $actor, $scope, $listRevision, array_values(array_filter(
            $views,
            static fn (array $candidate): bool => ! hash_equals((string) $candidate['saved_view_id'], $savedViewId),
        )));
    }

    /**
     * Which list holds a saved view: the person's own, or the one for everyone, which only an
     * administrator changes.
     *
     * @return array{0:string, 1:int, 2:list<array<string, mixed>>}
     */
    private function listHolding(string $actor, string $scope, string $savedViewId): array
    {
        $personal = $this->savedViews($actor, $scope);
        if ($this->savedView($personal['views'], $savedViewId) !== null) {
            return ['personal', $personal['revision'], $personal['views']];
        }
        $shared = $this->sharedViews($scope);
        if ($this->savedView($shared['views'], $savedViewId) === null) {
            throw new InvalidArgumentException('dataview_list_saved_view_unknown');
        }
        $this->authorizer->assertAllowed($actor, self::SHARED_RIGHT);

        return ['shared', $shared['revision'], $shared['views']];
    }

    /**
     * The filter operators each field type offers in the list toolbar: Storage's own, and for dates
     * the relative ranges resolved when the list is read.
     *
     * @return array<string, list<string>>
     */
    public function filterOperators(): array
    {
        return DataviewFilterQuery::operators($this->storage->filterOperators());
    }

    /**
     * Filters from the list toolbar: up to 8 fields, each with one operator. The operators follow the
     * field type; relative date ranges are resolved here, and every value is validated again by Storage.
     *
     * @return array<string,array{operator:string,value?:string,values?:list<string>}>
     */
    public function queryFilters(StorageWorkbenchStructure $structure, mixed $filters): array
    {
        if ($filters === null || $filters === []) {
            return [];
        }
        if (! is_array($filters) || array_is_list($filters) || count($filters) > 8) {
            throw new InvalidArgumentException('dataview_list_filter_invalid');
        }
        $types = array_column($structure->fields, 'type', 'key');
        $operators = $this->filterOperators();
        $normalized = [];
        foreach ($filters as $field => $filter) {
            $type = is_string($field) ? ($types[$field] ?? null) : null;
            $operator = is_array($filter) ? ($filter['operator'] ?? null) : null;
            if ($type === null || ! is_string($operator) || ! in_array($operator, $operators[$type] ?? [], true)) {
                throw new InvalidArgumentException('dataview_list_filter_invalid');
            }
            if (in_array($operator, ['today', 'week', 'month'], true)) {
                $today = Date::now()->startOfDay();
                [$from, $to] = match ($operator) {
                    'today' => [$today, $today->copy()],
                    'week' => [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()],
                    'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
                };
                $normalized[$field] = $type === 'datetime'
                    ? ['operator' => 'between', 'values' => [$from->format('Y-m-d').'T00:00:00', $to->format('Y-m-d').'T23:59:59']]
                    : ['operator' => 'between', 'values' => [$from->format('Y-m-d'), $to->format('Y-m-d')]];

                continue;
            }
            if (in_array($operator, ['in', 'between'], true)) {
                $values = $filter['values'] ?? null;
                if (! is_array($values) || ! array_is_list($values) || $values === [] || count($values) > 100
                    || ($operator === 'between' && count($values) !== 2)) {
                    throw new InvalidArgumentException('dataview_list_filter_invalid');
                }
                $normalized[$field] = ['operator' => $operator, 'values' => array_map(fn (mixed $value): string => $this->filterValue($value), $values)];

                continue;
            }
            $normalized[$field] = ['operator' => $operator, 'value' => $this->filterValue($filter['value'] ?? null)];
        }

        return $normalized;
    }

    private function columnsKey(string $scope, string $structureId): string
    {
        return 'columns.'.hash('sha256', $scope."\0".$structureId);
    }

    /**
     * A removed field must not make an existing collection inaccessible.
     *
     * @param  array<mixed>  $stored
     * @return array<string, mixed>
     */
    private function knownColumns(array $stored, StorageWorkbenchStructure $structure): array
    {
        $known = array_column($structure->fields, 'key');

        return array_filter($stored, static fn (mixed $key): bool => in_array($key, $known, true), ARRAY_FILTER_USE_KEY);
    }

    /** @param list<array<string, mixed>> $views */
    private function writeSavedViews(string $level, string $actor, string $scope, int $expectedRevision, array $views): void
    {
        if (count($views) > self::SAVED_VIEW_LIMIT) {
            throw new InvalidArgumentException('dataview_list_saved_view_limit');
        }
        // A view the list could not read back would make every list of the person unreadable.
        foreach ($views as $view) {
            if (! $this->validSavedViewObject($view)) {
                throw new InvalidArgumentException('dataview_list_saved_view_invalid');
            }
        }
        $this->values->compareAndSet(
            self::NAMESPACE, self::SAVED_VIEWS_KEY,
            $level === 'shared' ? 'system' : 'user', $level === 'shared' ? $scope : $actor,
            $views, $expectedRevision,
        );
    }

    /** @return list<array<string, mixed>> */
    private function legacySavedViews(string $actor): array
    {
        $resolved = $this->settings->resolve(
            SettingKey::make(self::NAMESPACE, self::LEGACY_SAVED_VIEWS_KEY),
            new SettingContext(SettingScopeType::User, $actor),
        );
        $value = $resolved->effectiveValue?->value;
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::SAVED_VIEW_LIMIT) {
            throw new InvalidArgumentException('dataview_list_saved_views_invalid');
        }
        $views = [];
        foreach ($value as $view) {
            if (! $this->validSavedView($view)) {
                throw new InvalidArgumentException('dataview_list_saved_view_invalid');
            }
            $views[] = $view;
        }

        return $views;
    }

    private function validSavedViewObject(mixed $view): bool
    {
        if (! is_array($view) || array_is_list($view)) {
            return false;
        }
        $keys = array_keys($view);
        sort($keys);
        if ($keys !== ['layout', 'name', 'query', 'revision', 'roles', 'saved_view_id', 'settings', 'structure_id']
            || ! is_int($view['revision']) || $view['revision'] < 1
            || ! is_array($view['roles']) || ! is_array($view['settings'])
            || ! is_array($view['layout']) || array_keys($view['layout']) !== ['columns'] || ! is_array($view['layout']['columns'])) {
            return false;
        }

        return $this->validSavedView(array_intersect_key($view, array_flip(['name', 'query', 'saved_view_id', 'structure_id'])));
    }

    private function validSavedView(mixed $view): bool
    {
        if (! is_array($view) || array_is_list($view)) {
            return false;
        }
        $keys = array_keys($view);
        sort($keys);
        if ($keys !== ['name', 'query', 'saved_view_id', 'structure_id']) {
            return false;
        }
        $query = $view['query'] ?? null;
        if (! is_array($query) || array_is_list($query)) {
            return false;
        }
        $queryKeys = array_keys($query);
        sort($queryKeys);
        // Views saved before toolbar filters have no "filters" key and stay valid.
        if (array_values(array_diff($queryKeys, ['filters'])) !== ['dataview_id', 'filter_field', 'filter_value', 'include_deleted', 'per_page', 'search', 'sort_direction', 'sort_field']
            || (array_key_exists('filters', $query) && (! is_array($query['filters']) || count($query['filters']) > 8
                || ($query['filters'] !== [] && array_is_list($query['filters']))))) {
            return false;
        }
        $sortField = $query['sort_field'];
        $sortDirection = $query['sort_direction'];

        return is_string($view['saved_view_id']) && preg_match(self::SAVED_VIEW_ID, $view['saved_view_id']) === 1
            && is_string($view['structure_id']) && preg_match('/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/', $view['structure_id']) === 1
            && is_string($view['name']) && trim($view['name']) !== '' && strlen($view['name']) <= 80
            && ($query['dataview_id'] === null || (is_string($query['dataview_id']) && preg_match('/^workbench\.[a-z][a-z0-9_.:-]{0,109}\.(?:table|cards|calendar|kanban|gantt|tree)$/', $query['dataview_id']) === 1))
            && ($query['search'] === null || (is_string($query['search']) && strlen($query['search']) <= 100))
            && ($query['filter_field'] === null || (is_string($query['filter_field']) && preg_match('/^[a-z][a-z0-9_.-]{0,119}$/', $query['filter_field']) === 1))
            && ($query['filter_value'] === null || (is_string($query['filter_value']) && strlen($query['filter_value']) <= 1000))
            && (($sortField === null && $sortDirection === null)
                || (is_string($sortField) && preg_match('/^(?:_[a-z][a-z0-9_]{0,30}|[a-z][a-z0-9_.-]{0,119})$/', $sortField) === 1
                    && in_array($sortDirection, ['asc', 'desc'], true)))
            && is_int($query['per_page']) && in_array($query['per_page'], self::PER_PAGE, true)
            && is_bool($query['include_deleted']);
    }

    /**
     * The query a saved view keeps, in the shape the lists read back.
     *
     * @param  array<mixed>  $query
     * @return array<string, mixed>
     */
    private function savedViewQuery(StorageWorkbenchStructure $structure, array $query): array
    {
        if (array_diff(array_keys($query), [
            'dataview_id', 'search', 'filters', 'filter_field', 'filter_value',
            'sort_field', 'sort_direction', 'per_page', 'include_deleted',
        ]) !== []) {
            throw new InvalidArgumentException('dataview_list_saved_view_query_keys_invalid');
        }
        $fieldKeys = array_fill_keys(array_map(static fn (array $field): string => (string) $field['key'], $structure->fields), true);
        $filterField = $this->nullableId($query['filter_field'] ?? null, '/^[a-z][a-z0-9_.-]{0,119}$/');
        if ($filterField !== null && ! isset($fieldKeys[$filterField])) {
            throw new InvalidArgumentException('dataview_list_saved_view_filter_invalid');
        }
        $sortField = $this->nullableId($query['sort_field'] ?? null, '/^(?:_[a-z][a-z0-9_]{0,30}|[a-z][a-z0-9_.-]{0,119})$/');
        $sortDirection = $this->nullableQueryString($query['sort_direction'] ?? null, 4);
        if (($sortField === null) !== ($sortDirection === null)
            || ($sortField !== null && ! isset($fieldKeys[$sortField]) && ! StorageListOrder::isSystem($sortField))
            || ($sortDirection !== null && ! in_array($sortDirection, ['asc', 'desc'], true))) {
            throw new InvalidArgumentException('dataview_list_saved_view_sort_invalid');
        }
        $dataviewId = $this->nullableId($query['dataview_id'] ?? null, '/^workbench\.[a-z][a-z0-9_.:-]{0,109}\.(?:table|cards|calendar|kanban|gantt|tree)$/');
        if ($dataviewId !== null && ! str_starts_with($dataviewId, $structure->structureId.'.')) {
            throw new InvalidArgumentException('dataview_list_saved_view_dataview_invalid');
        }
        $perPage = filter_var($query['per_page'] ?? 20, FILTER_VALIDATE_INT);
        if (! in_array($perPage, self::PER_PAGE, true)) {
            throw new InvalidArgumentException('dataview_list_saved_view_per_page_invalid');
        }

        // Toolbar filters are kept as chosen (a relative date stays relative) once they validate.
        $filters = $query['filters'] ?? null;
        if (is_string($filters)) {
            try {
                $filters = $filters === '' ? [] : (strlen($filters) <= 8192 ? json_decode($filters, true, 6, JSON_THROW_ON_ERROR) : null);
            } catch (\JsonException) {
                $filters = null;
            }
        }
        $this->queryFilters($structure, $filters);
        $savedFilters = [];
        foreach (is_array($filters) ? $filters : [] as $key => $filter) {
            $savedFilters[(string) $key] = array_intersect_key($filter, array_flip(['operator', 'value', 'values']));
        }
        ksort($savedFilters, SORT_STRING);

        return [
            'dataview_id' => $dataviewId,
            'search' => $this->nullableQueryString($query['search'] ?? null, 100),
            'filters' => $savedFilters,
            'filter_field' => $filterField,
            'filter_value' => $this->nullableQueryString($query['filter_value'] ?? null, 1000),
            'sort_field' => $sortField,
            'sort_direction' => $sortDirection,
            'per_page' => $perPage,
            'include_deleted' => filter_var($query['include_deleted'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    private function filterValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ((! is_string($value) && ! is_int($value)) || mb_strlen((string) $value) > 1000 || trim((string) $value) === '') {
            throw new InvalidArgumentException('dataview_list_filter_invalid');
        }

        return (string) $value;
    }

    private function structureId(mixed $value): string
    {
        return $this->nullableId($value, '/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/')
            ?? throw new InvalidArgumentException('dataview_list_identity_invalid');
    }

    private function nullableId(mixed $value, string $pattern): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('dataview_list_identity_invalid');
        }

        return $value;
    }

    private function nullableQueryString(mixed $value, int $maxLength): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('dataview_list_query_value_invalid');
        }

        return $value;
    }
}
