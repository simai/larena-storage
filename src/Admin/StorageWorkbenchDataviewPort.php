<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Admin\Runtime\AdminDataviewCells;
use Larena\Admin\Runtime\RecordFilterFields;
use Larena\Dataview\Enums\DataviewViewType;
use Larena\Dataview\Runtime\DataviewRoleBinder;
use Larena\Property\Contracts\PropertyFieldDefinition;
use Larena\Property\Contracts\PropertyRenderContext;
use Larena\Property\Runtime\PropertyDisplayProjector;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Setting\Exceptions\SettingRevisionConflict;
use Larena\Setting\ViewConfig\ViewConfigRefused;
use Larena\Setting\ViewConfig\ViewConfigStore;
use Larena\Storage\Board\StorageBoardDescription;
use Larena\Storage\Board\StorageBoardWindow;
use Larena\Storage\Contracts\StorageWorkbenchBoard;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Ui\Frontend\FrameworkPortEdition;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * The Storage workbench owns this port; registered document lists have another source.
 *
 * Column settings (personal and for everyone) and saved views live in
 * {@see DataviewListPreferences}, per scope and structure: the same layers and views the
 * Larena CMS list of that structure reads. Saved views are simai.view-config/1 documents of the levels
 * system (shipped with a package, written by nobody here), shared and personal (port 1.16.0); the person's own
 * view state of the list (rows per page, the view zone, the view opened last) comes with every answer as
 * `data.preferences` and is written by `preferences.save`. A query whose `window` names `lanes` asks a board (contract §3).
 */
final readonly class StorageWorkbenchDataviewPort
{
    private PropertyDisplayProjector $projector;

    public function __construct(
        private StorageWorkbenchGateway $gateway,
        private StorageWorkbench $storage,
        private StorageReferenceLabelResolver $labels,
        private DataviewListPreferences $lists,
        private Translator $translator,
        private UrlGenerator $urls,
        private ExceptionHandler $exceptions,
    ) {
        $this->projector = new PropertyDisplayProjector(PropertyTypeRegistry::builtIns());
    }

    /**
     * What the list may offer this person: everyone who reads the list keeps their own saved views;
     * saved views and column settings for everyone need {@see DataviewListPreferences::SHARED_RIGHT}.
     *
     * @return array<string,bool>
     */
    public function capabilities(string $actor): array
    {
        $shared = $this->lists->maySaveShared($actor);

        return ['saved-views' => true, 'shared-saved-views' => $shared, 'shared-column-settings' => $shared,
            'bulk-under-filter' => true, 'row-actions' => true, 'personal-preferences' => self::preferencesOffered()];
    }

    /**
     * Whether the page announces `personal-preferences`: the capability is the port's from 1.16.0, so it is announced
     * once the Framework the site pins carries that edition; until then the view keeps the state in the browser. The
     * port answers `preferences.save` and sends `data.preferences` either way.
     */
    public static function preferencesOffered(): bool
    {
        try {
            return class_exists(FrameworkPortEdition::class)
                && version_compare(FrameworkPortEdition::current('simai.dataview-port'), '1.16.0', '>=');
        } catch (Throwable) {
            // No pinned Framework to read (a package's own tests): the page keeps the state in the browser.
            return false;
        }
    }

    /**
     * The level a saved view is sent with. Port 1.16.0 names the highest level `system`; the pinned editions before it
     * know only package | shared | personal, so until the site pins 1.16.0 a system view goes out as `package`
     * (simai.view-config/1 §4: a host may name it `package` until 2027-01-01). The same switch as
     * `personal-preferences`.
     */
    public static function wireLevel(string $level): string
    {
        return $level === 'system' && !self::preferencesOffered() ? 'package' : $level;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function raise(string $actor, string $scope, string $structureId, string $intent, array $payload): array
    {
        if ($intent === 'query.change') {
            return $this->query($actor, $scope, $structureId, $payload);
        }

        $base = $this->gateway->snapshot($actor, $scope, ['structure_id' => $structureId, 'surface' => 'storage_section', 'records' => 'skip']);
        if (($base['status'] ?? null) === 'denied') {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        }
        if (($base['status'] ?? null) !== 'ok' || ($base['selected_structure']['structure_id'] ?? null) !== $structureId) {
            return ['answer' => 'unavailable', 'reason' => 'collection_unavailable'];
        }

        return match ($intent) {
            'bulk.apply_selected' => $this->archiveSelected($actor, $scope, $structureId, $payload, $base),
            'bulk.apply_under_filter' => $this->archiveUnderFilter($actor, $scope, $structureId, $payload, $base),
            'record.open', 'record.mutate' => $this->record($actor, $scope, $structureId, $intent, $payload, $base),
            'settings.save_personal', 'settings.save_shared' => $this->settings($actor, (string) $base['scope_ref'], $structureId, $intent, $payload),
            'view.open', 'view.save', 'view.delete' => $this->view($actor, (string) $base['scope_ref'], $structureId, $intent, $payload),
            // views.reset(id, level) of simai.view-config/1 §6 (port 1.16.0): the level's own copy goes, the view above opens.
            'view.reset', 'views.reset' => $this->resetView($actor, (string) $base['scope_ref'], $structureId, $payload),
            // A board view's lane limits (simai.view-config/1 §3 `layouts.board.limits`), against the level's own revision.
            'view.save_board' => $this->saveBoard($actor, (string) $base['scope_ref'], $structureId, $payload),
            // The person's own view state of the list (port 1.16.0): only the fields that changed, against its revision.
            'preferences.save' => $this->preferences($actor, (string) $base['scope_ref'], $structureId, $payload),
            // The composite's own call for a view for everyone; the page sends the same as view.save with target "shared".
            'view.save_shared' => $this->view($actor, (string) $base['scope_ref'], $structureId, 'view.save', ['target' => 'shared'] + $payload),
            // «Create» in the toolbar: the page opens the create form when the person may create records here.
            // Creating is offered only to a person who may create; to anyone else the capability is not there.
            'view.create_record' => ($base['permissions']['record.create'] ?? false) === true
                ? ['answer' => 'applied'] : ['answer' => 'unavailable', 'reason' => 'record_create_unavailable'],
            // The whole list a capped one shows part of: this storage's own page (port 1.10.0).
            'list.open' => ['answer' => 'applied', 'href' => '/admin/storage/'.rawurlencode($structureId)],
            default => ['answer' => 'unavailable', 'reason' => 'capability-not-provided'],
        };
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function query(string $actor, string $scope, string $structureId, array $payload): array
    {
        $sequence = $payload['sequence'] ?? null;
        if (!is_int($sequence) || $sequence < 1) {
            return ['answer' => 'refused', 'reason' => 'query_invalid'];
        }

        return $this->answer($actor, $scope, $structureId, $payload['query'] ?? null, $sequence);
    }

    /**
     * Answer one query of the list. A query that names an open saved view shows that view's
     * column layout; its filters, search and sort stay what the person asked for.
     *
     * @return array<string,mixed>
     */
    private function answer(string $actor, string $scope, string $structureId, mixed $asked, ?int $sequence): array
    {
        $echoSequence = $sequence === null ? [] : ['sequence' => $sequence];
        if (!is_array($asked) || ($asked !== [] && array_is_list($asked))) {
            return ['answer' => 'refused', 'reason' => 'query_invalid'];
        }
        $page = $asked['page'] ?? 1;
        $perPage = $asked['per_page'] ?? 20;
        $search = $asked['search'] ?? '';
        $sort = $asked['sort'] ?? [];
        $chosen = $asked['filters'] ?? [];
        $archived = $asked['include_archived'] ?? false;
        $savedViewId = $asked['saved_view_id'] ?? null;
        // How the total is counted: approximately above Storage's threshold unless asked exactly;
        // a reader that only pages on (the export) skips it and stops at a short page.
        $count = $asked['count'] ?? 'auto';
        if (!in_array($count, ['auto', 'exact', 'skip'], true)) {
            return ['answer' => 'refused', 'reason' => 'query_invalid'];
        }
        // The search box takes up to 100 characters; a longer one is refused with words the list shows, so the
        // person can shorten it (characters, not bytes: a Cyrillic letter is two bytes).
        if (is_string($search) && mb_strlen($search) > 100) {
            return ['answer' => 'refused', 'reason' => 'query_invalid',
                'message' => $this->translator->get('larena-storage::admin.section.search_too_long', ['max' => 100])];
        }
        if (!is_int($page) || $page < 1 || $page > 1000 || !is_int($perPage) || $perPage < 1 || $perPage > 100
            || !is_string($search) || !is_bool($archived)
            || !is_array($sort) || !array_is_list($sort) || count($sort) > 3
            || !is_array($chosen) || ($chosen !== [] && array_is_list($chosen)) || count($chosen) > 20
            || ($savedViewId !== null && (!is_string($savedViewId) || preg_match(DataviewListPreferences::SAVED_VIEW_ID, $savedViewId) !== 1))) {
            return ['answer' => 'refused', 'reason' => 'query_invalid'];
        }
        $search = trim($search);
        // Read the schema through Storage's scoped contract, then ask the
        // gateway for the filtered snapshot once. A second full snapshot used
        // to repeat files, content, layout and permission reads for every query.
        try {
            $structure = $this->storage->readStructure($scope, $structureId, $actor);
            $fields = $structure->fields;
        } catch (Throwable) {
            $state = $this->gateway->snapshot($actor, $scope, ['structure_id' => $structureId, 'surface' => 'storage_section', 'records' => 'skip']);
            return ($state['status'] ?? null) === 'denied'
                ? ['answer' => 'refused', 'reason' => 'access_denied']
                : ['answer' => 'unavailable', 'reason' => 'collection_unavailable'];
        }
        $known = [];
        // A hidden field keeps its values and stays filterable by nobody: it is
        // left out of the columns, the display and the query.
        $fields = array_values(array_filter($fields, static fn (array $field): bool => is_string($field['key'] ?? null)
            && ($field['hidden'] ?? false) !== true));
        foreach ($fields as $field) {
            $known[$field['key']] = true;
        }
        // The data view's filters in Storage's shape, each checked against its field type's operators;
        // relative date ranges (today, week, month) become dates. A filter that does not fit is
        // dropped and reported, as on the Larena CMS list.
        ['filters' => $translated, 'rejected' => $rejected] = DataviewFilterQuery::fromDataview($chosen, array_keys($known));
        // A file field filtered by kinds of files filters by the files of those kinds.
        ['filters' => $translated, 'kinds' => $kinds] = (new FileKindFilter(DB::connection()))->translate($translated,
            array_column(array_filter($fields, static fn (array $field): bool => ($field['type'] ?? null) === 'file'), 'key'));
        $filters = [];
        foreach ($translated as $key => $filter) {
            try {
                $filters += $this->lists->queryFilters($structure, [$key => $filter]);
            } catch (InvalidArgumentException) {
                $rejected[] = $key;
            }
        }
        $rejected = array_map('strval', $rejected);
        $normalizedSort = [];
        foreach ($sort as $entry) {
            if (!is_array($entry) || !is_string($entry['key'] ?? null)
                || !in_array($entry['direction'] ?? null, ['asc', 'desc'], true)
                || (!isset($known[$entry['key']]) && !StorageListOrder::isSystem($entry['key']))) {
                return ['answer' => 'refused', 'reason' => 'sort_invalid'];
            }
            $normalizedSort[] = ['field' => $entry['key'], 'direction' => $entry['direction']];
        }
        // A board's window names its lane field; the page window of a list ({cursor, limit}) is not a board.
        $window = $asked['window'] ?? null;
        if (is_array($window) && array_key_exists('lanes', $window)) {
            return $this->board($actor, $scope, $structureId, $structure, $fields, $window, $filters, $rejected, $search, $normalizedSort, $archived,
                $chosen, $sort, $echoSequence, $savedViewId);
        }
        $read = fn (array $filters): array => $this->gateway->snapshot($actor, $scope, [
            'structure_id' => $structureId, 'filters' => $filters, 'search' => $search === '' ? null : $search,
            'sort' => $normalizedSort, 'page' => $page, 'per_page' => $perPage,
            'include_archived' => $archived, 'surface' => 'storage_section',
        ] + ($count === 'auto' ? [] : ['count' => $count]));
        $state = $read($filters);
        // A page past the end (the list shrank under a person's feet) is an empty page, not a failure:
        // the list ends where the first page is already short, or where Storage counts fewer records.
        if (($state['status'] ?? null) === 'rejected' && $page > 1) {
            $firstPage = $this->gateway->snapshot($actor, $scope, ['structure_id' => $structureId, 'filters' => $filters,
                'search' => $search === '' ? null : $search, 'sort' => $normalizedSort, 'page' => 1, 'per_page' => $perPage,
                'include_archived' => $archived, 'surface' => 'storage_section']);
            $firstItems = is_array($firstPage['records']['items'] ?? null) ? $firstPage['records']['items'] : [];
            if (($firstPage['status'] ?? null) === 'ok' && (count($firstItems) < $perPage
                || (($firstPage['records']['matched_count_exact'] ?? true) === true && ($firstPage['records']['matched_count'] ?? 0) <= ($page - 1) * $perPage))) {
                $firstPage['records']['items'] = [];
                $state = $firstPage;
            }
        }
        // A value Storage refuses (a malformed date, a foreign record) must not take the list down:
        // the list is shown without the filters and says so.
        if (($state['status'] ?? null) === 'rejected' && $filters !== []) {
            $rejected = array_values(array_unique([...$rejected, ...array_keys($filters)]));
            $filters = [];
            $state = $read([]);
        }
        if (($state['status'] ?? null) !== 'ok') {
            return ['answer' => in_array($state['status'] ?? null, ['denied', 'rejected'], true) ? 'refused' : 'unavailable',
                'reason' => $state['reason_code'] ?? 'query_unavailable'] + $echoSequence;
        }
        if (($state['selected_structure']['structure_id'] ?? null) !== $structureId || !is_string($state['scope_ref'] ?? null)) {
            return ['answer' => 'unavailable', 'reason' => 'collection_unavailable'] + $echoSequence;
        }
        $settings = $this->settingsData($actor, $state['scope_ref'], $structure, $fields, $savedViewId);
        $rows = [];
        $locale = $this->translator->getLocale();
        $context = PropertyRenderContext::admin($locale !== '' ? $locale : 'en', false, $actor, $scope);
        // A related record opens on its structure's page of the Storage section, a person on their profile.
        $recordUrl = Route::has('larena.admin.storage.records')
            ? str_replace('__structure__', '{structure}', $this->urls->route('larena.admin.storage.records', ['structure' => '__structure__'], false))
                .'?record_id={record}&record_mode=view#admin-record-view'
            : null;
        $urls = AdminDataviewCells::referenceUrls();
        foreach ($state['records']['items'] ?? [] as $record) {
            if (!is_array($record)) {
                continue;
            }
            $display = [];
            foreach ($fields as $field) {
                $key = $field['key'];
                $projection = $this->projector->project(
                    PropertyFieldDefinition::make($key, (string) ($field['type'] ?? ''), 'lang:'.$key,
                        is_array($field['constraints'] ?? null) ? $field['constraints'] : [], ($field['required'] ?? false) === true),
                    is_int($field['type_version'] ?? null) ? $field['type_version'] : 1,
                    $record['values'][$key] ?? null, $context, $this->labels,
                );
                $display[$key] = AdminDataviewCells::value($field, $record['values'][$key] ?? null,
                    $projection->status === 'present' ? $projection->text : null,
                    AdminDataviewCells::referenceHref($field, $record['values'][$key] ?? null, $recordUrl, $urls['user'], $urls['file']));
            }
            // Row actions use the data-view record intents; the developer workbench table ignores them.
            // Archiving is offered on the record card, which asks for its own confirmation,
            // not from the row menu, which could only ask through a browser dialog.
            $actions = ['record.view'];
            if (($record['state'] ?? null) === 'active' && ($state['permissions']['record.update'] ?? false) === true) {
                $actions[] = 'record.edit';
            }
            $rows[] = ['id' => $record['record_id'], 'revision' => $record['revision'],
                'actions' => $actions, 'display_values' => $display];
        }
        // The labels of the host's own action ids; which apply to a row stays that row's `actions` list.
        $offered = array_values(array_unique(array_merge(['record.view'], ...array_column($rows, 'actions'))));
        $rowActions = [];
        foreach (['record.view' => 'view', 'record.edit' => 'edit'] as $actionId => $word) {
            if (in_array($actionId, $offered, true)) {
                $label = $this->translator->get('larena-admin::admin.row_actions.'.$word);
                $rowActions[] = ['id' => $actionId, 'label' => is_string($label) ? $label : $word];
            }
        }
        return ['answer' => 'applied', ...$echoSequence, 'data' => [
            'columns' => $settings['columns'], 'records' => $rows, 'row_actions' => $rowActions,
            'total' => $state['records']['matched_count'] ?? 0,
            // False when the total is Storage's estimate of a large list («~N»); count = exact asks for the exact one.
            'total_exact' => ($state['records']['matched_count_exact'] ?? true) === true,
            // A sort by an unindexed field on a large storage is slow: the list says so and, to a person who may
            // configure the storage, links the field's «Быстрая сортировка и фильтр». Null clears the hint.
            'sort_hint' => (new StorageSortHint($this->translator))->hint(
                is_array($state['records'] ?? null) ? $state['records'] : [],
                is_array($state['selected_structure'] ?? null) ? $state['selected_structure'] : null,
                ($state['permissions']['structure.configure'] ?? false) === true
                    && ($state['selected_structure']['archived'] ?? false) !== true,
                '/admin/storage/'.rawurlencode($structureId).'/settings',
            ),
            'echo' => ['filters_chosen' => $chosen, 'filters' => FileKindFilter::echoKinds(DataviewFilterQuery::echo($chosen, $filters), $kinds), 'filters_rejected' => $rejected,
                'search' => $search, 'sort' => $sort, 'page' => $page, 'per_page' => $perPage,
                'include_archived' => $archived],
            ...array_diff_key($settings, ['columns' => true]),
            ...$this->viewState($actor, $state['scope_ref'], $structureId),
            'capabilities' => $this->capabilities($actor),
        ]];
    }

    /**
     * The person's own view state and the limits of saved views: with every answer, so the first one carries them
     * (port 1.16.0 `preferences`; simai.view-config/1 §5 `limits`). A store that cannot be read leaves them out.
     *
     * @return array<string, mixed>
     */
    private function viewState(string $actor, string $scopeRef, string $structureId): array
    {
        try {
            return ['preferences' => $this->lists->preferences($actor, $scopeRef, $structureId), 'limits' => $this->lists->limits()];
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);

            return ['limits' => $this->lists->limits()];
        }
    }

    /**
     * A board's window (simai.view-config/1 §3): every lane's first records and totals in one answer, or one lane
     * further from its cursor. The list's filters, search, sort and archived records cut every lane.
     *
     * @param list<array<string,mixed>> $fields @param array<string,mixed> $filters @param list<string> $rejected
     * @param list<array{field:string,direction:string}> $normalizedSort @param array<string,mixed> $chosen
     * @param array<mixed> $sort @param array<string,int> $echoSequence @param array<array-key,mixed> $window
     * @return array<string,mixed>
     */
    private function board(string $actor, string $scope, string $structureId, StorageWorkbenchStructure $structure, array $fields, array $window,
        array $filters, array $rejected, string $search, array $normalizedSort, bool $archived, array $chosen, array $sort, array $echoSequence,
        ?string $savedViewId = null): array
    {
        if (!$this->storage instanceof StorageWorkbenchBoard) {
            return ['answer' => 'unavailable', 'reason' => 'board_unavailable'] + $echoSequence;
        }
        $locale = $this->translator->getLocale();
        $context = PropertyRenderContext::admin($locale !== '' ? $locale : 'en', false, $actor, $scope);
        $list = new StorageWorkbenchRecordQuery($scope, $structureId, $filters, $search === '' ? null : $search,
            $normalizedSort === [] ? StorageListOrder::NEWEST_FIRST : $normalizedSort, StorageBoardWindow::PER_LANE_DEFAULT, null, $archived);
        try {
            $board = (new StorageBoardWindow($this->storage))->answer($window, $list, $actor,
                fn (StorageWorkbenchRecord $record): array => ['display_values' => $this->display($fields, $record->values, $context)],
                $this->boardLimits($actor, $scope, $structure, $window, $savedViewId));
        } catch (StorageRejected $rejectedBoard) {
            $code = $rejectedBoard->reasonCode;

            return (str_starts_with($code, 'storage_board_window_limit:')
                ? ['answer' => 'refused', 'reason' => 'limit', 'limit' => substr($code, strlen('storage_board_window_limit:'))]
                : ['answer' => 'refused', 'reason' => $code]) + $echoSequence;
        }

        return ['answer' => 'applied', ...$echoSequence, 'data' => [
            'records' => [],
            'lanes' => $board['lanes'],
            'total' => array_sum(array_column($board['lanes'], 'total')),
            'columns' => $this->columns($fields, []),
            'echo' => ['filters_chosen' => $chosen, 'filters' => DataviewFilterQuery::echo($chosen, $filters), 'filters_rejected' => $rejected,
                'search' => $search, 'sort' => $sort, 'include_archived' => $archived, 'window' => $window],
            ...$this->viewState($actor, $scope, $structure->structureId),
            'capabilities' => $this->capabilities($actor),
        ]];
    }

    /**
     * The soft limits of the lanes (simai.view-config/1 §3 `over_limit`): those of the open board view's description
     * (`layouts.board.limits`), when its lane field is the window's. No open view, no description, or one that no
     * longer fits the fields: no limits, and the board still answers.
     *
     * @param array<array-key,mixed> $window @return array<string,int>
     */
    private function boardLimits(string $actor, string $scope, StorageWorkbenchStructure $structure, array $window, ?string $savedViewId): array
    {
        if ($savedViewId === null) {
            return [];
        }
        try {
            // The view as the person opens it: their own copy laid over the one everyone has (views.reset drops it again).
            $open = $this->lists->resolvedView($actor, $scope, $structure->structureId, $savedViewId);
            $options = is_array($open['board'] ?? null) ? $open['board'] : [];
            if ($options === [] || ($options['lane_field'] ?? $window['lanes']) !== $window['lanes']) {
                return [];
            }

            return StorageBoardDescription::fromArray(['lane_field' => $window['lanes']] + $options, $structure->fields)->limits;
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);

            return [];
        }
    }

    /**
     * A record's values as the list shows them; an encrypted field shows nothing (its value is sealed).
     *
     * @param list<array<string,mixed>> $fields @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function display(array $fields, array $values, PropertyRenderContext $context): array
    {
        $display = [];
        foreach ($fields as $field) {
            $key = (string) $field['key'];
            if (($field['encrypted'] ?? false) === true) {
                $display[$key] = null;
                continue;
            }
            $projection = $this->projector->project(
                PropertyFieldDefinition::make($key, (string) ($field['type'] ?? ''), 'lang:'.$key,
                    is_array($field['constraints'] ?? null) ? $field['constraints'] : [], ($field['required'] ?? false) === true),
                is_int($field['type_version'] ?? null) ? $field['type_version'] : 1,
                $values[$key] ?? null, $context, $this->labels,
            );
            $display[$key] = AdminDataviewCells::value($field, $values[$key] ?? null, $projection->status === 'present' ? $projection->text : null, null);
        }

        return $display;
    }

    /**
     * preferences.save: the fields of the person's view state that changed (page_size of the menu 10/20/50/100,
     * view_zone open or closed, the view opened last; null removes one), against the revision of their layer.
     *
     * @param array<string,mixed> $payload @return array<string,mixed>
     */
    private function preferences(string $actor, string $scopeRef, string $structureId, array $payload): array
    {
        $changes = $payload['preferences'] ?? null;
        $revision = $payload['base_revision'] ?? null;
        if (is_string($revision) && preg_match('/\A(0|[1-9][0-9]{0,9})\z/', $revision) === 1) {
            $revision = (int) $revision;
        }
        // The port's revisions are strings or integers: a string this layer never issued is a well-formed revision
        // that is not the current one, so it is a conflict naming the current revision, and the view raises again.
        $foreign = is_string($revision) && $revision !== '' && strlen($revision) <= 64;
        if (!is_array($changes) || (!$foreign && (!is_int($revision) || $revision < 0))
            || array_diff(array_keys($payload), ['preferences', 'base_revision', 'reason']) !== []) {
            return ['answer' => 'refused', 'reason' => 'preferences_invalid'];
        }
        if ($foreign) {
            if ($changes === [] || array_is_list($changes) || array_diff(array_map('strval', array_keys($changes)), ViewConfigStore::PREFERENCES) !== []) {
                return ['answer' => 'refused', 'reason' => 'preferences_invalid'];
            }
            try {
                return ['answer' => 'conflict', 'current_revision' => $this->lists->preferences($actor, $scopeRef, $structureId)['revision']];
            } catch (Throwable) {
                return ['answer' => 'refused', 'reason' => 'access_denied'];
            }
        }
        try {
            return ['answer' => 'applied', 'data' => ['preferences' => $this->lists->savePreferences($actor, $scopeRef, $structureId, $changes, $revision)]];
        } catch (SettingRevisionConflict $conflict) {
            return ['answer' => 'conflict', 'current_revision' => $conflict->actualRevision];
        } catch (ViewConfigRefused $refused) {
            return $this->refusal($refused);
        } catch (Throwable) {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        }
    }

    /**
     * A refusal of the views store with its stable code and the pack's words (contract §5: a save over a limit is
     * refused with reason `limit` and the limit's name).
     *
     * @return array<string,mixed>
     */
    private function refusal(ViewConfigRefused $refused): array
    {
        $words = $this->translator->get($refused->wordsKey(), ['limit' => (string) ($refused->limitValue() ?? '')]);

        return $refused->answer() + (is_string($words) && $words !== $refused->wordsKey() ? ['message' => $words] : []);
    }

    /**
     * The columns with the person's layout, the column settings by layer and the person's saved views
     * of this structure. A store that cannot be read leaves the list with its plain columns.
     *
     * @param list<array<string,mixed>> $fields the fields the list shows
     * @return array{columns:list<array<string,mixed>>,column_settings?:array<string,mixed>,saved_views?:list<array<string,mixed>>}
     */
    private function settingsData(string $actor, string $scopeRef, StorageWorkbenchStructure $structure, array $fields, ?string $savedViewId): array
    {
        try {
            $views = $this->lists->structureViews($actor, $scopeRef, $structure->structureId);
            // The open view as the person reads it: their own copy over the one everyone has, over the package's.
            $open = $this->lists->resolvedView($actor, $scopeRef, $structure->structureId, $savedViewId);
            $preferences = $this->lists->columns($actor, $scopeRef, $structure, $open);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
            return ['columns' => $this->columns($fields, [])];
        }
        $target = $preferences['target'];
        // With a saved view open, the person's layer is that view's layout: a column change is saved
        // into the view, against the view's revision.
        $personal = $target['kind'] === 'saved_view'
            ? ['revision' => $target['revision'], 'columns' => $preferences['personal']['columns']]
            : $preferences['personal'];
        $saved = array_map(fn (array $view): array => $this->savedEntry($view), $views);

        return [
            'columns' => $this->columns($fields, $preferences['columns']),
            'column_settings' => [
                'layers' => ['package' => ['revision' => 0, 'columns' => (object) []],
                    'shared' => ['revision' => $preferences['shared']['revision'], 'columns' => (object) $preferences['shared']['columns']],
                    'personal' => ['revision' => $personal['revision'], 'columns' => (object) $personal['columns']]],
                'target' => ['kind' => $target['kind'], 'revision' => $target['revision'], 'key' => $target['saved_view_id'] ?? null],
            ],
            'saved_views' => $saved,
        ];
    }

    /**
     * As on the CMS port: every answer says which columns sort and which one names a record, and
     * carries the layout the person sees.
     *
     * @param list<array<string,mixed>> $fields @param array<string,mixed> $layout
     * @return list<array<string,mixed>>
     */
    private function columns(array $fields, array $layout): array
    {
        // An encrypted field shows a mask, so it never titles the list: the record id does.
        $titleKey = (new DataviewRoleBinder)->bind(DataviewViewType::Table,
            array_map('strval', array_column(array_filter($fields, static fn (array $field): bool => ($field['encrypted'] ?? false) !== true), 'type', 'key')), ['title' => 'title'])['bindings']['title'] ?? null;
        $columns = [];
        // On a phone the list is a list of rows (C31, lists study PX-64): the title over a line of the next two fields.
        $supporting = 0;
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $option = is_array($layout[$field['key']] ?? null) ? $layout[$field['key']] : [];
                $place = $field['key'] === $titleKey ? 'title'
                    : (($option['visible'] ?? true) !== false && ($field['encrypted'] ?? false) !== true && $supporting < 2 ? 'supporting' : null);
                $supporting += $place === 'supporting' ? 1 : 0;
                $columns[] = ['key' => $field['key'], 'label' => $field['label'] ?? $field['key']]
                    + ($field['key'] === $titleKey ? ['role' => 'title'] : [])
                    + ($place !== null ? ['list' => $place] : [])
                    + ['sortable' => RecordFilterFields::sortable($field),
                        'visible' => $option['visible'] ?? true]
                    + array_intersect_key($option, array_flip(['order', 'width', 'min_width', 'priority', 'sticky', 'pinned']))
                    + AdminDataviewCells::column($field);
            }
        }

        return $columns;
    }

    /**
     * Saving column settings: the person's own layer (or the open saved view's layout), or the layer
     * for everyone. Empty settings reset the layer, against its revision like any other write.
     *
     * @param array<string,mixed> $payload @return array<string,mixed>
     */
    private function settings(string $actor, string $scopeRef, string $structureId, string $intent, array $payload): array
    {
        $shared = $intent === 'settings.save_shared';
        // Settings for everyone are a capability of the person who may set them; to anyone else it is not there.
        if ($shared && !$this->lists->maySaveShared($actor)) {
            return ['answer' => 'unavailable', 'reason' => 'shared_settings_unavailable'];
        }
        $columns = $payload['columnSettings'] ?? null;
        $revision = $payload['base_revision'] ?? null;
        $savedViewId = $shared ? null : ($payload['saved_view_id'] ?? null);
        if (!is_array($columns) || ($columns !== [] && array_is_list($columns)) || !is_int($revision) || $revision < 0
            || ($shared && ($payload['target'] ?? null) !== 'shared')
            || ($savedViewId !== null && (!is_string($savedViewId) || preg_match(DataviewListPreferences::SAVED_VIEW_ID, $savedViewId) !== 1))
            || array_diff(array_keys($payload), ['columnSettings', 'base_revision', 'target', 'reason', 'saved_view_id']) !== []) {
            return ['answer' => 'refused', 'reason' => 'settings_invalid'];
        }
        // The table reports every option of a column; a layer keeps only the layout settings.
        $layout = [];
        foreach ($columns as $key => $options) {
            if (!is_string($key) || !is_array($options)) {
                return ['answer' => 'refused', 'reason' => 'settings_invalid'];
            }
            $kept = array_intersect_key($options, array_flip(DataviewListPreferences::COLUMN_OPTIONS));
            if ($kept !== []) {
                $layout[$key] = $kept;
            }
        }
        try {
            $structure = $this->storage->readStructure($scopeRef, $structureId, $actor);
        } catch (Throwable) {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        }
        $outcome = $this->write(function () use ($actor, $scopeRef, $structureId, $savedViewId, $revision, $layout, $shared): void {
            if ($savedViewId !== null) {
                $open = $this->lists->resolvedView($actor, $scopeRef, $structureId, $savedViewId);
                if ($open === null) {
                    throw new InvalidArgumentException('saved_view_unknown');
                }
                // The change goes to the person's own copy of the view; on a view everyone has it makes that copy and
                // leaves the view for everyone as it is (it changes only through its shared save).
                $this->lists->saveViewLayout($actor, $scopeRef, $savedViewId, $revision, $layout);
                return;
            }
            $this->lists->saveColumns($actor, $scopeRef, $structureId, $revision, $layout, $shared);
        });
        if ($outcome !== null) {
            return $outcome;
        }
        // The answer carries the new layers, so the next change is written against the new revision.
        $data = $this->settingsData($actor, $scopeRef, $structure, $this->shownFields($structure), $savedViewId);

        return ['answer' => 'applied', 'data' => $data];
    }

    /**
     * Saved views: opening one runs its query with its layout, saving stores the current query and
     * layout under a name, deleting removes it. Anyone who reads the list keeps their own views; an
     * administrator also keeps views for everyone (target "shared"). A view keeps its key when it is
     * renamed.
     *
     * @param array<string,mixed> $payload @return array<string,mixed>
     */
    private function view(string $actor, string $scopeRef, string $structureId, string $intent, array $payload): array
    {
        $key = $payload['key'] ?? null;
        if (!is_string($key) || $key === '' || strlen($key) > 120) {
            return ['answer' => 'refused', 'reason' => 'view_key_invalid'];
        }
        try {
            $views = $this->lists->structureViews($actor, $scopeRef, $structureId);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
            return ['answer' => 'unavailable', 'reason' => 'saved_views_unavailable'];
        }
        $existing = $this->lists->savedView($views, preg_match(DataviewListPreferences::SAVED_VIEW_ID, $key) === 1 ? $key : null);
        if ($intent === 'view.open') {
            // The view opens as the person reads it: their own copy when they have one, else the one everyone has,
            // else the package's (simai.view-config/1 §4).
            $existing = $existing === null ? null : $this->lists->resolvedView($actor, $scopeRef, $structureId, $existing['saved_view_id']);
            if ($existing === null || array_diff(array_keys($payload), ['key']) !== []) {
                return ['answer' => 'refused', 'reason' => 'view_unknown'];
            }
            $answer = $this->answer($actor, $scopeRef, $structureId,
                ['page' => 1] + $this->listQuery($existing['query']) + ['saved_view_id' => $existing['saved_view_id']], null);
            return $answer['answer'] === 'applied' ? $answer
                : ['answer' => 'unavailable', 'reason' => $answer['reason'] ?? 'view_unavailable'];
        }
        // A system view ships with its package and is never written through the port (port 1.16.0): the person saves
        // a change to it as a new view of their own («Сохранить как моё»), or for everyone.
        // One cause, one answer: the same `system_readonly` with the pack's words as a column change of the view.
        if ($existing !== null && $existing['level'] === 'system' && ($intent === 'view.delete' || $intent === 'view.save')) {
            return $this->refusal(new ViewConfigRefused('system_readonly'));
        }
        if ($intent === 'view.delete') {
            if ($existing === null) {
                return ['answer' => 'refused', 'reason' => 'view_unknown'];
            }
            if ($existing['level'] === 'shared' && !$this->lists->maySaveShared($actor)) {
                return ['answer' => 'refused', 'reason' => 'shared_saved_views_denied'];
            }
            return $this->write(fn () => $this->lists->deleteView($actor, $scopeRef, $existing['saved_view_id']), true)
                ?? ['answer' => 'applied', 'key' => $existing['saved_view_id']];
        }
        if (array_diff(array_keys($payload), ['key', 'label', 'revision', 'query', 'roles', 'layout', 'settings', 'target']) !== []) {
            return ['answer' => 'refused', 'reason' => 'view_fields_invalid'];
        }
        $target = $payload['target'] ?? 'personal';
        if (!in_array($target, ['personal', 'shared'], true)) {
            return ['answer' => 'refused', 'reason' => 'view_fields_invalid'];
        }
        if ($target === 'shared' && !$this->lists->maySaveShared($actor)) {
            return ['answer' => 'unavailable', 'reason' => 'shared_saved_views_unavailable'];
        }
        // A name is 1–120 characters (simai.view-config/1 §2): a longer one is refused, never cut.
        $label = is_string($payload['label'] ?? null) && trim($payload['label']) !== '' ? trim($payload['label']) : $key;
        if (mb_strlen($label) > DataviewListPreferences::NAME_MAX) {
            return ['answer' => 'refused', 'reason' => 'view_name_too_long', 'limit' => DataviewListPreferences::NAME_MAX];
        }
        // A view of the other level is not rewritten in place: saving a view for everyone as one's
        // own (or the other way round) makes a new view with the same contents.
        if ($existing !== null && $existing['level'] !== $target) {
            $existing = null;
            unset($payload['revision']);
        }
        // Without a known key, a view is named by its label: saving again under the same name writes that view.
        $renamed = $existing !== null;
        if ($existing === null) {
            foreach ($views as $view) {
                if ($view['level'] === $target && mb_strtolower($view['name']) === mb_strtolower($label)) {
                    $existing = $view;
                    break;
                }
            }
        }
        // A write of a stored view names its revision; a view deleted meanwhile is not written again.
        if ($existing === null && array_key_exists('revision', $payload)) {
            return ['answer' => 'refused', 'reason' => 'view_unknown'];
        }
        if ($existing !== null && array_key_exists('revision', $payload) && $existing['revision'] !== $payload['revision']) {
            return ['answer' => 'conflict', 'current_revision' => $existing['revision']];
        }
        $query = $this->savedQuery(is_array($payload['query'] ?? null) ? $payload['query'] : []);
        // The page's query names no view type: a board (or any view of a type of its own) stays what it is, and so do
        // its board options.
        if (is_string($existing['query']['dataview_id'] ?? null)) {
            $query['dataview_id'] = $existing['query']['dataview_id'];
        }
        // A view named by its key takes the label it is sent with: that is how it is renamed.
        $write = ['structure_id' => $structureId, 'name' => $renamed ? $label : ($existing['name'] ?? $label), 'query' => $query, 'level' => $target];
        if (array_key_exists('roles', $payload)) {
            $write['roles'] = $payload['roles'];
        }
        if (array_key_exists('settings', $payload)) {
            // The data view sends its column settings along; the layers are the host's own and the
            // layout travels separately, so only the view's own settings are kept.
            $write['settings'] = is_array($payload['settings'])
                ? array_diff_key($payload['settings'], ['layers' => true, 'target' => true]) : $payload['settings'];
        }
        if (array_key_exists('layout', $payload)) {
            $layout = $payload['layout'];
            if (!is_array($layout) || array_keys($layout) !== ['columns'] || !is_array($layout['columns'])
                || !array_is_list($layout['columns'])) {
                return ['answer' => 'refused', 'reason' => 'view_layout_invalid'];
            }
            $columns = [];
            foreach ($layout['columns'] as $column) {
                if (!is_array($column) || !is_string($column['key'] ?? null) || isset($columns[$column['key']])) {
                    return ['answer' => 'refused', 'reason' => 'view_layout_invalid'];
                }
                // The table sends its whole column description; a saved view keeps only the layout settings.
                $kept = array_intersect_key($column, array_flip(DataviewListPreferences::COLUMN_OPTIONS));
                if ($kept !== []) {
                    $columns[$column['key']] = $kept;
                }
            }
            $write['layout'] = ['columns' => $columns];
        }
        if ($existing !== null) {
            $write['saved_view_id'] = $existing['saved_view_id'];
            $write['base_revision'] = $existing['revision'];
            $write['loaded_saved_view_id'] = $existing['saved_view_id'];
        }
        $savedViewId = null;
        $outcome = $this->write(function () use ($actor, $scopeRef, $write, $existing, &$savedViewId): void {
            $savedViewId = $this->lists->saveView($actor, $scopeRef, $write, $existing !== null);
        }, true);

        return $outcome ?? ['answer' => 'applied', 'key' => $savedViewId];
    }

    /**
     * A saved view as the page lists it (`saved_views[]`), its level as the pinned port names it.
     *
     * @param array<string,mixed> $view @return array<string,mixed>
     */
    private function savedEntry(array $view): array
    {
        // A view for everyone shows «Save for all» ticked in the table's save form.
        return ['key' => $view['saved_view_id'], 'label' => $view['name'], 'level' => self::wireLevel((string) $view['level']),
            'data' => ['template_save_for_all' => $view['level'] === 'shared'], 'revision' => $view['revision'],
            'query' => ['filters' => (object) $this->listQuery($view['query'])['filters']] + $this->listQuery($view['query']), 'roles' => $view['roles'], 'layout' => ['columns' => array_map(
                static fn (string $key, array $options): array => ['key' => $key] + $options,
                array_keys($view['layout']['columns']), array_values($view['layout']['columns']),
            )], 'settings' => $view['settings']];
    }

    /**
     * view.save_board: `{key, revision, limits, level?, lane_field?}`. `limits` is the whole set by lane value (a number,
     * or null / "" for none); `revision` is that of the level's own copy as it was read (0 when the person has none yet:
     * the save makes it). Answers the new revision and the limits as kept; a moved copy is a conflict, a system view
     * `system_readonly`.
     *
     * @param array<string,mixed> $payload @return array<string,mixed>
     */
    private function saveBoard(string $actor, string $scopeRef, string $structureId, array $payload): array
    {
        $key = $payload['key'] ?? null;
        if (!is_string($key) || preg_match(DataviewListPreferences::SAVED_VIEW_ID, $key) !== 1 || !array_key_exists('revision', $payload)
            || !is_array($payload['limits'] ?? null) || array_diff(array_keys($payload), ['key', 'revision', 'limits', 'level', 'lane_field']) !== []) {
            return ['answer' => 'refused', 'reason' => 'view_fields_invalid'];
        }
        $view = null;
        $outcome = $this->write(function () use ($actor, $scopeRef, $structureId, $key, $payload, &$view): void {
            $view = $this->lists->saveBoardLimits($actor, $scopeRef, $structureId, $key, $payload['revision'], $payload['limits'],
                $payload['level'] ?? null, $payload['lane_field'] ?? null);
        });

        return $outcome ?? ['answer' => 'applied', 'key' => $key, 'revision' => $view['revision'], 'level' => $view['level'],
            'lane_field' => $view['board']['lane_field'], 'limits' => (object) ($view['board']['limits'] ?? [])];
    }

    /**
     * views.reset: `{key, level, revision?}`. The level is the one whose own copy goes (`personal` or `shared`; a
     * system view, `package` included, is reset by nobody); the revision, when sent, is that copy's as it was read.
     * Answers the view as it now resolves.
     *
     * @param array<string,mixed> $payload @return array<string,mixed>
     */
    private function resetView(string $actor, string $scopeRef, string $structureId, array $payload): array
    {
        $key = $payload['key'] ?? null;
        $revision = $payload['revision'] ?? null;
        if (!is_string($key) || preg_match(DataviewListPreferences::SAVED_VIEW_ID, $key) !== 1 || !is_string($payload['level'] ?? null)
            || ($revision !== null && (!is_int($revision) || $revision < 0)) || array_diff(array_keys($payload), ['key', 'level', 'revision', 'reason']) !== []) {
            return ['answer' => 'refused', 'reason' => 'view_fields_invalid'];
        }
        $view = null;
        $outcome = $this->write(function () use ($actor, $scopeRef, $structureId, $key, $payload, $revision, &$view): void {
            $view = $this->lists->resetView($actor, $scopeRef, $structureId, $key, $payload['level'], $revision);
        });

        return $outcome ?? ['answer' => 'applied', 'key' => $key, 'view' => $this->savedEntry((array) $view)];
    }

    /**
     * The data view's query as a saved view keeps it: filters, search, sort, page size and whether
     * archived records are shown. The page is not kept.
     *
     * @param array<mixed> $asked @return array<string,mixed>
     */
    private function savedQuery(array $asked): array
    {
        $query = [];
        if (is_string($asked['search'] ?? null) && trim($asked['search']) !== '') {
            $query['search'] = trim($asked['search']);
        }
        $filters = DataviewFilterQuery::forSavedView($asked['filters'] ?? null);
        if ($filters !== []) {
            $query['filters'] = $filters;
        }
        $sort = is_array($asked['sort'] ?? null) ? ($asked['sort'][0] ?? null) : null;
        if (is_array($sort) && is_string($sort['key'] ?? null) && in_array($sort['direction'] ?? null, ['asc', 'desc'], true)) {
            $query['sort_field'] = $sort['key'];
            $query['sort_direction'] = $sort['direction'];
        }
        $perPage = $asked['per_page'] ?? 20;
        $query['per_page'] = in_array($perPage, DataviewListPreferences::PER_PAGE, true) ? $perPage : 20;
        if (($asked['include_archived'] ?? false) === true) {
            $query['include_deleted'] = true;
        }

        return $query;
    }

    /**
     * A stored saved view query in the shape this list asks with.
     *
     * @param array<string,mixed> $stored @return array<string,mixed>
     */
    private function listQuery(array $stored): array
    {
        $filters = is_array($stored['filters'] ?? null) ? $stored['filters'] : [];
        // Views saved with the single filter of the old list keep it as an equality filter.
        if (is_string($stored['filter_field'] ?? null) && is_string($stored['filter_value'] ?? null) && !isset($filters[$stored['filter_field']])) {
            $filters[$stored['filter_field']] = ['operator' => 'eq', 'value' => $stored['filter_value']];
        }
        $query = ['filters' => $filters, 'search' => is_string($stored['search'] ?? null) ? $stored['search'] : '',
            'sort' => is_string($stored['sort_field'] ?? null) && is_string($stored['sort_direction'] ?? null)
                ? [['key' => $stored['sort_field'], 'direction' => $stored['sort_direction']]] : [],
            'per_page' => is_int($stored['per_page'] ?? null) ? $stored['per_page'] : 20];
        if (($stored['include_deleted'] ?? false) === true) {
            $query['include_archived'] = true;
        }

        return $query;
    }

    /**
     * Run one write to the list store and turn its refusals into port answers; null when it applied.
     *
     * @return array<string,mixed>|null
     */
    private function write(callable $write, bool $retryList = false): ?array
    {
        try {
            try {
                $write();
            } catch (SettingRevisionConflict $first) {
                if (!$retryList) {
                    throw $first;
                }
                // The person's saved views are one list: a write to another view (the data view saves
                // and deletes views side by side) moves the list's revision, not this view's. The write
                // is read again and repeated once; a changed view still answers with a conflict.
                $write();
            }

            return null;
        } catch (SettingRevisionConflict $conflict) {
            return ['answer' => 'conflict', 'current_revision' => $conflict->actualRevision];
        } catch (ViewConfigRefused $refused) {
            return $this->refusal($refused);
        } catch (AccessMutationRejected) {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        } catch (StorageRejected $rejected) {
            return ['answer' => 'refused', 'reason' => $rejected->reasonCode];
        } catch (InvalidArgumentException $invalid) {
            return ['answer' => 'refused', 'reason' => preg_match('/^[a-z][a-z0-9_]{0,80}$/', $invalid->getMessage()) === 1
                ? $invalid->getMessage() : 'settings_invalid'];
        }
    }

    /** @return list<array<string,mixed>> */
    private function shownFields(StorageWorkbenchStructure $structure): array
    {
        return array_values(array_filter($structure->fields, static fn (array $field): bool => is_string($field['key'] ?? null)
            && ($field['hidden'] ?? false) !== true));
    }

    /**
     * «For all»: archive every active record the list's filters and search find. The count the person
     * confirmed travels along; the Storage section reads the set again and refuses a changed count as a
     * conflict. At most 1000 records at once.
     *
     * @param array<string,mixed> $payload @param array<string,mixed> $base @return array<string,mixed>
     */
    private function archiveUnderFilter(string $actor, string $scope, string $structureId, array $payload, array $base): array
    {
        if (($base['permissions']['record.bulk_archive'] ?? false) !== true) {
            return ['answer' => 'refused', 'reason' => 'bulk_archive_denied'];
        }
        $total = $payload['confirmed_total'] ?? null;
        $search = $payload['search'] ?? '';
        $chosen = $payload['filters'] ?? [];
        if (($payload['action_id'] ?? null) !== 'archive' || !is_int($total) || $total < 0
            || !is_string($search) || mb_strlen($search) > 100
            || !is_array($chosen) || ($chosen !== [] && array_is_list($chosen))) {
            return ['answer' => 'refused', 'reason' => 'selection_invalid'];
        }
        if ($total > 1000) {
            return ['answer' => 'refused', 'reason' => 'bulk_too_many'];
        }
        if ($total === 0) {
            return ['answer' => 'applied', 'count' => 0];
        }
        try {
            $structure = $this->storage->readStructure($scope, $structureId, $actor);
        } catch (Throwable) {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        }
        // The same translation the list's queries use: a filter that does not fit refuses the action
        // instead of archiving a wider set than the person saw.
        $known = array_column($this->shownFields($structure), 'key');
        ['filters' => $translated, 'rejected' => $rejected] = DataviewFilterQuery::fromDataview($chosen, $known);
        ['filters' => $translated] = (new FileKindFilter(DB::connection()))->translate($translated,
            array_column(array_filter($this->shownFields($structure), static fn (array $field): bool => ($field['type'] ?? null) === 'file'), 'key'));
        $filters = [];
        foreach ($translated as $key => $filter) {
            try {
                $filters += $this->lists->queryFilters($structure, [$key => $filter]);
            } catch (InvalidArgumentException) {
                $rejected[] = $key;
            }
        }
        if ($rejected !== []) {
            return ['answer' => 'refused', 'reason' => 'filters_invalid'];
        }
        // The number the person confirmed must still be the number under the filter; if not, the answer says how many now.
        $now = $this->answer($actor, $scope, $structureId, ['filters' => $chosen, 'search' => $search, 'page' => 1, 'per_page' => 1, 'count' => 'exact'], null);
        if (($now['answer'] ?? null) === 'applied' && $now['data']['total'] !== $total) {
            return ['answer' => 'conflict', 'reason' => 'count_changed', 'current_total' => $now['data']['total']];
        }
        $receipt = $this->gateway->dispatch($actor, $scope, 'record.bulk_archive_matching', [
            'structure_id' => $structureId, 'confirmed' => '1', 'expected_count' => $total,
            'filters' => $filters, 'search' => trim($search) === '' ? null : trim($search),
        ]);

        return match ($receipt['status'] ?? null) {
            'ok' => ['answer' => 'applied', 'count' => $total],
            'denied' => ['answer' => 'refused', 'reason' => 'bulk_archive_denied'],
            'rejected' => ($receipt['reason_code'] ?? null) === 'storage_workbench_revision_conflict'
                ? ['answer' => 'conflict', 'reason' => 'count_changed']
                : ['answer' => 'refused', 'reason' => $receipt['reason_code'] ?? 'bulk_archive_rejected'],
            default => ['answer' => 'unavailable', 'reason' => 'bulk_archive_unavailable'],
        };
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $base @return array<string,mixed> */
    private function archiveSelected(string $actor, string $scope, string $structureId, array $payload, array $base): array
    {
        if (($base['permissions']['record.bulk_archive'] ?? false) !== true) {
            return ['answer' => 'refused', 'reason' => 'bulk_archive_denied'];
        }
        $ids = $payload['record_ids'] ?? null;
        $revisions = $payload['revisions'] ?? null;
        if (($payload['action_id'] ?? null) !== 'archive'
            || !is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 100
            || !is_array($revisions) || array_is_list($revisions) || count($revisions) !== count($ids)) {
            return ['answer' => 'refused', 'reason' => 'selection_invalid'];
        }
        $expected = [];
        foreach ($ids as $id) {
            if (!is_string($id) || isset($expected[$id]) || !is_int($revisions[$id] ?? null) || $revisions[$id] < 1) {
                return ['answer' => 'refused', 'reason' => 'selection_invalid'];
            }
            try {
                $record = $this->storage->readRecord($scope, $structureId, $id, $actor);
            } catch (Throwable) {
                return ['answer' => 'refused', 'reason' => 'record_unavailable'];
            }
            if ($record->state !== 'active') {
                return ['answer' => 'refused', 'reason' => 'record_not_active'];
            }
            if ($record->revision !== $revisions[$id]) {
                return ['answer' => 'conflict', 'conflicts' => [$id => $record->revision]];
            }
            $expected[$id] = $revisions[$id];
        }
        // A stale row is a conflict first; a selection that holds is archived only once the person confirmed it.
        // The view (dataview port 1.14.0) is refused with a digest of exactly this batch and the words to show, and
        // raises it again with that digest; another batch, another person or a moved revision gives another digest.
        // `confirmed` is the Storage section's own call, made after the person confirmed on its publication bar.
        if (($payload['confirmed'] ?? false) !== true) {
            $digest = hash('sha256', (string) json_encode([$actor, $structureId, 'archive', $expected]));
            $confirm = $payload['confirm'] ?? null;
            if (!is_string($confirm)) {
                return ['answer' => 'refused', 'reason' => 'confirm_required', 'data' => ['confirm' => ['digest' => $digest,
                    'summary' => $this->translator->get('larena-storage::admin.section.confirm_bulk', ['count' => (string) count($ids)])]]];
            }
            if (!hash_equals($digest, $confirm)) {
                return ['answer' => 'refused', 'reason' => 'confirm_mismatch'];
            }
        }
        $receipt = $this->gateway->dispatch($actor, $scope, 'record.bulk_archive', [
            'structure_id' => $structureId, 'confirmed' => '1', 'expected_revisions' => $expected,
        ]);
        return match ($receipt['status'] ?? null) {
            'ok' => ['answer' => 'applied', 'count' => count($ids)],
            'denied' => ['answer' => 'refused', 'reason' => 'bulk_archive_denied'],
            'rejected' => ($receipt['reason_code'] ?? null) === 'storage_workbench_revision_conflict'
                ? ['answer' => 'conflict', 'reason' => 'revision_conflict']
                : ['answer' => 'refused', 'reason' => $receipt['reason_code'] ?? 'bulk_archive_rejected'],
            default => ['answer' => 'unavailable', 'reason' => 'bulk_archive_unavailable'],
        };
    }

    /**
     * Opening, editing and archiving one row. Opening and editing only confirm
     * the row is readable and allowed; the page then shows the record card.
     *
     * @param array<string,mixed> $payload @param array<string,mixed> $base @return array<string,mixed>
     */
    private function record(string $actor, string $scope, string $structureId, string $intent, array $payload, array $base): array
    {
        $ids = $payload['record_ids'] ?? null;
        if (!is_array($ids) || count($ids) !== 1 || !is_string($ids[0] ?? null)) {
            return ['answer' => 'refused', 'reason' => 'record_ids_invalid'];
        }
        $id = $ids[0];
        try {
            $record = $this->storage->readRecord($scope, $structureId, $id, $actor);
        } catch (Throwable) {
            return ['answer' => 'refused', 'reason' => 'record_unknown'];
        }
        $action = $intent === 'record.open' ? 'view' : ($payload['action_id'] ?? null);
        $permissions = is_array($base['permissions'] ?? null) ? $base['permissions'] : [];
        $allowed = match ($action) {
            'view' => true,
            'edit' => $record->state === 'active' && ($permissions['record.update'] ?? false) === true,
            'delete', 'archive' => $record->state === 'active' && ($permissions['record.archive'] ?? false) === true,
            default => false,
        };
        if (!$allowed) {
            return ['answer' => 'refused', 'reason' => 'action_denied'];
        }
        if ($action === 'view') {
            return ['answer' => 'applied', 'record' => ['id' => $record->recordId, 'revision' => $record->revision]];
        }
        $revision = $payload['revisions'][$id] ?? null;
        if ($revision !== $record->revision) {
            return ['answer' => 'conflict', 'current_revision' => $record->revision];
        }
        if ($action === 'edit') {
            return ['answer' => 'applied', 'record' => ['id' => $record->recordId, 'revision' => $record->revision]];
        }
        $receipt = $this->gateway->dispatch($actor, $scope, 'record.archive', [
            'structure_id' => $structureId, 'record_id' => $id, 'expected_revision' => $revision, 'confirmed' => '1',
        ]);

        return match ($receipt['status'] ?? null) {
            // The answer carries the row as it now is: none, once archived out of the list (port 1.8.0).
            'ok' => ['answer' => 'applied', 'count' => 1, 'data' => ['records' => []],
                'effects' => [['type' => 'record-removed', 'value' => ['collection' => $structureId, 'id' => $id]]]],
            'denied' => ['answer' => 'refused', 'reason' => 'action_denied'],
            'rejected' => ($receipt['reason_code'] ?? null) === 'storage_workbench_revision_conflict'
                ? ['answer' => 'conflict', 'reason' => 'revision_conflict']
                : ['answer' => 'refused', 'reason' => $receipt['reason_code'] ?? 'record_rejected'],
            default => ['answer' => 'unavailable', 'reason' => 'record_unavailable'],
        };
    }
}
