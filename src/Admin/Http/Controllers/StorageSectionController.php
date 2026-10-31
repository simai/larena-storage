<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use Larena\Admin\Contracts\RecordFilterOptionsGateway;
use Larena\Admin\Contracts\RecordRelationOptionsGateway;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\StorageEncryptedFields;
use Larena\Storage\Admin\StorageEncryptionKeyStatus;
use Larena\Storage\Admin\StorageEncryptionRunState;
use Larena\Storage\Admin\StorageFieldChangeState;
use Larena\Storage\Admin\StorageSectionBindings;
use Larena\Storage\Admin\StorageSectionGateway;
use Larena\Storage\Admin\StorageSectionPublication;
use Larena\Storage\Admin\StorageSectionPublicationActions;
use Larena\Storage\Admin\StorageSitePackExports;
use Larena\Storage\Admin\StorageSortHint;
use Larena\Admin\Assets\AdminProductAssetManifest;
use Larena\Admin\Runtime\AdminControls;
use Larena\Admin\Runtime\AdminDataviewCells;
use Larena\Admin\Runtime\AdminDisclosure;
use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Admin\View\AdminDataviewPageHostComposer;
use Larena\Admin\Runtime\AdminPanelNotice;
use Larena\Admin\Runtime\RecordFilterFields;
use Larena\Storage\Admin\Runtime\StorageEncryptionPanel;
use Larena\Storage\Admin\Runtime\StorageSitePackPanel;
use Larena\Admin\Runtime\AdminLiveRegion;
use Larena\Storage\Admin\Runtime\StorageLiveRegions;
use Larena\Dataview\Enums\DataviewViewType;
use Larena\Dataview\Runtime\DataviewRoleBinder;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Admin\Runtime\AdminRecordPanel;
use Larena\Admin\Runtime\AdminRecordPanelPresenter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use Larena\Admin\Runtime\AdminFrameworkForm;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminElementDocumentRenderer;
use Larena\Admin\Runtime\AdminLiveRegionPart;
use Larena\Admin\Runtime\AdminNode;
use Larena\Admin\Runtime\AdminRenderedPart;
use Larena\Admin\Runtime\AdminSfElement;
use Illuminate\Support\HtmlString;

/**
 * The product Storage section: the list of storages, the data of one storage
 * and its settings. Every read and write goes through the owner gateway; this
 * controller keeps no state and never takes the actor or scope from input.
 */
final readonly class StorageSectionController
{
    private const MAX_INPUT_BYTES = 1048576;
    private const RECORD_PATTERN = '/\Arecord-[a-f0-9]{32}\z/D';
    private const ACTIONS = [
        'structure.create', 'structure.configure', 'structure.archive', 'structure.restore', 'structure.purge', 'structure.unbind_role',
        'record.create', 'record.update', 'record.revert', 'record.archive', 'record.restore', 'record.purge',
    ];
    private const STATE_KEYS = [
        'status', 'reason_code', 'scope_ref', 'structures', 'selected_structure', 'records',
        'files', 'documents', 'pages', 'publication', 'permissions', 'limits', 'filter_operators',
    ];

    public function __construct(
        private ConfigRepository $config,
        private ViewFactory $views,
        private Redirector $redirector,
        private Translator $translator,
        private StorageWorkbenchGateway $gateway,
        private AdminDataviewPagePresenter $dataviews,
        private AdminRecordPanelPresenter $recordPanels,
        private Container $container,
    ) {
    }

    public function index(Request $request): View|Response
    {
        $showArchived = $request->query('archived') === '1';
        $state = $this->snapshot($request, ['include_archived_structures' => $showArchived]);
        $structures = [];
        foreach (is_array($state['structures'] ?? null) ? $state['structures'] : [] as $structure) {
            if (is_array($structure) && is_string($structure['structure_id'] ?? null)
                && ($showArchived || ($structure['archived'] ?? false) !== true)) {
                $structures[] = $structure;
            }
        }

        $rows = [];
        foreach ($structures as $structure) {
            $rows[] = [
                'id' => $structure['structure_id'],
                'label' => is_string($structure['label'] ?? null) ? $structure['label'] : $structure['structure_id'],
                'structure_id' => $structure['structure_id'],
                'fields' => is_array($structure['fields'] ?? null) ? count($structure['fields']) : 0,
                'version' => 'v'.(int) ($structure['version'] ?? 0),
                'state' => $this->copy(($structure['archived'] ?? false) === true ? 'archived' : 'active'),
            ];
        }

        $base = $this->sectionPath();
        $canCreate = (($state['permissions'] ?? [])['structure.create'] ?? false) === true;

        $createPanel = null;
        if ($canCreate && $request->query('create') === '1' && ($state['status'] ?? null) === 'ok') {
            try {
                $createPanel = $this->recordPanels->present(new AdminRecordPanel(
                    mode: 'create',
                    title: $this->copy('create_storage'),
                    fields: [
                        ['key' => 'label', 'label' => $this->copy('label'), 'type' => 'string', 'type_version' => 1, 'constraints' => [], 'required' => true],
                        ['key' => 'structure_id', 'label' => $this->copy('structure_id'), 'type' => 'string', 'type_version' => 1, 'constraints' => [], 'required' => true],
                    ],
                    values: ['structure_id' => 'workbench.'],
                    closeUrl: $base.($showArchived ? '?archived=1' : ''),
                    writeUrl: $base,
                    writeFields: ['_token' => $request->hasSession() ? $request->session()->token() : '', 'action' => 'structure.create'],
                    submitLabel: $this->copy('create_storage'),
                    heading: $this->copy('create_storage'),
                    closeLabel: $this->translated('record_list.close'),
                    // The field list of the new storage is a structured value with its own registered editor.
                    structuredEditors: [[
                        'key' => 'fields',
                        'label' => $this->copy('fields'),
                        'html' => (function () use ($state): string {
                            // Three new field rows (StorageFieldEditor) and one name list for their Framework controls, placed with them in the panel's form.
                            $form = AdminFrameworkForm::start();
                            $editor = new \Larena\Storage\Admin\Runtime\StorageFieldEditor($this->translator);
                            $rows = array_map(fn (int $index): AdminNode => $editor->describe($form, $index, null, $this->recordTargets($state),
                                firstRequired: $index === 0, allowRequired: true), [0, 1, 2]);

                            return $this->draw(...[...$rows, AdminRenderedPart::control($form->names())])->toHtml();
                        })(),
                    ]],
                    // The Framework property view makes the storage (port 1.12.0); the form above stays its fallback.
                    context: ['locale' => (string) $this->translator->getLocale(), 'structure_id' => \Larena\Storage\Admin\StorageStructurePropertyViewPort::COLLECTION]
                        + ($this->config->get('larena-admin.property_view.enabled', false) === true && is_string($state['scope_ref'] ?? null)
                            ? ['property_port' => $this->sectionPath().'/property-port', 'scope_ref' => $state['scope_ref']] : []),
                ), AdminProductAssetManifest::dataviewActivation());
            } catch (Throwable) {
                $createPanel = null;
            }
        }

        return $this->render('larena-storage::admin.index', $state, [
            'structures' => $structures,
            'showArchived' => $showArchived,
            'createPanel' => $createPanel,
            'dataview' => $this->dataview(new AdminDataviewPage(
                instanceId: 'storage-list-data-view',
                title: $this->copy('storages'),
                columns: [
                    // The storage's name opens the storage.
                    ['key' => 'label', 'label' => $this->copy('label'), 'role' => 'title'],
                    ['key' => 'structure_id', 'label' => $this->copy('structure_id')],
                    ['key' => 'fields', 'label' => $this->copy('field_count')],
                    ['key' => 'version', 'label' => $this->copy('version')],
                    ['key' => 'state', 'label' => $this->copy('state')],
                ],
                total: count($rows),
                pageId: 'page.admin.storage',
                rows: $rows,
                rowLinks: ['view' => $base.'/__ID__']
                    + ((($state['permissions'] ?? [])['structure.configure'] ?? false) === true ? ['edit' => $base.'/__ID__/settings'] : []),
                createLink: $canCreate ? $base.'?'.($showArchived ? 'archived=1&' : '').'create=1' : null,
                settings: true,
                actions: $canCreate,
                countLabel: $this->translated('record_list.record_count'),
                tableViewLabel: $this->translated('record_list.table_view'),
                headerActions: [[
                    'text' => $this->copy($showArchived ? 'hide_archive' : 'show_archive'),
                    'href' => $base.($showArchived ? '' : '?archived=1'),
                    'attributes' => ['data-larena-storage-archive-toggle' => ''],
                ], ...$this->sitePackImportAction($state)],
            )),
        ]);
    }

    public function records(Request $request, string $structure): View|Response|JsonResponse
    {
        $archiveView = $request->query('view') === 'archive';
        // The field change notice reads itself again while the change runs: it alone, without the list.
        if (AdminLiveRegion::asked($request) === StorageLiveRegions::FIELD_CHANGE && !$archiveView) {
            return $this->fieldChangeAnswer($request, $structure);
        }
        $recordId = $this->recordId($request->query('record_id'));
        $mode = $request->query('record_mode');
        $mode = in_array($mode, ['view', 'edit', 'create'], true) ? $mode : null;
        // A record address without a mode opens the record for viewing.
        $mode ??= $recordId !== null ? 'view' : null;
        // One record on its own page (record_page=1): the record panel stands in the work area, no list.
        $recordPage = $request->query('record_page') === '1' && $recordId !== null && in_array($mode, ['view', 'edit'], true);
        $query = ['structure_id' => $structure, 'include_archived_structures' => true];
        if ($archiveView) {
            $query += ['include_archived' => true, 'per_page' => 100];
        }
        if ($recordId !== null) {
            $query += ['record_id' => $recordId, 'include_archived' => true];
        }
        // «Count exactly» under an approximate count asks for the list again with an exact count.
        if ($request->query('count') === 'exact') {
            $query += ['count' => 'exact'];
        }
        $state = $this->snapshot($request, $query);
        // A large storage's list is still being built in the background: the page says so, and the rest (the
        // storage's settings, a record opened by its address, a new record) keeps working without the list.
        $listNotReady = ($state['status'] ?? null) === 'unavailable' && ($state['reason_code'] ?? null) === StorageSectionGateway::VIEW_NOT_READY;
        if ($listNotReady) {
            $state = $this->snapshot($request, $query + ['records' => 'skip']);
        }
        $selected = $this->selected($state, $structure);
        $records = is_array($state['records'] ?? null) ? $state['records'] : [];
        $history = is_array($records['history'] ?? null) ? $records['history'] : [];
        $current = is_array($history[0] ?? null) && ($history[0]['record_id'] ?? null) === $recordId ? $history[0] : null;
        $archived = [];
        foreach (is_array($records['items'] ?? null) ? $records['items'] : [] as $record) {
            if (is_array($record) && ($record['state'] ?? null) === 'archived') {
                $archived[] = $record;
            }
        }
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        $fields = $selected === null ? [] : $this->fields($selected);
        $structureArchived = is_array($selected) && ($selected['archived'] ?? false) === true;
        $recordsPath = $this->sectionPath().'/'.rawurlencode($structure);
        $listPath = $recordsPath.($archiveView ? '?view=archive' : '');

        $archiveRows = [];
        foreach ($archived as $record) {
            // The revision the row's restore and permanent deletion are written against.
            $row = ['id' => $record['record_id'], '_revision' => (string) ($record['revision'] ?? '')];
            foreach ($fields as $field) {
                $row[$field['key']] = $this->displayValue($record['values'][$field['key']] ?? null, $field);
            }
            $archiveRows[] = $row;
        }
        $headerActions = [];
        $headerActions[] = $archiveView
            ? ['text' => $this->copy('back_to_records'), 'href' => $recordsPath]
            : ['text' => $this->copy('archive_view'), 'href' => $recordsPath.'?view=archive',
                'attributes' => ['data-larena-toolbar-action' => 'archive', 'data-larena-toolbar-icon' => 'inventory_2']];
        $headerActions[] = ['text' => $this->copy('settings'), 'href' => $recordsPath.'/settings',
            'attributes' => ['data-larena-toolbar-action' => 'settings', 'data-larena-toolbar-icon' => 'tune']];
        // Beside the list, as on the settings page: the storage's own properties and the way back to the storages.
        $headerActions[] = ['text' => $this->copy('storage_properties'),
            'href' => $recordsPath.'/settings?record_id='.rawurlencode($structure).'&record_mode=view'];
        $headerActions[] = ['text' => $this->copy('back_to_list'), 'href' => $this->sectionPath()];
        // The records as CSV, as the list shows them: the page adds the list's query to the address.
        if (!$archiveView) {
            $headerActions[] = ['text' => $this->copy('export_csv'), 'href' => $recordsPath.'/export.csv',
                'attributes' => ['data-larena-toolbar-action' => 'export', 'data-larena-toolbar-icon' => 'download']];
            // A large list is exported by the queue: the jobs page offers it with the list's query and keeps the file.
            if ($this->backgroundJobs() !== null) {
                $headerActions[] = ['text' => $this->copy('jobs.export_action'), 'href' => $recordsPath.'/jobs',
                    'attributes' => ['data-larena-toolbar-action' => 'export-queued', 'data-larena-toolbar-icon' => 'schedule']];
            }
            // And back: a file adds records and updates those it names by ID.
            if (!$structureArchived
                && (($permissions['record.create'] ?? false) === true || ($permissions['record.update'] ?? false) === true)) {
                $headerActions[] = ['text' => $this->copy('import_csv'), 'href' => $recordsPath.'/import',
                    'attributes' => ['data-larena-toolbar-action' => 'import', 'data-larena-toolbar-icon' => 'upload']];
            }
        }
        // The same columns the host port answers with: which sort, and which one names a record.
        $titleKey = (new DataviewRoleBinder)->bind(DataviewViewType::Table,
            array_map('strval', array_column($fields, 'type', 'key')), ['title' => 'title'])['bindings']['title'] ?? null;
        $columns = array_map(fn (array $field): array => [
            'key' => $field['key'],
            'label' => is_string($field['label'] ?? null) ? $field['label'] : $field['key'],
            'sortable' => RecordFilterFields::sortable($field),
        ] + ($field['key'] === $titleKey ? ['role' => 'title'] : []), $fields);
        // Column settings for the person are always kept; saved views, and views and settings for
        // everyone, are offered when the host grants them (data view port 1.4.0).
        // From data view port 1.10.0 «Create» and the person's column settings are offered only when the host
        // names them: «Create» to a person who may create records in a storage that is not archived.
        $capabilities = array_values(array_filter([
            'row-actions',
            'personal-column-settings',
            ($permissions['record.create'] ?? false) === true && !$structureArchived && !$archiveView ? 'create-record' : null,
            ($permissions['dataview.saved_view.save'] ?? false) === true ? 'saved-views' : null,
            ($permissions['dataview.saved_view.save_shared'] ?? false) === true ? 'shared-saved-views' : null,
            ($permissions['dataview.preferences.save_shared'] ?? false) === true ? 'shared-column-settings' : null,
            ($permissions['record.bulk_archive'] ?? false) === true && ($selected['archived'] ?? false) !== true && !$archiveView ? 'bulk-under-filter' : null,
        ]));
        // A storage bound to a publishable role: its list gets the badges and «Опубликовать» from its own host port.
        $publishable = $selected !== null && !$archiveView && !$structureArchived && !$listNotReady && is_string($state['scope_ref'] ?? null)
            ? $this->publishable($request, $state['scope_ref'], $structure) : null;
        $dataview = null;
        if ($selected !== null && $columns !== [] && !$listNotReady && $publishable !== null) {
            $dataview = $this->dataview($this->publicationList($structure, $recordsPath, $records, $columns, $permissions, $headerActions, $publishable,
                ($permissions['record.create'] ?? false) === true ? $this->createItems($state, $selected, $structure) : [], $this->firstQuery($request)));
        } elseif ($selected !== null && $columns !== [] && !$listNotReady) {
            $bulk = ($permissions['record.bulk_archive'] ?? false) === true && !$structureArchived;
            $canCreate = ($permissions['record.create'] ?? false) === true && !$structureArchived;
            $createItems = $canCreate ? $this->createItems($state, $selected, $structure) : [];
            $dataview = $this->dataview($archiveView
                ? new AdminDataviewPage(
                    instanceId: 'storage-archive-'.substr(hash('sha256', $structure), 0, 12),
                    title: $this->copy('archive_view'),
                    columns: $columns,
                    total: count($archiveRows),
                    pageId: 'page.admin.storage-records',
                    rows: $archiveRows,
                    rowLinks: ['view' => '?record_id=__ID__&record_mode=view#admin-record-view'],
                    // Restore and permanent deletion straight from the row menu, without opening the record.
                    rowWrites: $this->archiveRowWrites($request, $recordsPath, $permissions, $structureArchived),
                    settings: true,
                    countLabel: $this->translated('record_list.record_count'),
                    tableViewLabel: $this->translated('record_list.table_view'),
                    headerActions: $headerActions,
                )
                : new AdminDataviewPage(
                    instanceId: 'storage-section-'.substr(hash('sha256', $structure), 0, 12),
                    title: $this->copy('records'),
                    columns: $columns,
                    total: max(0, (int) ($records['matched_count'] ?? 0)),
                    pageId: 'page.admin.storage-records',
                    portUrl: $this->sectionPath().'/port',
                    selectable: $bulk,
                    settings: true,
                    // The toolbar create button opens the record panel; without the right it is not offered.
                    actions: $canCreate,
                    createItems: $createItems,
                    bulkActions: $bulk ? ['bulk_delete' => $this->copy('bulk_archive')] : [],
                    // «For all»: everything the list's filters and search find, read again and counted by the host.
                    forAllLabel: $bulk ? $this->translated('record_list.for_all') : null,
                    applyText: $this->copy('apply'),
                    capabilities: $capabilities,
                    rowLinks: [
                        'view' => '?record_id=__ID__&record_mode=view#admin-record-view',
                        'edit' => '?record_id=__ID__&record_mode=edit#admin-record-editor',
                    ],
                    countLabel: $this->translated('record_list.record_count'),
                    tableViewLabel: $this->translated('record_list.table_view'),
                    headerActions: $headerActions,
                    // The first query as the page's query form holds it (the shared data view page: its pagination query
                    // from the address; no other query field), so the list declares the call it sends first.
                    firstQuery: is_string($state['scope_ref'] ?? null) && $state['scope_ref'] !== '' ? $this->firstQuery($request) : null,
                    portFields: ['scope_ref' => is_string($state['scope_ref'] ?? null) ? $state['scope_ref'] : '', 'structure_id' => $structure],
                ));
        }

        $panel = null;
        if ($selected !== null) {
            $panel = $this->recordPanel($request, $selected, $structure, $permissions, $mode, $current, $history, $listPath, $archiveView,
                is_string($state['scope_ref'] ?? null) ? $state['scope_ref'] : null, $recordPage,
                $mode === 'view' && !$structureArchived && is_string($state['scope_ref'] ?? null) ? $publishable ?? $this->publishable($request, $state['scope_ref'], $structure) : null);
        }
        // The list asks for the record panel alone when a record opens over it (X-Larena-Fragment: record-panel).
        if ($request->headers->get('X-Larena-Fragment') === 'record-panel') {
            $html = is_string($panel['html'] ?? null) ? $panel['html'] : '';
            $notice = AdminPanelNotice::fromSession($request, $this->translator, AdminProductAssetManifest::dataviewActivation());
            $assets = is_array($panel['asset_tags'] ?? null) ? array_values(array_filter($panel['asset_tags'], 'is_string')) : [];

            return new JsonResponse([
                'html' => $html,
                'asset_tags' => array_values(array_unique([...$assets, ...$notice['asset_tags']])),
                'mode' => $mode ?? 'list',
                'notice_html' => $notice['html'],
                'notice' => isset($notice['tone'], $notice['text']) ? ['tone' => $notice['tone'], 'text' => $notice['text']] : null,
            ], $html === '' ? 404 : 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        $this->breadcrumbTail($request, $selected, $structure, $recordPage && $current !== null && $selected !== null ? $this->recordTitle($current, $selected) : null);

        return $this->render('larena-storage::admin.records', $state, [
            'dataview' => $dataview,
            'recordPanel' => $panel,
            'recordPage' => $recordPage && $panel !== null,
            // An archived storage says so above its records: a Framework alert drawn by the shared renderer.
            'archivedNotice' => $this->archivedNotice($selected),
            // On its own page the record names the page; the storage is the line under it.
            'recordPageActions' => $this->dataviews->heading([['text' => $this->copy('back_to_records'), 'href' => $listPath]])->actions,
            'recordTitle' => $recordPage && $current !== null && $selected !== null ? $this->recordTitle($current, $selected) : null,
            // The heading buttons when no list can be shown here: the same buttons, drawn by the same renderer.
            'headerActions' => $this->dataviews->heading($headerActions)->actions,
            'selected' => $selected,
            'structureId' => $structure,
            'fields' => $fields,
            'archiveView' => $archiveView,
            'archivedRecords' => $archived,
            'dataViewFilterFields' => $this->filterFields($request, $fields, $structure,
                is_string($state['scope_ref'] ?? null) ? $state['scope_ref'] : null,
                is_array($state['filter_operators'] ?? null) ? $state['filter_operators'] : []),
            'matchedCount' => max(0, (int) ($records['matched_count'] ?? 0)),
            'listNotice' => $listNotReady ? $this->listNotice(StorageSectionGateway::VIEW_NOT_READY) : null,
            // A list that cannot exist here is an operator's matter, said in its own words.
            'stateNotice' => ($state['reason_code'] ?? null) === StorageSectionGateway::VIEW_UNAVAILABLE ? $this->listNotice(StorageSectionGateway::VIEW_UNAVAILABLE) : null,
            // A large list is counted approximately (owner decision 2026-10-20): «~N» and «count exactly».
            'countNotice' => !$archiveView && $selected !== null && ($records['matched_count_exact'] ?? true) === false
                ? ['text' => (string) $this->translator->get('larena-storage::admin.section.count_approximate',
                    ['count' => number_format(max(0, (int) ($records['matched_count'] ?? 0)), 0, ',', "\u{00A0}")]),
                    'link' => $this->copy('count_exactly'), 'href' => $recordsPath.'?count=exact']
                : null,
            // A large list sorted by a field without an index: a hint, with the field's settings for who may change them.
            // The list's port carries the same hint (data.sort_hint) when the sort changes in place.
            'sortHint' => $archiveView ? null : (new StorageSortHint($this->translator))->hint($records, $selected,
                ($permissions['structure.configure'] ?? false) === true && !$structureArchived, $recordsPath.'/settings'),
            // A field change running in the background (its progress), or the last one that failed or was cancelled.
            'fieldChangeRegion' => $selected === null || $archiveView ? '' : $this->fieldChangeRegion(
                $this->fieldChangeNotice($request, $selected, $structure, $permissions, 'records'), $recordsPath),
            // A host port list connects itself; the records port's bridge asks for a port address on the form.
            'portUrl' => $publishable !== null ? null : $this->sectionPath().'/port',
            'portCapabilities' => $publishable !== null ? StorageSectionPublicationPortController::capabilities($permissions) : $capabilities,
            'bulkAll' => $publishable === null && !$archiveView && $selected !== null && ($permissions['record.bulk_archive'] ?? false) === true
                && ($selected['archived'] ?? false) !== true,
            'addressQuery' => $archiveView ? [] : $this->addressQuery($request),
        ]);
    }

    /**
     * The publication this person sees of a storage: its binding and what they may do, or null for a storage without
     * a publishable role.
     *
     * @return array{binding: array{schema: string, site: string}, can: array<string, bool>, actions: StorageSectionPublicationActions}|null
     */
    private function publishable(Request $request, string $scope, string $structure): ?array
    {
        $actions = StorageSectionBindings::publicationActions($this->container);
        $binding = $actions?->binding($this->actor($request), $scope, $structure);

        return $actions === null || $binding === null ? null : ['binding' => $binding, 'can' => $actions->can($this->actor($request)), 'actions' => $actions];
    }

    /**
     * The records list of a publishable storage: the same columns with the badges, and the actions for checked rows.
     *
     * @param array<string, mixed> $records @param list<array<string, mixed>> $columns
     * @param array<string, mixed> $permissions @param list<array<string, mixed>> $headerActions
     * @param array{binding: array{schema: string, site: string}, can: array<string, bool>, actions: StorageSectionPublicationActions} $publishable
     */
    private function publicationList(string $structure, string $recordsPath, array $records, array $columns, array $permissions, array $headerActions, array $publishable, array $createItems, ?array $firstQuery): AdminDataviewPage
    {
        $publication = $publishable['actions']->publication();
        $archive = ($permissions['record.bulk_archive'] ?? false) === true;
        $bulk = $archive ? ['bulk_delete' => $this->copy('bulk_archive')] : [];
        $bulk += $publication->bulkActions($publishable['can'], $publishable['binding']['site']);
        $canCreate = ($permissions['record.create'] ?? false) === true;

        return new AdminDataviewPage(
            instanceId: 'storage-section-'.substr(hash('sha256', $structure), 0, 12),
            title: $this->copy('records'),
            columns: [...array_slice($columns, 0, 2), ['key' => StorageSectionPublication::COLUMN, 'label' => $publication->word('larena-admin::structure_editors.publication'), 'sortable' => false], ...array_slice($columns, 2)],
            total: max(0, (int) ($records['matched_count'] ?? 0)),
            pageId: 'page.admin.storage-records',
            portUrl: $recordsPath.'/publication-port',
            selectable: $bulk !== [],
            settings: true,
            actions: $canCreate,
            createItems: $createItems,
            bulkActions: $bulk,
            // «For all»: everything the list's filters and search find, read again and counted by the host.
            forAllLabel: $bulk !== [] ? $this->translated('record_list.for_all') : null,
            applyText: $this->copy('apply'),
            countLabel: $this->translated('record_list.record_count'),
            tableViewLabel: $this->translated('record_list.table_view'),
            headerActions: $headerActions,
            firstQuery: $firstQuery,
            portKind: 'host',
            portCapabilities: StorageSectionPublicationPortController::capabilities($permissions),
            hostPanels: ['view' => '?record_id=__ID__&record_mode=view#admin-record-view', 'edit' => '?record_id=__ID__&record_mode=edit#admin-record-editor']
                + ($canCreate ? ['create' => '?record_mode=create#admin-record-editor'] : []),
        );
    }

    /**
     * «Create» offers this storage's record and, for each «Storage record» field, a record of the storage it points to,
     * opened on that storage's own page; an archived target is not offered.
     *
     * @param array<string, mixed> $state @param array<string, mixed> $selected
     * @return list<array<string, string>>
     */
    private function createItems(array $state, array $selected, string $structure): array
    {
        $active = [];
        foreach (is_array($state['structures'] ?? null) ? $state['structures'] : [] as $listed) {
            if (is_array($listed) && ($listed['archived'] ?? false) !== true && is_string($listed['structure_id'] ?? null)) {
                $active[$listed['structure_id']] = true;
            }
        }
        $createItems = [];
        foreach ($this->fields($selected) as $field) {
            $target = ($field['type'] ?? null) === 'record' ? ($field['constraints']['target_structure_id'] ?? null) : null;
            if (!is_string($target) || $target === $structure || !isset($active[$target])
                || isset($createItems[$target]) || preg_match('/^[a-z][a-z0-9_]{0,40}$/', (string) ($field['key'] ?? '')) !== 1) {
                continue;
            }
            $createItems[$target] = ['id' => 'field-'.str_replace('_', '-', (string) $field['key']),
                'name' => is_string($field['label'] ?? null) && trim($field['label']) !== '' ? trim($field['label']) : (string) $field['key'],
                'href' => $this->sectionPath().'/'.rawurlencode($target).'?record_mode=create#admin-record-editor'];
        }

        return $createItems === [] ? [] : [['id' => 'record', 'name' => $this->translated('record_list.create_item_record')], ...array_values($createItems)];
    }

    /**
     * The first query as the page's query form holds it (the shared data view page: its pagination query from the
     * address; no other query field), so the list declares the call it sends first.
     *
     * @return array<string, mixed>|null
     */
    private function firstQuery(Request $request): ?array
    {
        return AdminDataviewPage::firstQueryOf(
            (array) json_decode(AdminDataviewPageHostComposer::host([], [], $this->addressQuery($request))['pagination_query'], true), [],
        );
    }

    /**
     * What the records page says instead of the list: it is still being built (a large storage, in the background),
     * or it cannot exist here.
     *
     * @return array{reason: string, title: string, text: string, alert: HtmlString}
     */
    private function listNotice(string $reason): array
    {
        $ready = $reason === StorageSectionGateway::VIEW_NOT_READY;
        $key = $ready ? 'list_not_ready' : 'list_unavailable';
        $title = $this->copy($key.'_title');
        $text = $this->copy($key.'_text');

        return ['reason' => $reason, 'title' => $title, 'text' => $text,
            // A list being prepared is news, not a failure; one that cannot exist here is a warning.
            'alert' => $this->draw(AdminSfElement::alert($text, $ready ? 'info' : 'warning', $title))];
    }

    /**
     * «Хранилище в архиве» above an archived storage's records and settings, else nothing.
     *
     * @param array<string, mixed>|null $selected
     */
    private function archivedNotice(?array $selected): ?HtmlString
    {
        return is_array($selected) && ($selected['archived'] ?? false) === true
            ? $this->draw(AdminSfElement::alert($this->copy('storage_archived_notice'), 'warning')) : null;
    }

    /**
     * «Архивировать», «Восстановить» and «Удалить навсегда» of a storage's settings page, each a titled form drawn by
     * the shared renderer, for a person with the right; the Framework controls are drawn by AdminFrameworkForm.
     *
     * @param array<string, mixed> $selected @param array<string, mixed> $permissions
     */
    private function structureActions(Request $request, array $selected, string $structure, array $permissions, ?string $structureLocked): HtmlString
    {
        $archived = ($selected['archived'] ?? false) === true;
        // The section's own address of the storage (route larena.admin.storage.structure.dispatch), as its other forms post.
        $action = $this->sectionPath().'/'.rawurlencode($structure);
        $token = $request->hasSession() ? (string) $request->session()->token() : '';
        $form = function (string $marker, string $operation, array $controls) use ($action, $token, $selected): AdminElement {
            return new AdminElement('form', ['method' => 'post', 'action' => $action, $marker => null], [
                new AdminElement('input', ['type' => 'hidden', 'name' => '_token', 'value' => $token, 'autocomplete' => 'off']),
                ...AdminElement::hiddenInputs(['action' => $operation, 'payload[expected_version]' => (string) ($selected['version'] ?? '')]),
                ...array_map(static fn (string $html): AdminRenderedPart => AdminRenderedPart::control($html), $controls),
            ]);
        };
        $section = static fn (string $id, string $title, array $parts): AdminElement => new AdminElement('section', ['class' => 'larena-panel', 'aria-labelledby' => $id],
            [new AdminElement('h2', ['id' => $id], $title), ...$parts]);
        $parts = [];
        if (!$archived && ($permissions['structure.archive'] ?? false) === true && $structureLocked === null) {
            $controls = AdminFrameworkForm::start();
            $parts[] = $section('storage-archive-title', $this->copy('archive_storage'), [
                new AdminElement('p', [], $this->copy('archive_storage_text')),
                $form('data-larena-storage-archive-structure', 'structure.archive', [
                    $controls->checkbox('payload[confirmed]', $this->copy('confirm_archive'), '1', false),
                    $controls->submit($this->copy('archive'), false),
                    $controls->names(),
                ]),
            ]);
        }
        // A storage bound to a publishable role can go back to being an ordinary one, and only then be removed for good.
        $bound = ($permissions['structure.configure'] ?? false) === true ? $this->boundRole($request, $selected) : null;
        if ($bound !== null) {
            $parts[] = $section('storage-role-title', $this->copy('unbind_role'), [
                new AdminElement('p', [], $this->copy('unbind_role_text')),
                $form('data-larena-storage-unbind-role', 'structure.unbind_role', [
                    ...array_map(static fn (AdminNode $input): string => (string) (new AdminElementDocumentRenderer())->tree($input), AdminElement::hiddenInputs(['payload[role_ref]' => $bound['role'], 'payload[site_ref]' => $bound['site']])),
                    AdminFrameworkForm::start()->submit($this->copy('unbind_role_action'), false),
                ]),
            ]);
        }
        if ($archived && ($permissions['structure.restore'] ?? false) === true) {
            $parts[] = $section('storage-restore-title', $this->copy('restore_storage'), [
                $form('data-larena-storage-restore-structure', 'structure.restore', [AdminFrameworkForm::start()->submit($this->copy('restore'))]),
            ]);
        }
        if ($archived && ($permissions['structure.purge'] ?? false) === true) {
            $controls = AdminFrameworkForm::start();
            // A typed ID that did not match comes back with its reason under the field (dispatch()).
            $bag = $request->hasSession() ? $request->session()->get('errors') : null;
            $mismatch = $bag instanceof \Illuminate\Support\ViewErrorBag ? $bag->first('confirm_structure_id') : '';
            $parts[] = $section('storage-purge-title', $this->copy('purge_storage'), [
                new AdminElement('p', [], $this->copy('purge_storage_text')),
                $form('data-larena-storage-purge-structure', 'structure.purge', [
                    $controls->input('payload[confirm_structure_id]', $this->copy('structure_id'), '', true,
                        error: $mismatch !== '' ? $mismatch : null),
                    $controls->submit($this->copy('purge'), false),
                    $controls->names(),
                ]),
            ]);
        }

        return $this->draw(...$parts);
    }

    /**
     * The publishable role the storage is bound to, and in which site.
     *
     * @param array<string, mixed> $selected
     * @return array{role: string, site: string}|null
     */
    private function boundRole(Request $request, array $selected): ?array
    {
        $structure = is_string($selected['structure_id'] ?? null) ? $selected['structure_id'] : null;
        $scope = $this->gateway->snapshot($this->actor($request), null, ['surface' => 'storage_section', 'records' => 'skip'])['scope_ref'] ?? null;

        return $structure === null || !is_string($scope) ? null : StorageSectionBindings::publicationActions($this->container)?->boundRole($this->actor($request), $scope, $structure);
    }

    /** Described page parts drawn by the shared element Document renderer, for the view to print with {{ }}. */
    private function draw(AdminNode ...$nodes): HtmlString
    {
        return (new AdminElementDocumentRenderer())->tree(...$nodes);
    }

    /**
     * The list opened with a search and filters from its address, in the shape the Larena CMS list
     * reads: `search` as text and `filters` as one JSON object of field => list of values or
     * {operator, value|values}. The list's port checks every filter against its fields and reports
     * what it drops; an address that does not fit opens the plain list.
     *
     * @return array{search?:string,filters?:string}
     */
    private function addressQuery(Request $request): array
    {
        $query = [];
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '' && strlen($search) <= 100) {
            $query['search'] = trim($search);
        }
        $filters = $request->query('filters');
        if (is_string($filters) && $filters !== '' && strlen($filters) <= 8192) {
            try {
                $decoded = json_decode($filters, true, 6, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decoded = null;
            }
            if (is_array($decoded) && $decoded !== [] && !array_is_list($decoded) && count($decoded) <= 20) {
                $query['filters'] = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return $query;
    }

    /**
     * The record panel over the list (larena.admin-dataview-page 1.1): viewing,
     * editing and creating a record, its archive, restore and permanent deletion.
     *
     * @param array<string, mixed> $selected
     * @param array<string, mixed> $permissions
     * @param array<string, mixed>|null $current
     * @param list<mixed> $history
     * @return array{html:string,asset_tags:list<string>}|null
     */
    private function recordPanel(Request $request, array $selected, string $structure, array $permissions, ?string $mode, ?array $current, array $history, string $listPath, bool $archiveView, ?string $scopeRef = null, bool $recordPage = false, ?array $publishable = null): ?array
    {
        $structureArchived = ($selected['archived'] ?? false) === true;
        $recordsPath = $this->sectionPath().'/'.rawurlencode($structure);
        // On the record's own page, editing and cancelling stay on that page.
        $base = $recordsPath.($archiveView ? '?view=archive&' : '?').($recordPage ? 'record_page=1&' : '');
        $token = $request->hasSession() ? $request->session()->token() : '';
        $writeUrl = $this->sectionPath().'/'.rawurlencode($structure);
        $common = [
            'fields' => $this->fields($selected, true),
            'closeUrl' => $listPath,
            'writeUrl' => $writeUrl,
            'heading' => $this->copy('record'),
            'closeLabel' => $this->translated('record_list.close'),
            'editLabel' => $this->copy('edit'),
            'cancelLabel' => $this->translated('record_list.cancel'),
            'hiddenFieldsLabel' => $this->copy('hidden_fields'),
            'historyLabel' => $this->copy('history'),
            'context' => ($recordPage ? ['inline' => true] : []) + [
                'copy' => ['yes' => $this->copy('yes'), 'no' => $this->copy('no'),
                    'user_unavailable' => $this->translated('record_list.user_unavailable'),
                    'file_unavailable' => $this->translated('record_list.file_unavailable'),
                    'record_unavailable' => $this->translated('record_list.record_unavailable'),
                    'relation_unavailable' => $this->translated('record_list.relation_unavailable'),
                    'reference_choose' => $this->translated('record_list.reference_choose'),
                    'reference_load_more' => $this->translated('record_list.reference_load_more'),
                    'choose_record' => $this->translated('record_list.choose_record')],
                'locale' => (string) $this->translator->getLocale(),
                // The foot bar's delete moves the record to the archive, and says so.
                'delete_text' => $this->copy('archive'),
                // An encrypted field reads «•••• зашифровано» in the property view.
                ...(StorageEncryptedFields::keys($this->fields($selected, true)) !== [] ? ['sealed_text' => StorageEncryptedFields::maskText()] : []),
                // User and file fields choose from the owner-scoped option source; a relation needs
                // its owner (Content) and stays as it is while that owner is absent.
                ...$this->referenceSources($request, $structure, $scopeRef),
            ],
        ];
        // A save refused a moment ago comes back with what was typed in the form.
        $draft = $request->hasSession() ? $request->session()->get('larena_storage_record_draft') : null;
        $draft = is_array($draft) && ($draft['structure_id'] ?? null) === $structure ? $draft : null;
        $typed = static fn (array $values): ?array => AdminRecordPanel::draftValues(static fn (string $name): mixed => $request->old($name), $values);
        if ($mode === 'create') {
            if ($structureArchived || ($permissions['record.create'] ?? false) !== true) {
                return null;
            }
            $panel = new AdminRecordPanel(...[...$common,
                'mode' => 'create',
                'title' => $this->copy('create_record'),
                'values' => ($created = ($draft['action'] ?? null) === 'record.create' ? $typed([]) : null) ?? [],
                'draft' => $created !== null,
                'writeFields' => ['_token' => $token, 'action' => 'record.create'],
                'submitLabel' => $this->copy('create_record'),
            ]);
        } elseif ($current !== null && in_array($mode, ['view', 'edit'], true)) {
            $recordId = (string) $current['record_id'];
            $revision = (int) $current['revision'];
            $active = ($current['state'] ?? null) === 'active';
            $canEdit = $active && !$structureArchived && ($permissions['record.update'] ?? false) === true;
            $lifecycle = [];
            $identity = ['_token' => $token, 'payload[record_id]' => $recordId, 'payload[expected_revision]' => $revision];
            // «Опубликовать» and «Снять с публикации» per language, for a storage bound to a publishable role.
            if ($active && $publishable !== null) {
                $lifecycle = $this->publicationLifecycle($publishable, $recordId, $revision, $identity);
            }
            if ($active && !$structureArchived && ($permissions['record.archive'] ?? false) === true) {
                $lifecycle[] = ['label' => $this->copy('archive'), 'write_fields' => ['action' => 'record.archive'] + $identity,
                    'confirm' => $this->copy('confirm_archive'), 'danger' => true];
            }
            if (!$active && !$structureArchived && ($permissions['record.restore'] ?? false) === true) {
                $lifecycle[] = ['label' => $this->copy('restore'), 'write_fields' => ['action' => 'record.restore'] + $identity];
            }
            if (!$active && ($permissions['record.purge'] ?? false) === true) {
                $lifecycle[] = ['label' => $this->copy('purge'), 'danger' => true, 'confirm' => $this->copy('confirm_purge'),
                    'write_fields' => ['_token' => $token, 'action' => 'record.purge', 'payload[expected_revisions]['.$recordId.']' => $revision]];
            }
            // Every saved version, newest first: who, when and what; an earlier one comes back with «Restore»
            // as a new revision, so nothing is lost by it.
            $versions = [];
            $canRevert = $canEdit && ($permissions['record.revert'] ?? false) === true;
            $names = $this->identityLabels();
            $encrypted = array_values(array_filter($this->fields($selected, true), StorageEncryptedFields::isEncrypted(...)));
            $sealedChanges = $this->sealedChanges($history, $encrypted);
            foreach ($history as $position => $revisionItem) {
                if (!is_array($revisionItem) || !is_int($revisionItem['revision'] ?? null)) {
                    continue;
                }
                $actor = is_string($revisionItem['actor'] ?? null) ? $revisionItem['actor'] : '';
                $operation = is_string($revisionItem['operation'] ?? null) ? $revisionItem['operation'] : '';
                $versions[] = [
                    'label' => implode(' · ', array_filter([
                        'r'.$revisionItem['revision'],
                        self::when((string) ($revisionItem['updated_at'] ?? '')),
                        in_array($operation, ['create', 'update', 'archive', 'restore', 'schema_migration'], true) ? $this->copy('operation_'.$operation) : '',
                        $actor !== '' ? ($names?->resolve($actor) ?? '') : '',
                        $sealedChanges[$position] ?? '',
                    ], static fn (string $part): bool => $part !== '')),
                    'write_fields' => $canRevert && $revisionItem['revision'] !== $revision
                        ? ['_token' => $token, 'action' => 'record.revert', 'payload[record_id]' => $recordId,
                            'payload[expected_revision]' => (string) $revision, 'payload[from_revision]' => (string) $revisionItem['revision']]
                        : null,
                ];
            }
            $values = is_array($current['values'] ?? null) ? $current['values'] : [];
            $restored = $mode === 'edit' && $canEdit && ($draft['action'] ?? null) === 'record.update' && ($draft['record_id'] ?? null) === $recordId
                ? $typed($values) : null;
            if ($mode === 'view') {
                $common['context'] += $this->sealedContext($request, $structure, $recordId, $encrypted, $values);
            }
            $panel = new AdminRecordPanel(...[...$common,
                'mode' => $mode === 'edit' && $canEdit ? 'edit' : 'view',
                'title' => $this->recordTitle($current, $selected),
                'values' => $restored ?? $values,
                'draft' => $restored !== null,
                'writeFields' => ['_token' => $token, 'action' => 'record.update', 'payload[record_id]' => $recordId, 'payload[expected_revision]' => $revision]
                    + ($recordPage ? ['record_page' => '1'] : []),
                'recordId' => $recordId,
                'revision' => $revision,
                'editUrl' => $canEdit ? $base.'record_id='.rawurlencode($recordId).'&record_mode=edit#admin-record-editor' : null,
                'cancelUrl' => $base.'record_id='.rawurlencode($recordId).'&record_mode=view#admin-record-view',
                'submitLabel' => $this->copy('save_record'),
                'lifecycle' => $lifecycle,
                'versions' => $versions,
                'versionsLabel' => $this->copy('versions'),
                'restoreLabel' => $this->copy('restore_version'),
                'restoreHint' => $this->copy('restore_version_hint'),
                // Where the property view reads records, it names the publication among the record's fields (StoragePropertyViewPort).
                'notice' => !$active ? $this->copy('archived')
                    : ($publishable !== null && $this->config->get('larena-admin.property_view.enabled', false) !== true
                        ? $this->publicationWord($publishable, 'larena-admin::structure_editors.publication').': '.$publishable['actions']->publication()->line($publishable['binding']['schema'], $recordId, $publishable['binding']['site'])
                        : null),
            ]);
        } else {
            return null;
        }
        try {
            return $this->recordPanels->present($panel, AdminProductAssetManifest::dataviewActivation());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The record card's «Опубликовать · ru» and «Снять с публикации · ru»: the actions the row menu offers this record.
     *
     * @param array{binding: array{schema: string, site: string}, can: array<string, bool>, actions: StorageSectionPublicationActions} $publishable
     * @param array<string, int|string> $identity
     * @return list<array<string, mixed>>
     */
    private function publicationLifecycle(array $publishable, string $recordId, int $revision, array $identity): array
    {
        $publication = $publishable['actions']->publication();
        $lifecycle = [];
        foreach ($publication->actions($publishable['binding']['schema'], $recordId, $publishable['binding']['site'], $revision, $publishable['can']) as $action) {
            [$verb, $locale] = explode(':', $action, 2);
            $lifecycle[] = ['label' => $publication->word('larena-admin::structure_editors.actions.'.$verb).' · '.$locale,
                'write_fields' => ['action' => 'record.'.$verb, 'payload[locale]' => $locale] + $identity];
        }

        return $lifecycle;
    }

    /** @param array{actions: StorageSectionPublicationActions} $publishable */
    private function publicationWord(array $publishable, string $key): string
    {
        return $publishable['actions']->publication()->word($key);
    }

    /**
     * «Опубликовать» or «Снять с публикации» from the record card: back to the card, saying what happened.
     *
     * @param array<mixed> $payload
     */
    private function publicationWrite(Request $request, string $structure, string $verb, array $payload): RedirectResponse
    {
        $recordId = $this->recordId($payload['record_id'] ?? null);
        $locale = $payload['locale'] ?? null;
        $revision = filter_var($payload['expected_revision'] ?? null, FILTER_VALIDATE_INT);
        $back = $recordId === null ? $this->redirector->route('larena.admin.storage.records', ['structure' => $structure])
            : $this->redirector->route('larena.admin.storage.records', ['structure' => $structure, 'record_id' => $recordId, 'record_mode' => 'view']);
        $refused = fn (): RedirectResponse => $back->withErrors(['storage' => (string) $this->translator->get('larena-storage::admin.section.notice_rejected')]);
        $state = $this->snapshot($request, ['structure_id' => $structure, 'records' => 'skip']);
        $publishable = is_string($state['scope_ref'] ?? null) ? $this->publishable($request, $state['scope_ref'], $structure) : null;
        if ($recordId === null || !is_string($locale) || !is_int($revision) || $publishable === null
            || !in_array($locale, $publishable['actions']->publication()->locales($publishable['binding']['site']), true) || !is_string($state['scope_ref'] ?? null)) {
            return $refused();
        }
        $refusal = $publishable['actions']->write($this->actor($request), $state['scope_ref'], $structure, $publishable['binding'], $recordId, $verb, $locale, $revision);
        if ($refusal === StorageSectionPublicationActions::CHANGED) {
            return $back->withErrors(['storage' => (string) $this->translator->get('larena-storage::admin.section.notice_conflict')]);
        }

        return $refusal === null
            ? $back->with('status', (string) $this->translator->get('larena-storage::admin.section.notice_record_'.$verb, ['locale' => $locale]))
            : $refused();
    }

    /**
     * What each version says about the encrypted fields, newest first: «Паспорт: изменено» or «без изменений» against
     * the version before it (the same stored envelope is the same value), never a value.
     *
     * @param list<mixed> $history @param list<array<string, mixed>> $encrypted
     * @return array<int, string> by the version's position in the history
     */
    private function sealedChanges(array $history, array $encrypted): array
    {
        if ($encrypted === []) {
            return [];
        }
        $changes = [];
        foreach ($history as $position => $version) {
            if (!is_array($version)) {
                continue;
            }
            $values = is_array($version['values'] ?? null) ? $version['values'] : [];
            $earlier = is_array($history[$position + 1] ?? null) && is_array($history[$position + 1]['values'] ?? null) ? $history[$position + 1]['values'] : [];
            $parts = [];
            foreach ($encrypted as $field) {
                $key = (string) $field['key'];
                // A field empty in both versions says nothing.
                if (($earlier[$key] ?? null) === null && ($values[$key] ?? null) === null) {
                    continue;
                }
                $parts[] = (is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $key).': '
                    .(StorageEncryptedFields::same($earlier[$key] ?? null, $values[$key] ?? null)
                        ? StorageEncryptedFields::word('unchanged', 'unchanged') : StorageEncryptedFields::word('changed', 'changed'));
            }
            if ($parts !== []) {
                $changes[$position] = implode(', ', $parts);
            }
        }

        return $changes;
    }

    /**
     * «Показать» under the record view: the encrypted fields that hold a value, for a person with
     * storage.workbench.record.decrypt; the plaintext comes from the reveal route when asked and is never in the page.
     *
     * @param list<array<string, mixed>> $encrypted @param array<string, mixed> $values
     * @return array{encrypted?: array<string, mixed>}
     */
    private function sealedContext(Request $request, string $structure, string $recordId, array $encrypted, array $values): array
    {
        $filled = array_values(array_filter($encrypted, static fn (array $field): bool => StorageEncryptedFields::isSealed($values[$field['key']] ?? null)));
        if ($filled === [] || !$this->mayDecrypt($request)) {
            return [];
        }

        return ['encrypted' => [
            'reveal_url' => $this->sectionPath().'/'.rawurlencode($structure).'/reveal',
            'record_id' => $recordId,
            'fields' => array_map(static fn (array $field): array => ['key' => (string) $field['key'],
                'label' => is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : (string) $field['key']], $filled),
            'title' => StorageEncryptedFields::word('reveal_title', 'Encrypted fields'),
            'masked' => StorageEncryptedFields::maskText(),
            'show' => StorageEncryptedFields::word('show', 'Show'),
            'hide' => StorageEncryptedFields::word('hide', 'Hide'),
            'failed' => StorageEncryptedFields::word('reveal_failed', 'The value could not be shown.'),
            'audited' => StorageEncryptedFields::word('reveal_audited', 'Each view is recorded in the audit log.'),
        ]];
    }

    /**
     * What a refused save brings back to the form, without what was typed into an encrypted field: that plaintext is
     * not kept in the session, and the field opens empty again.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function withoutEncryptedInput(Request $request, string $structure, array $input): array
    {
        $keys = is_string($input['field_keys_json'] ?? null) ? json_decode($input['field_keys_json'], true) : null;
        if (!is_array($keys)) {
            return $input;
        }
        $selected = $this->selected($this->snapshot($request, ['structure_id' => $structure, 'include_archived_structures' => true, 'records' => 'skip']), $structure);
        if ($selected === null) {
            // The storage could not be read again: nothing typed is kept rather than a value that may be encrypted.
            return array_intersect_key($input, ['field_keys_json' => true]);
        }
        $encrypted = StorageEncryptedFields::keys($this->fields($selected, true));
        foreach ($keys as $index => $field) {
            if (is_array($field) && in_array($field['key'] ?? null, $encrypted, true)) {
                unset($input['field_'.$index]);
            }
        }

        return $input;
    }

    private function mayDecrypt(Request $request): bool
    {
        try {
            return $this->container->make(\Larena\Storage\Admin\StorageEncryptedFieldReveal::class)->mayReveal($this->actor($request));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The scope's active storages a «Storage record» field may point to: id => label.
     *
     * @param array<string, mixed> $state
     * @return array<string, string>
     */
    private function recordTargets(array $state): array
    {
        $targets = [];
        foreach (is_array($state['structures'] ?? null) ? $state['structures'] : [] as $structure) {
            if (is_array($structure) && is_string($structure['structure_id'] ?? null) && ($structure['archived'] ?? false) !== true) {
                $targets[$structure['structure_id']] = is_string($structure['label'] ?? null) ? $structure['label'] : $structure['structure_id'];
            }
        }

        return $targets;
    }

    /**
     * Where the record panel's reference fields take their choices from.
     *
     * @return array{reference_types:list<string>,filter_options_url:?string,relation_options_url:?string,scope_ref:?string,structure_id:string,actor_ref:string}
     */
    private function referenceSources(Request $request, string $structure, ?string $scope): array
    {
        $types = [];
        if ($this->container->bound(RecordFilterOptionsGateway::class)) {
            array_push($types, 'user', 'file', 'record');
        }
        $relations = $this->container->bound(RecordRelationOptionsGateway::class);
        if ($relations) {
            $types[] = 'relation';
        }

        return [
            'reference_types' => $types,
            'filter_options_url' => $types === [] ? null : $this->sectionPath().'/filter-options',
            'relation_options_url' => $relations ? $this->sectionPath().'/relation-options' : null,
            'scope_ref' => $scope,
            'structure_id' => $structure,
            'actor_ref' => $this->actor($request),
            'record_href' => $this->sectionPath().'/{structure}?record_id={record}&record_mode=view#admin-record-view',
            // The Framework property view reads and saves the record through the section's port, when switched on.
            'property_port' => $this->config->get('larena-admin.property_view.enabled', false) === true ? $this->sectionPath().'/property-port' : null,
        ];
    }

    /** @param array<string, mixed> $record @param array<string, mixed> $selected */
    private function recordTitle(array $record, array $selected): string
    {
        // A field named `title` names the record, wherever it stands; otherwise the first filled one does.
        $fields = $this->fields($selected);
        usort($fields, static fn (array $a, array $b): int => (($b['key'] ?? null) === 'title') <=> (($a['key'] ?? null) === 'title'));
        foreach ($fields as $field) {
            // A record whose title field is encrypted is named by its ID: the value is never shown.
            if (StorageEncryptedFields::isEncrypted($field)) {
                break;
            }
            $value = $record['values'][$field['key']] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 120);
            }
        }

        return (string) $record['record_id'];
    }

    /**
     * Records from a CSV file (owner decision 2026-10-19: add and update): the file is read into a plan the
     * person sees first, then written. The file waits in the section's private job files between the two steps.
     * A file of at most `import_inline_rows` rows is planned in the request and written at once, whole or not at
     * all. A larger one is checked by the queue (storage.import.plan): the import page then shows the job, «Проверяется…»
     * with its progress, and the finished plan with «Применить», which writes it by the queue (storage.import.apply)
     * in chunks, its progress on the storage's jobs page. Without the queue every file is planned and written at once.
     */
    public function import(Request $request, string $structure): View|Response
    {
        $job = $request->query('job');

        return $this->importPage($request, $structure, null, null, false, is_string($job) && preg_match('/\A[a-f0-9]{32}\z/D', $job) === 1 ? $job : null);
    }

    public function importPreview(Request $request, string $structure): View|Response|RedirectResponse
    {
        $file = $request->file('file');
        $csv = $file instanceof \Illuminate\Http\UploadedFile && $file->isValid() && $file->getSize() <= \Larena\Storage\Admin\StorageCsvImport::MAX_BYTES
            ? (string) file_get_contents($file->getRealPath()) : '';
        $token = bin2hex(random_bytes(16));
        $stored = $csv !== '' && $this->jobFiles()?->keepUpload($this->actor($request), $token, $csv) === true;
        $rows = $stored ? \Larena\Storage\Admin\StorageCsvImport::rowCount($csv) ?? 0 : 0;
        $jobs = $rows > $this->inlineRows() ? $this->backgroundJobs() : null;
        if ($jobs !== null) {
            // Too large to plan in the request: the queue checks the file, and the import page follows the check.
            $state = $this->snapshot($request, ['structure_id' => $structure, 'records' => 'skip']);
            $selected = $this->selected($state, $structure);
            $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
            if ($selected === null || ($selected['archived'] ?? false) === true || !is_string($state['scope_ref'] ?? null)
                || (($permissions['record.create'] ?? false) !== true && ($permissions['record.update'] ?? false) !== true)) {
                $this->jobFiles()?->forgetUpload($this->actor($request), $token);
                throw new NotFoundHttpException();
            }
            $ref = $jobs->startPlan($this->actor($request), $state['scope_ref'], $structure, $token, $rows);
            $back = $this->redirector->to($this->sectionPath().'/'.rawurlencode($structure).'/import');
            if ($ref === null) {
                $this->jobFiles()?->forgetUpload($this->actor($request), $token);

                return $back->withErrors(['storage' => $this->copy('import.queue_refused')]);
            }

            return $this->redirector->to($this->sectionPath().'/'.rawurlencode($structure).'/import?job='.$ref)
                ->with('status', (string) $this->translator->get('larena-storage::admin.section.import.checking_queued', ['count' => $rows]));
        }

        return $this->importPage($request, $structure, $csv, $token, $stored);
    }

    public function importApply(Request $request, string $structure): RedirectResponse
    {
        $token = $request->input('token');
        $digest = $request->input('digest');
        $files = $this->jobFiles();
        $path = is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $files?->upload($this->actor($request), $token) : null;
        $csv = is_string($path) ? file_get_contents($path) : false;
        $back = $this->redirector->to($this->sectionPath().'/'.rawurlencode($structure).'/import');
        if ($files === null || !is_string($token) || !is_string($csv) || !is_string($digest)) {
            return $back->withErrors(['storage' => $this->copy('import.expired')]);
        }
        $state = $this->snapshot($request, ['structure_id' => $structure, 'records' => 'skip']);
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        // A large file goes to the queue; the worker reads it again and refuses it if it no longer plans as shown.
        $rows = \Larena\Storage\Admin\StorageCsvImport::rowCount($csv) ?? 0;
        $jobs = $this->backgroundJobs();
        if ($jobs !== null && $rows > $this->inlineRows()) {
            $selected = $this->selected($state, $structure);
            if ($selected === null || ($selected['archived'] ?? false) === true || !is_string($state['scope_ref'] ?? null)
                || (($permissions['record.create'] ?? false) !== true && ($permissions['record.update'] ?? false) !== true)) {
                return $back->withErrors(['storage' => $this->copy('import.write_refused')]);
            }
            $ref = preg_match('/^[a-f0-9]{64}$/', $digest) === 1
                ? $jobs->startImport($this->actor($request), $state['scope_ref'], $structure, $token, $digest, $rows) : null;
            if ($ref === null) {
                return $back->withErrors(['storage' => $this->copy('import.queue_refused')]);
            }

            return $this->redirector->to($this->sectionPath().'/'.rawurlencode($structure).'/jobs')
                ->with('status', (string) $this->translator->get('larena-storage::admin.section.import.queued', ['count' => $rows]));
        }
        try {
            $result = $this->container->make(\Larena\Storage\Admin\StorageCsvImport::class)
                ->apply($this->actor($request), $structure, $csv, $digest, $permissions);
        } catch (Throwable) {
            $result = ['status' => 'rejected', 'reason' => 'write_refused'];
        }
        if ($result['status'] !== 'ok') {
            return $back->withErrors(['storage' => $this->copy('import.'.($result['reason'] ?? 'write_refused'))]);
        }
        $files->forgetUpload($this->actor($request), $token);
        $counts = $result['counts'] ?? ['create' => 0, 'update' => 0, 'same' => 0];

        return $this->redirector->to($this->sectionPath().'/'.rawurlencode($structure))
            ->with('status', (string) $this->translator->get('larena-storage::admin.section.import.done', ['create' => $counts['create'], 'update' => $counts['update']]));
    }

    private function importPage(Request $request, string $structure, ?string $csv, ?string $token, bool $storable, ?string $jobRef = null): View|Response
    {
        $state = $this->snapshot($request, ['structure_id' => $structure, 'records' => 'skip']);
        $selected = $this->selected($state, $structure);
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        if ($selected === null || ($selected['archived'] ?? false) === true
            || (($permissions['record.create'] ?? false) !== true && ($permissions['record.update'] ?? false) !== true)) {
            throw new NotFoundHttpException();
        }
        $plan = null;
        if ($csv !== null) {
            try {
                $plan = $this->container->make(\Larena\Storage\Admin\StorageCsvImport::class)
                    ->plan($this->actor($request), $structure, $csv, $permissions);
            } catch (Throwable) {
                $plan = ['columns' => [], 'rows' => [], 'errors' => [['line' => 0, 'column' => '', 'reason' => 'file_unreadable']],
                    'counts' => ['create' => 0, 'update' => 0, 'same' => 0], 'digest' => ''];
            }
        }
        $jobs = $this->backgroundJobs();
        $jobsPath = $this->sectionPath().'/'.rawurlencode($structure).'/jobs';
        $importPath = $this->sectionPath().'/'.rawurlencode($structure).'/import';
        $csrf = $request->hasSession() ? (string) $request->session()->token() : '';
        $panel = new \Larena\Storage\Admin\Runtime\StorageJobsPanel($this->translator);
        // A file the queue checks: the job's state while it runs, then its plan with «Применить».
        $checking = null;
        $apply = null;
        $queued = false;
        $job = $jobRef !== null && $jobs !== null ? $jobs->job($this->actor($request), $structure, $jobRef) : null;
        if ($job !== null && $job['kind'] === 'import' && $job['stage'] === 'check') {
            $item = $panel->items([$job], $jobsPath, $csrf, true, $importPath)[0];
            if ($job['plan'] !== null) {
                $plan = $job['plan'];
                $queued = true;
                $apply = $job['can_apply'] ? ['action' => $jobsPath.'/'.$job['ref'].'/apply', 'fields' => ['digest' => $job['plan']['digest']],
                    'text' => $this->copy('jobs.apply')] : null;
            } else {
                $checking = ['ref' => $job['ref'], 'title' => $this->copy('import.checking'), 'state' => $item['state'], 'progress' => $item['progress'],
                    'reason' => $item['reason'], 'actions' => $item['actions'], 'refresh' => $importPath.'?job='.$job['ref'],
                    'refresh_text' => $this->copy('jobs.refresh'), 'text' => $this->copy('import.checking_text')];
            }
        } elseif ($plan !== null && $storable && $token !== null) {
            $queued = $jobs !== null && $plan['counts']['create'] + $plan['counts']['update'] + $plan['counts']['same'] > $this->inlineRows();
            $apply = ['action' => $importPath.'/apply', 'fields' => ['token' => $token, 'digest' => $plan['digest']], 'text' => $this->copy('import.apply')];
        }
        if ($plan !== null) {
            // What the page shows of the plan: the counts, the columns and the first errors with the number of all.
            $errorCount = is_int($plan['error_count'] ?? null) ? $plan['error_count'] : count($plan['errors']);
            $shown = array_slice($plan['errors'], 0, \Larena\Storage\Admin\StorageCsvImport::MAX_KEPT_ERRORS);
            $plan = ['counts' => $plan['counts'], 'columns' => $plan['columns'], 'errors' => $shown, 'error_count' => $errorCount,
                'more_errors' => max(0, $errorCount - count($shown))];
        }

        $jobItems = $jobs === null ? [] : $panel->items($jobs->jobs($this->actor($request), $structure), $jobsPath, $csrf, true, $importPath);
        $recordsPath = $this->sectionPath().'/'.rawurlencode($structure);

        return $this->views->make('larena-storage::admin.import', [
            'structureId' => $structure, 'selected' => $selected, 'plan' => $plan, 'checking' => $checking, 'apply' => $apply, 'queued' => $queued,
            'jobs' => $jobItems,
            // The page as described parts (developer remark 3', stage 5), drawn by the shared renderer for the view to print with {{ }}.
            'content' => $this->draw(...(new \Larena\Storage\Admin\Runtime\StorageImportPage($this->translator))->describe([
                'upload_url' => $this->importUrl($structure), 'token' => $csrf, 'records_path' => $recordsPath,
                'jobs_path' => $jobs !== null ? $jobsPath : null, 'form' => AdminFrameworkForm::start(),
                // A refused or expired write.
                'error' => $this->importRefusal($request), 'queued' => $queued,
                'checking' => $checking, 'plan' => $plan, 'apply' => $apply, 'jobs' => $jobItems, 'panel' => $panel,
            ])),
        ]);
    }

    /** Where the import's upload posts (the import.preview route), or the import page's own path where routes are not loaded. */
    private function importUrl(string $structure): string
    {
        try {
            return $this->redirector->getUrlGenerator()->route('larena.admin.storage.import.preview', ['structure' => $structure]);
        } catch (Throwable) {
            return $this->sectionPath().'/'.rawurlencode($structure).'/import';
        }
    }

    /** The most rows a CSV file may have to be planned and written in the request. */
    private function inlineRows(): int
    {
        return max(0, (int) $this->config->get('larena-admin.storage_section.import_inline_rows', 1000));
    }

    private function importRefusal(Request $request): ?string
    {
        $bag = $request->hasSession() ? $request->session()->get('errors') : null;
        $text = $bag instanceof \Illuminate\Support\ViewErrorBag ? $bag->first('storage') : '';

        return $text !== '' ? $text : null;
    }

    private function jobFiles(): ?\Larena\Storage\Admin\StorageJobFiles
    {
        try {
            return $this->container->make(\Larena\Storage\Admin\StorageJobFiles::class);
        } catch (Throwable) {
            return null;
        }
    }

    /** The section's background jobs, when the queue is installed and runs them. */
    private function backgroundJobs(): ?\Larena\Storage\Admin\StorageBackgroundJobs
    {
        try {
            $jobs = $this->container->make(\Larena\Storage\Admin\StorageBackgroundJobs::class);
        } catch (Throwable) {
            return null;
        }

        return $jobs->available() ? $jobs : null;
    }

    public function settings(Request $request, string $structure): View|Response|JsonResponse
    {
        $state = $this->snapshot($request, ['structure_id' => $structure, 'include_archived_structures' => true, 'records' => 'skip']);
        $selected = $this->selected($state, $structure);

        $fields = $selected === null ? [] : $this->fields($selected, true);
        $marks = $selected === null ? [] : $this->fieldChanges()->fieldMarks($selected);
        $fieldRows = [];
        foreach ($fields as $field) {
            $fieldRows[] = [
                'state' => $marks[$field['key']] ?? '',
                'id' => $field['key'],
                'key' => $field['key'],
                'label' => (is_string($field['label'] ?? null) ? $field['label'] : $field['key']).(($field['required'] ?? false) === true ? ' *' : ''),
                'type' => $this->copy('types.'.(is_string($field['type'] ?? null) ? $field['type'] : 'string')),
                'position' => (string) ($field['position'] ?? ''),
                'hidden' => ($field['hidden'] ?? false) === true ? $this->copy('yes') : '',
            ];
        }

        // The fields as a list of the schema port (owner decision 2026-10-16): checkable cells, drag order, the row menu.
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        // While a field change runs in the background the fields are read, not changed.
        $changing = $selected !== null && StorageFieldChangeState::changing($selected);
        $configure = ($permissions['structure.configure'] ?? false) === true && ($selected['archived'] ?? false) !== true && !$changing;
        $here = $this->sectionPath().'/'.rawurlencode($structure).'/settings';
        $schemaList = $selected === null ? null : $this->dataview(new AdminDataviewPage(
            instanceId: 'storage-schema-'.substr(hash('sha256', $structure), 0, 12),
            title: $this->copy('fields'),
            columns: [['key' => 'label', 'label' => $this->copy('label'), 'role' => 'title'], ['key' => 'key', 'label' => $this->copy('field_key')],
                ['key' => 'type', 'label' => $this->copy('type')], ['key' => 'required', 'label' => $this->copy('required')], ['key' => 'hidden', 'label' => $this->copy('hidden')],
                // «Состояние» (indexed, changing, encrypted) only while a field has one, as the schema port answers.
                ...(self::anyFieldState($this->schemaRecords($selected, false, false))
                    ? [['key' => 'state', 'label' => $this->fieldChanges()->columnLabel()]] : [])],
            total: count($fieldRows),
            pageId: 'page.admin.storage-schema',
            portUrl: $this->sectionPath().'/'.rawurlencode($structure).'/schema-port',
            countLabel: $this->translated('record_list.record_count'),
            tableViewLabel: $this->translated('record_list.table_view'),
            // The table's toolbar carries «Create» and the search; it is shown for the list of fields.
            actions: true,
            portKind: 'host',
            portCapabilities: $configure ? ['row-actions', 'row-reorder', 'inline-edit', 'create-record'] : ['row-actions'],
            // The list creates a field, so its toolbar button says so.
            createText: $this->copy('add_field'),
            // A field opens in the record panel (sf-property-view over the field's port): its name, edit from the row, create.
            hostPanels: ['view' => $here.'?record_id=__ID__&record_mode=view']
                + ($configure ? ['edit' => $here.'?record_id=__ID__&record_mode=edit', 'create' => $here.'?record_mode=create'] : []),
            headerActions: [
                ['text' => $this->copy('records'), 'href' => $this->sectionPath().'/'.rawurlencode($structure)],
                // The storage's own properties (its name, its group in the menu) are its record.
                ['text' => $this->copy('storage_properties'), 'href' => $here.'?record_id='.rawurlencode($structure).'&record_mode=view'],
                ['text' => $this->copy('back_to_list'), 'href' => $this->sectionPath()],
            ],
        ));
        $panel = $selected === null ? null : $this->schemaPanel($request, $selected, $structure, $here, is_string($state['scope_ref'] ?? null) ? $state['scope_ref'] : null);
        if ($request->headers->get('X-Larena-Fragment') === 'record-panel') {
            $html = is_string($panel['html'] ?? null) ? $panel['html'] : '';

            return new JsonResponse(['html' => $html, 'asset_tags' => is_array($panel['asset_tags'] ?? null) ? array_values($panel['asset_tags']) : [],
                'mode' => $request->query('record_mode'), 'notice_html' => '', 'notice' => null], $html === '' ? 404 : 200,
                ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        $this->breadcrumbTail($request, $selected, $structure, $this->copy('settings'));

        return $this->render('larena-storage::admin.settings', $state, [
            // What the page draws around the list and the full form, worked out here so the template stays thin.
            'schemaPage' => $schemaList === null ? null : [
                'dataview' => $schemaList,
                'aria_label' => $this->copy('fields'),
                'query_url' => $here,
                'count' => count($fieldRows),
                'panel' => $panel['markup'] ?? null,
            ],
            'archivedNotice' => $this->archivedNotice($selected),
            // «Архивировать», «Восстановить», «Удалить навсегда»: forms described here, drawn by the shared renderer.
            'structureActions' => $selected === null ? null : $this->structureActions($request, $selected, $structure, $permissions,
                $this->fieldChanges()->locked($selected)),
            // The group this storage stands under in the menu's «Данные»; a menu setting, not a storage one.
            'menuGroup' => $this->menuProfiles()?->storageGroups()[$structure] ?? '',
            // The field change in the background, why the structure cannot change meanwhile, and the list still being filled.
            'fieldChangeRegion' => $selected === null ? '' : $this->fieldChangeRegion(
                $this->fieldChangeNotice($request, $selected, $structure, $permissions, 'settings'), $here),
            'structureLocked' => $selected === null ? null : $this->fieldChanges()->locked($selected),
            'backfillNote' => $selected === null ? null : $this->fieldChanges()->backfill($selected),
            // «Перенос на другой сайт»: the export and the person's runs, for a person with storage.sitepack.export.
            'sitePackPanel' => $selected === null ? null : $this->sitePackPanel($request, $state, $structure, $permissions, $selected),
            // «Шифрование»: the storage's key and «Сменить ключ шифрования», for a person with storage.encryption.manage.
            'encryptionPanel' => $selected === null ? null : $this->encryptionPanel($request, $state, $structure, $permissions, $selected),
            'selected' => $selected,
            'structureId' => $structure,
            'recordTargets' => $this->recordTargets($state),
            'fields' => $fields,
            'dataview' => null,
        ]);
    }

    public function dispatch(Request $request, ?string $structure = null): RedirectResponse
    {
        $actor = $this->actor($request);
        $action = $request->input('action');
        $payload = $request->input('payload', []);
        // The record panel's confirmation checkbox is a Framework control named "confirmed".
        if (is_array($payload) && $request->input('confirmed') === '1') {
            $payload['confirmed'] = '1';
        }
        // The record panel sends its fields as field_N with their key list: record values go to
        // payload[values], a new storage's label and ID to the payload itself.
        // The record card's «Опубликовать» and «Снять с публикации» go to the publication operations, not the workbench.
        if ($structure !== null && is_array($payload) && in_array($action, ['record.publish', 'record.unpublish'], true)) {
            return $this->publicationWrite($request, $structure, substr((string) $action, strlen('record.')), $payload);
        }
        $panelValues = AdminRecordPanel::submittedValues(static fn (string $name): mixed => $request->input($name));
        if (is_array($payload) && $panelValues !== null) {
            if ($action === 'structure.create') {
                $payload = [...$payload, ...$panelValues];
            } elseif (in_array($action, ['record.create', 'record.update'], true)) {
                $payload['values'] = $panelValues;
            }
        }
        $valid = is_string($action) && in_array($action, self::ACTIONS, true)
            && is_array($payload) && !array_is_list($payload)
            && ($structure === null) === ($action === 'structure.create');
        if ($valid && $structure !== null) {
            $given = $payload['structure_id'] ?? $structure;
            $valid = $given === $structure;
            $payload['structure_id'] = $structure;
        }
        // «Удалить хранилище навсегда» asks for the storage's ID typed back: a different one is said as such, under the
        // field and in the notice, instead of the general refusal; nothing is sent to Storage.
        if ($valid && $action === 'structure.purge' && $structure !== null) {
            $typed = is_string($payload['confirm_structure_id'] ?? null) ? trim($payload['confirm_structure_id']) : '';
            if ($typed !== $structure) {
                return $this->redirector->route('larena.admin.storage.settings', ['structure' => $structure])->withErrors([
                    'confirm_structure_id' => (string) $this->translator->get('larena-storage::admin.section.purge_id_mismatch'.($typed === '' ? '_empty' : ''),
                        ['id' => $structure]),
                ]);
            }
            $payload['confirm_structure_id'] = $typed;
        }
        $receipt = ['status' => 'rejected', 'redirect' => []];
        if ($valid) {
            try {
                $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $receipt = strlen($encoded) > self::MAX_INPUT_BYTES
                    ? $receipt
                    : $this->gateway->dispatch($actor, null, $action, $payload);
            } catch (Throwable) {
                $receipt = ['status' => 'unavailable', 'redirect' => []];
            }
        }

        // A save on the record's own page comes back to that page.
        $ownPage = $action === 'record.update' && $request->input('record_page') === '1' ? ['record_page' => '1'] : [];
        $redirect = $this->redirectFor(is_string($action) ? $action : '', $structure, $receipt, $ownPage);
        $status = $receipt['status'] ?? null;
        if ($status === 'ok' && $action === 'structure.configure' && $structure !== null && $request->exists('menu_group')) {
            try {
                // An emptied field arrives as null (ConvertEmptyStrings) and takes the storage out of its group.
                $group = $request->input('menu_group');
                $this->menuProfiles()?->setStorageGroup($structure, is_string($group) ? $group : null);
            } catch (Throwable) {
                // The storage is saved; a group that could not be kept leaves it where it was in the menu.
            }
        }
        if ($status === 'ok') {
            // Every write says what happened («Хранилище перенесено в архив.»): the notice's title is already «Готово».
            $done = in_array($action, self::ACTIONS, true) ? 'notice_'.str_replace('.', '_', (string) $action) : 'notice_success';
            // A field change of a large storage goes on in the background; the list keeps working meanwhile.
            if (($receipt['reason_code'] ?? null) === StorageSectionGateway::FIELD_CHANGE_STARTED) {
                return $redirect->with('status', $this->fieldChanges()->started());
            }
            // Encryption switched on or off a field with values: the run seals or opens them in the background.
            if (($receipt['reason_code'] ?? null) === StorageSectionGateway::ENCRYPTION_STARTED && $structure !== null) {
                return $redirect->with('status', $this->fieldChanges()->started($this->current($request, $structure)));
            }

            return $redirect->with('status', (string) $this->translator->get('larena-storage::admin.section.'.$done));
        }
        $reason = is_string($receipt['reason_code'] ?? null) ? $receipt['reason_code'] : '';
        if (in_array($reason, [StorageSectionGateway::FIELD_CHANGE_RUNNING, StorageEncryptionRunState::RUNNING, StorageEncryptionRunState::FAILED], true)
            && $structure !== null) {
            // The storage refuses while one of its fields changes or is encrypted: the page names it and asks to wait.
            return $redirect->withErrors(['storage' => (string) $this->fieldChanges()->refusal($reason, $this->current($request, $structure))]);
        }
        if ($reason === 'storage_role_binding_published' || $reason === 'storage_binding_unknown') {
            return $redirect->withErrors(['storage' => (string) $this->translator->get('larena-storage::admin.section.'.$reason)]);
        }
        // Storage's refusals of a structure change about encryption, in the section's words (a record's write keeps its draft).
        if (in_array($reason, StorageSectionGateway::ENCRYPTION_REASONS, true) && $status !== 'denied'
            && !in_array($action, ['record.create', 'record.update'], true)) {
            return $redirect->withErrors(['storage' => $this->encryptionError($reason)]);
        }
        $recordWrite = in_array($action, ['record.create', 'record.update'], true);
        $key = match (true) {
            $status === 'denied' => 'notice_denied',
            $reason === 'storage_workbench_revision_conflict' => $recordWrite ? 'notice_record_conflict' : 'notice_conflict',
            $reason === 'storage_workbench_records_unconvertible' => 'notice_unconvertible',
            $reason === 'storage_workbench_field_options_invalid' => 'notice_options_invalid',
            // A list still being built: a permanent deletion waits for the lists of the storages that point here.
            $reason === StorageSectionGateway::VIEW_NOT_READY => in_array($action, ['record.purge', 'structure.purge'], true)
                ? 'notice_related_list_not_ready' : 'notice_list_not_ready',
            $recordWrite => 'notice_record_rejected',
            default => 'notice_rejected',
        };
        if ($recordWrite && $structure !== null) {
            // The form opens again with what was typed, over the record as it is now: after a
            // conflict a second save writes the typed values against the current revision.
            $recordId = is_array($payload) && is_string($payload['record_id'] ?? null) ? $payload['record_id'] : null;
            $redirect = $this->redirector->route('larena.admin.storage.records', $action === 'record.update' && $this->recordId($recordId) !== null
                ? ['structure' => $structure, 'record_id' => $recordId, 'record_mode' => 'edit'] + $ownPage
                : ['structure' => $structure, 'record_mode' => 'create'])
                ->withInput($this->withoutEncryptedInput($request, $structure,
                    AdminRecordPanel::recoverableInput(static fn (string $name): mixed => $request->input($name))))
                ->with('larena_storage_record_draft', ['action' => $action, 'structure_id' => $structure, 'record_id' => $recordId,
                    'conflict' => $reason === 'storage_workbench_revision_conflict']);
        }

        $text = (string) $this->translator->get('larena-storage::admin.section.'.$key);
        // A value one field cannot take is said with that field: «Цена: нужно число, например 100,5 или 100.5.»
        if ($recordWrite && $reason === StorageSectionGateway::FIELD_VALUE_INVALID && is_string($receipt['field'] ?? null)) {
            $label = $receipt['field'];
            foreach ($structure !== null ? $this->fields($this->current($request, $structure) ?? [], true) : [] as $field) {
                if (($field['key'] ?? null) === $receipt['field'] && is_string($field['label'] ?? null)) {
                    $label = $field['label'];
                }
            }
            $text .= ' '.$label.': '.(string) $this->translator->get('larena-storage::admin.section.value_errors.'
                .StorageSectionGateway::valueErrorKey(is_string($receipt['field_reason'] ?? null) ? $receipt['field_reason'] : ''));
        }

        return $redirect->withErrors(['storage' => $text]);
    }

    /**
     * @param array<string, mixed> $permissions
     * @return array<string, array{url:string,fields:array<string,string>,confirm?:string}>
     */
    private function archiveRowWrites(Request $request, string $recordsPath, array $permissions, bool $structureArchived): array
    {
        $token = $request->hasSession() ? $request->session()->token() : '';
        $writes = [];
        if (!$structureArchived && ($permissions['record.restore'] ?? false) === true) {
            $writes['restore'] = ['url' => $recordsPath, 'fields' => ['_token' => $token, 'action' => 'record.restore',
                'payload[record_id]' => '__ID__', 'payload[expected_revision]' => '__REVISION__']];
        }
        if (($permissions['record.purge'] ?? false) === true) {
            $writes['purge'] = ['url' => $recordsPath, 'confirm' => $this->copy('confirm_purge_row'),
                // The question the page asks before posting stands for the panel's confirmation box.
                'fields' => ['_token' => $token, 'action' => 'record.purge', 'payload[confirmed]' => '1',
                    'payload[expected_revisions][__ID__]' => '__REVISION__']];
        }

        return $writes;
    }

    private function identityLabels(): ?\Larena\Admin\Contracts\AdminIdentityLabelResolver
    {
        try {
            return $this->container->make(\Larena\Admin\Contracts\AdminIdentityLabelResolver::class);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The breadcrumbs name the storage after «Хранилища» (QA 2026-10-22: they ended at the section), and the page under
     * it (its settings, a record on its own page).
     *
     * @param array<string, mixed>|null $selected
     */
    private function breadcrumbTail(Request $request, ?array $selected, string $structure, ?string $leaf): void
    {
        if ($selected === null) {
            return;
        }
        $label = is_string($selected['label'] ?? null) && trim($selected['label']) !== '' ? $selected['label'] : $structure;
        $request->attributes->set(\Larena\Admin\View\AdminShellViewComposer::BREADCRUMB_TAIL, ['parent_href' => $this->sectionPath(),
            'items' => [['label' => $label, 'href' => $this->sectionPath().'/'.rawurlencode($structure)], ...($leaf !== null ? [['label' => $leaf]] : [])]]);
    }

    /** A kept UTC moment in the site's time zone, «UTC» named while the site keeps UTC. */
    private static function when(string $stored): string
    {
        return \Larena\Admin\Settings\AdminSiteSettings::shown($stored);
    }

    private function menuProfiles(): ?\Larena\Admin\Navigation\AdminMenuProfiles
    {
        try {
            return $this->container->make(\Larena\Admin\Navigation\AdminMenuProfiles::class);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $receipt @param array<string, string> $ownPage */
    private function redirectFor(string $action, ?string $structure, array $receipt, array $ownPage = []): RedirectResponse
    {
        $ok = ($receipt['status'] ?? null) === 'ok';
        $target = is_array($receipt['redirect'] ?? null) ? $receipt['redirect'] : [];
        $created = is_string($target['structure_id'] ?? null) ? $target['structure_id'] : null;
        if ($action === 'structure.create') {
            return $ok && $created !== null
                ? $this->redirector->route('larena.admin.storage.settings', ['structure' => $created])
                : $this->redirector->route('larena.admin.storage.index');
        }
        if ($structure === null) {
            return $this->redirector->route('larena.admin.storage.index');
        }
        if ($action === 'structure.purge') {
            return $ok
                ? $this->redirector->route('larena.admin.storage.index', ['archived' => '1'])
                : $this->redirector->route('larena.admin.storage.settings', ['structure' => $structure]);
        }
        if (str_starts_with($action, 'structure.')) {
            return $this->redirector->route('larena.admin.storage.settings', ['structure' => $structure]);
        }
        if (in_array($action, ['record.restore', 'record.purge'], true)) {
            return $this->redirector->route('larena.admin.storage.records', ['structure' => $structure, 'view' => 'archive']);
        }
        $recordId = is_string($target['record_id'] ?? null) ? $target['record_id'] : null;
        if ($ok && $recordId !== null && $action !== 'record.archive') {
            return $this->redirector->route('larena.admin.storage.records', ['structure' => $structure, 'record_id' => $recordId, 'record_mode' => 'view'] + $ownPage);
        }

        return $this->redirector->route('larena.admin.storage.records', ['structure' => $structure]);
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function snapshot(Request $request, array $query): array
    {
        try {
            $state = $this->gateway->snapshot($this->actor($request), null, ['surface' => 'storage_section'] + $query);
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            return ['status' => 'unavailable', 'reason_code' => 'storage_section_gateway_failed'];
        }
        foreach (array_keys($state) as $key) {
            if (!in_array($key, self::STATE_KEYS, true)) {
                return ['status' => 'unavailable', 'reason_code' => 'storage_section_projection_invalid'];
            }
        }
        if (!in_array($state['status'] ?? null, ['ok', 'denied', 'rejected', 'unavailable'], true)) {
            return ['status' => 'unavailable', 'reason_code' => 'storage_section_projection_invalid'];
        }

        return $state;
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $data */
    private function render(string $view, array $state, array $data): View|Response
    {
        $status = (string) ($state['status'] ?? 'unavailable');
        if ($status === 'ok' && array_key_exists('selected', $data) && $data['selected'] === null) {
            throw new NotFoundHttpException('larena_admin_storage_structure_unknown');
        }
        $rendered = $this->views->make($view, $data + [
            'status' => $status,
            'scopeRef' => is_string($state['scope_ref'] ?? null) ? $state['scope_ref'] : '',
            'permissions' => is_array($state['permissions'] ?? null) ? $state['permissions'] : [],
        ]);
        $code = match ($status) {
            'ok' => 200,
            'denied' => 403,
            'rejected' => 422,
            default => 503,
        };

        return $code === 200 ? $rendered : new Response($rendered->render(), $code);
    }

    /**
     * The storage's settings as lists (owner decision 2026-10-16): its fields are the records of the «fields»
     * collection of this host port (simai.composite-host-port). A field's revision is the storage's version, so a
     * write made from a stale list is a conflict. Order, «required» and «hidden» change in place; a change that
     * rewrites records (required, removal) is confirmed first with a digest of what it will do.
     */
    public function schemaPort(Request $request, string $structure): JsonResponse
    {
        $intent = $request->json('intent');
        $payload = $request->json('payload');
        $payload = is_array($payload) ? $payload : [];
        $state = $this->snapshot($request, ['structure_id' => $structure, 'include_archived_structures' => true, 'records' => 'skip']);
        $selected = $this->selected($state, $structure);
        if ($selected === null) {
            return self::portAnswer(['answer' => 'refused', 'reason' => 'structure_unknown']);
        }
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        $configure = ($permissions['structure.configure'] ?? false) === true;
        $migrate = $configure && ($permissions['structure.migrate'] ?? false) === true;
        // While a field change runs the list is read; a write from a page opened before it is refused in words.
        if (StorageFieldChangeState::changing($selected)) {
            if (in_array($intent, ['record.reorder', 'record.set', 'record.mutate'], true)) {
                return self::portAnswer(['answer' => 'refused', 'reason' => 'field_change_running',
                    'message' => (string) $this->fieldChanges()->refusal(StorageSectionGateway::FIELD_CHANGE_RUNNING, $selected)]);
            }
            [$configure, $migrate] = [false, false];
        }

        return match ($intent) {
            'query.change' => $this->schemaQuery($selected, $payload, $configure, $migrate),
            // The field's name opens it in the record panel; the page opens the panel once the port says so.
            'record.open' => $this->schemaField($selected, (string) ((is_array($payload['record_ids'] ?? null) ? $payload['record_ids'] : [])[0] ?? '')) !== null
                ? self::portAnswer(['answer' => 'applied']) : self::portAnswer(['answer' => 'refused', 'reason' => 'record_unknown']),
            'record.reorder' => $configure ? $this->schemaReorder($request, $selected, $payload) : self::portAnswer(['answer' => 'refused', 'reason' => 'access_denied']),
            'record.set' => $configure ? $this->schemaSet($request, $selected, $payload, $migrate) : self::portAnswer(['answer' => 'refused', 'reason' => 'access_denied']),
            'record.mutate' => $migrate ? $this->schemaRemove($request, $selected, $payload) : self::portAnswer(['answer' => 'refused', 'reason' => 'access_denied']),
            default => self::portAnswer(['answer' => 'unavailable', 'reason' => 'capability-not-provided']),
        };
    }

    /**
     * The record panel the settings page opens: a field of the storage (collection storage.fields.<id>), a new field,
     * or the storage itself (its name and menu group). Everything in it comes from the property port.
     *
     * @param array<string, mixed> $selected
     * @return array<string, mixed>|null
     */
    private function schemaPanel(Request $request, array $selected, string $structure, string $here, ?string $scopeRef): ?array
    {
        $mode = $request->query('record_mode');
        $recordId = $request->query('record_id');
        if (!in_array($mode, ['view', 'edit', 'create'], true) || $scopeRef === null
            || ($mode !== 'create' && (!is_string($recordId) || $recordId === ''))) {
            return null;
        }
        $storage = $recordId === $structure;
        $collection = $storage ? \Larena\Storage\Admin\StorageStructurePropertyViewPort::COLLECTION
            : \Larena\Storage\Admin\StorageFieldPropertyViewPort::COLLECTION_PREFIX.$structure;
        $field = $storage || $mode === 'create' ? null : $this->schemaField($selected, (string) $recordId);
        if (!$storage && $mode !== 'create' && $field === null) {
            return null;
        }
        $title = $mode === 'create' ? $this->copy('add_field')
            : ($storage ? (string) ($selected['label'] ?? $structure) : (string) ($field['label'] ?? $recordId));
        try {
            return $this->recordPanels->present(new AdminRecordPanel(
                mode: $mode,
                title: $title,
                fields: [],
                values: [],
                closeUrl: $here,
                writeUrl: $here,
                writeFields: [],
                recordId: $mode === 'create' ? null : (string) $recordId,
                revision: (int) ($selected['version'] ?? 0),
                heading: $title,
                closeLabel: $this->translated('record_list.close'),
                context: ['locale' => (string) $this->translator->getLocale(), 'structure_id' => $collection, 'scope_ref' => $scopeRef,
                    'property_port' => $this->sectionPath().'/property-port', 'view_settings' => true],
            ), AdminProductAssetManifest::dataviewActivation());
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $selected @param array<string, mixed> $payload */
    private function schemaQuery(array $selected, array $payload, bool $configure, bool $migrate): JsonResponse
    {
        $sequence = $payload['sequence'] ?? null;
        if (!is_int($sequence) || $sequence < 1) {
            return self::portAnswer(['answer' => 'refused', 'reason' => 'sequence_invalid']);
        }
        $query = is_array($payload['query'] ?? null) ? $payload['query'] : [];
        $search = is_string($query['search'] ?? null) ? mb_strtolower(trim($query['search'])) : '';
        $all = $this->schemaRecords($selected, $configure, $migrate);
        $records = array_values(array_filter($all, static fn (array $record): bool => $search === ''
            || str_contains(mb_strtolower($record['label'].' '.$record['key']), $search)));
        $types = [];
        foreach (array_keys((array) $this->translator->get('larena-storage::admin.section.types')) as $type) {
            $types[] = ['value' => (string) $type, 'label' => $this->copy('types.'.$type)];
        }
        $columns = [
            ['key' => 'label', 'label' => $this->copy('label'), 'role' => 'title', 'sortable' => false],
            ['key' => 'key', 'label' => $this->copy('field_key'), 'sortable' => false],
            ['key' => 'type', 'label' => $this->copy('type'), 'sortable' => false] + AdminDataviewCells::column(['type' => 'choice', 'constraints' => ['options' => $types]]),
            ['key' => 'required', 'label' => $this->copy('required'), 'sortable' => false] + AdminDataviewCells::column(['type' => 'boolean']),
            ['key' => 'hidden', 'label' => $this->copy('hidden'), 'sortable' => false] + AdminDataviewCells::column(['type' => 'boolean']),
        ];
        // «индексируется…» while a field's list column is filled, «меняется…» while the field changes, «зашифровано» for an
        // encrypted field; no column while no field has a state (an always-empty column says nothing).
        if (self::anyFieldState($all)) {
            $columns[] = ['key' => 'state', 'label' => $this->fieldChanges()->columnLabel(), 'sortable' => false];
        }

        return self::portAnswer(['answer' => 'applied', 'sequence' => $sequence, 'data' => [
            'columns' => $columns,
            'records' => $records,
            // The ids are the Framework's own (view, edit, delete), so the kit names, draws and marks them:
            // its delete carries danger since ui c30885b, and the row menu paints a danger entry in «error».
            // Naming them here only put other words on the same actions (owner decision 2026-10-13: view,
            // edit and delete carry the Framework's labels and icons; a host names what the kit does not know).
            // Rights still travel with the data in each record's `actions`: removing a field rewrites the
            // records, so it is offered with the migration right, and editing one with the configure right.
            'row_actions' => [],
            'total' => count($records),
            // Every field is on the one page; its page size is the nearest of the list's own sizes (not «1» for one field).
            'echo' => ['search' => $search, 'filters' => [], 'sort' => [], 'page' => 1,
                'per_page' => count($records) <= 20 ? 20 : (count($records) <= 50 ? 50 : (count($records) <= 100 ? 100 : count($records)))],
        ]]);
    }

    /** Whether a field of the list has a state to show in «Состояние». @param list<array<string, mixed>> $records */
    private static function anyFieldState(array $records): bool
    {
        foreach ($records as $record) {
            if (($record['state'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $selected
     * @return list<array<string, mixed>>
     */
    private function schemaRecords(array $selected, bool $configure, bool $migrate): array
    {
        $fields = $this->fields($selected, true);
        usort($fields, static fn (array $a, array $b): int => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));
        $revision = (int) ($selected['version'] ?? 0);
        // Which checkable cells this person may change: «hidden» with the configure right, «required» with the migration too.
        $editable = [...($migrate ? ['required'] : []), ...($configure ? ['hidden'] : [])];
        $actions = ['view', ...($configure ? ['edit'] : []), ...($migrate ? ['delete'] : [])];
        $marks = $this->fieldChanges()->fieldMarks($selected);

        return array_map(static fn (array $field): array => [
            'id' => (string) $field['key'],
            'key' => (string) $field['key'],
            'label' => is_string($field['label'] ?? null) ? $field['label'] : (string) $field['key'],
            'type' => is_string($field['type'] ?? null) ? $field['type'] : 'string',
            'required' => ($field['required'] ?? false) === true,
            'hidden' => ($field['hidden'] ?? false) === true,
            // An encrypted field says so in the list of fields (QA 2026-10-22: nothing told it apart), beside its change.
            'state' => implode(' · ', array_filter([$marks[(string) $field['key']] ?? '',
                StorageEncryptedFields::isEncrypted($field) ? StorageEncryptedFields::word('column_marker', 'encrypted') : ''], static fn (string $part): bool => $part !== '')),
            'position' => (int) ($field['position'] ?? 0),
            'revision' => $revision,
            '_revision' => $revision,
            'editable' => $editable,
            // What the row offers: its name opens it (view); edit and delete as the person may.
            'actions' => $actions,
        ], $fields);
    }

    /** Drag order: the moved fields go before `before`, or to the end; positions are renumbered by tens. @param array<string, mixed> $selected @param array<string, mixed> $payload */
    private function schemaReorder(Request $request, array $selected, array $payload): JsonResponse
    {
        $moved = array_values(array_filter(is_array($payload['record_ids'] ?? null) ? $payload['record_ids'] : [], 'is_string'));
        $before = $payload['before'] ?? null;
        $order = array_column($this->schemaRecords($selected, true, true), 'key');
        if ($moved === [] || array_diff($moved, $order) !== [] || ($before !== null && (!is_string($before) || !in_array($before, $order, true) || in_array($before, $moved, true)))) {
            return self::portAnswer(['answer' => 'refused', 'reason' => 'reorder_invalid']);
        }
        if (($payload['revision'] ?? null) !== (int) ($selected['version'] ?? 0)) {
            return $this->schemaConflict($selected);
        }
        $rest = array_values(array_diff($order, $moved));
        $at = $before === null ? count($rest) : (int) array_search($before, $rest, true);
        array_splice($rest, $at, 0, $moved);
        $fields = [];
        foreach ($rest as $index => $key) {
            $fields[] = ['key' => $key, 'position' => ($index + 1) * 10];
        }

        return $this->schemaWrite($request, $selected, $fields, false);
    }

    /** A checkable cell: «hidden» in place, «required» after the person confirms what it does to the records. @param array<string, mixed> $selected @param array<string, mixed> $payload */
    private function schemaSet(Request $request, array $selected, array $payload, bool $migrate): JsonResponse
    {
        $key = $payload['record_id'] ?? null;
        $column = $payload['key'] ?? null;
        $value = $payload['value'] ?? null;
        $field = is_string($key) ? $this->schemaField($selected, $key) : null;
        if ($field === null || !in_array($column, ['required', 'hidden'], true) || !is_bool($value)) {
            return self::portAnswer(['answer' => 'refused', 'reason' => 'set_invalid']);
        }
        if ($column === 'required' && !$migrate) {
            return self::portAnswer(['answer' => 'refused', 'reason' => 'access_denied']);
        }
        if (($payload['revision'] ?? null) !== (int) ($selected['version'] ?? 0)) {
            return $this->schemaConflict($selected);
        }
        if ($column === 'required') {
            $digest = hash('sha256', 'required|'.$key.'|'.($value ? '1' : '0').'|'.(int) ($selected['version'] ?? 0));
            if (($payload['confirm'] ?? null) !== $digest) {
                // Made required, the field is first checked over the records: the question says how many have no value.
                return self::portAnswer(['answer' => 'refused', 'reason' => 'confirm_required', 'data' => ['confirm' => [
                    'summary' => $this->copy($value ? 'confirm_required_on' : 'confirm_required_off')
                        .($value ? $this->requiredCount($request, $selected, $field) : ''), 'digest' => $digest]]]);
            }
        }

        return $this->schemaWrite($request, $selected, [['key' => $key, $column => $value]], $column === 'required');
    }

    /**
     * How many records would have no value once a field is required (Storage's check of the change), said after the
     * question; nothing when the check is not available.
     *
     * @param array<string, mixed> $selected @param array<string, mixed> $field
     */
    private function requiredCount(Request $request, array $selected, array $field): string
    {
        $receipt = $this->gateway->dispatch($this->actor($request), null, 'structure.configure_check', [
            'structure_id' => (string) $selected['structure_id'], 'label' => (string) ($selected['label'] ?? $selected['structure_id']),
            'expected_version' => (int) ($selected['version'] ?? 0),
            'fields' => [['key' => (string) $field['key'], 'label' => (string) ($field['label'] ?? $field['key']), 'position' => (int) ($field['position'] ?? 0),
                'hidden' => ($field['hidden'] ?? false) === true, 'type' => (string) ($field['type'] ?? 'string'), 'required' => true]],
        ]);
        $check = $receipt['redirect']['check'] ?? null;
        if (($receipt['status'] ?? null) !== 'ok' || !is_array($check) || ($check['data_changing'] ?? false) !== true) {
            return '';
        }

        return ' '.strtr($this->copy('data_change.required_on_count'), [':checked' => (string) (int) ($check['checked'] ?? 0),
            ':total' => (string) (int) ($check['total'] ?? 0), ':invalid' => (string) (int) ($check['invalid'] ?? 0)]);
    }

    /** Removing a field from its row's menu: its values go from every record, so the person confirms it first. @param array<string, mixed> $selected @param array<string, mixed> $payload */
    private function schemaRemove(Request $request, array $selected, array $payload): JsonResponse
    {
        $ids = array_values(array_filter(is_array($payload['record_ids'] ?? null) ? $payload['record_ids'] : [], 'is_string'));
        $field = count($ids) === 1 ? $this->schemaField($selected, $ids[0]) : null;
        if (($payload['action_id'] ?? null) !== 'delete' || $field === null) {
            return self::portAnswer(['answer' => 'refused', 'reason' => 'action-unknown']);
        }
        $digest = hash('sha256', 'remove|'.$ids[0].'|'.(int) ($selected['version'] ?? 0));
        if (($payload['confirm'] ?? null) !== $digest) {
            return self::portAnswer(['answer' => 'refused', 'reason' => 'confirm_required', 'data' => ['confirm' => [
                'summary' => str_replace(':name', (string) ($field['label'] ?? $ids[0]), $this->copy('confirm_field_remove')), 'digest' => $digest]]]);
        }

        // A removed row is not a patch: the list asks again after the answer.
        return $this->schemaWrite($request, $selected, [['key' => $ids[0], 'remove' => true]], true, false);
    }

    /** @param array<string, mixed> $selected @param list<array<string, mixed>> $fields */
    private function schemaWrite(Request $request, array $selected, array $fields, bool $migration, bool $patch = true): JsonResponse
    {
        $structure = (string) $selected['structure_id'];
        // Fields the write does not name keep their settings; a named one keeps what it does not change.
        $current = [];
        foreach ($this->fields($selected, true) as $field) {
            $current[(string) $field['key']] = ['key' => (string) $field['key'], 'label' => (string) ($field['label'] ?? $field['key']),
                'position' => (int) ($field['position'] ?? 0), 'hidden' => ($field['hidden'] ?? false) === true, 'type' => (string) ($field['type'] ?? 'string')];
        }
        $submitted = array_map(static fn (array $change): array => $change + ($current[$change['key']] ?? []), $fields);
        $receipt = $this->gateway->dispatch($this->actor($request), null, 'structure.configure', [
            'structure_id' => $structure, 'label' => (string) ($selected['label'] ?? $structure),
            'expected_version' => (int) ($selected['version'] ?? 0), 'fields' => $submitted, 'confirm_migration' => $migration ? '1' : '0',
        ]);
        if (($receipt['status'] ?? null) !== 'ok') {
            return self::portAnswer(match ($receipt['reason_code'] ?? null) {
                'storage_workbench_revision_conflict' => ['answer' => 'conflict', 'reason' => 'revision_conflict'],
                'storage_workbench_access_denied', 'storage_workbench_scope_denied' => ['answer' => 'refused', 'reason' => 'access_denied'],
                'storage_workbench_records_unconvertible' => ['answer' => 'refused', 'reason' => 'records_unconvertible'],
                StorageSectionGateway::FIELD_CHANGE_RUNNING, StorageEncryptionRunState::RUNNING, StorageEncryptionRunState::FAILED => ['answer' => 'refused',
                    'reason' => 'field_change_running',
                    'message' => (string) $this->fieldChanges()->refusal((string) $receipt['reason_code'], $this->current($request, $structure))],
                default => ['answer' => 'refused', 'reason' => 'schema_write_rejected'],
            });
        }
        $state = $this->snapshot($request, ['structure_id' => $structure, 'include_archived_structures' => true, 'records' => 'skip']);
        $after = $this->selected($state, $structure) ?? $selected;
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        $configure = ($permissions['structure.configure'] ?? false) === true;

        $effects = [['type' => 'record-changed', 'value' => ['collection' => 'storage.fields', 'id' => $structure, 'revision' => (int) ($after['version'] ?? 0)]]];
        // A change that goes on in the background says so beside the answer; meanwhile the fields are read, not changed.
        $background = in_array($receipt['reason_code'] ?? null, [StorageSectionGateway::FIELD_CHANGE_STARTED, StorageSectionGateway::ENCRYPTION_STARTED], true)
            || StorageFieldChangeState::changing($after);
        $said = $background ? ['message' => $this->fieldChanges()->started($after)] : [];
        if ($background) {
            $configure = false;
        }
        if (!$patch) {
            return self::portAnswer(['answer' => 'applied', 'effects' => $effects] + $said);
        }

        // The patched rows replace the old ones; the list does not ask again.
        return self::portAnswer(['answer' => 'applied', 'data' => ['records' => $this->schemaRecords($after, $configure,
            $configure && ($permissions['structure.migrate'] ?? false) === true)],
            'effects' => $effects] + $said);
    }

    /** @param array<string, mixed> $selected */
    private function schemaConflict(array $selected): JsonResponse
    {
        return self::portAnswer(['answer' => 'conflict', 'reason' => 'revision_conflict', 'current_revision' => (int) ($selected['version'] ?? 0)]);
    }

    /** @param array<string, mixed> $selected @return array<string, mixed>|null */
    private function schemaField(array $selected, string $key): ?array
    {
        foreach ($this->fields($selected, true) as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $answer */
    private static function portAnswer(array $answer): JsonResponse
    {
        $status = match ($answer['answer'] ?? null) {'applied' => 200, 'conflict' => 409, 'refused' => 422, default => 503};

        return new JsonResponse($answer, $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @param array<string, mixed> $state @return array<string, mixed>|null */
    private function selected(array $state, string $structure): ?array
    {
        $selected = $state['selected_structure'] ?? null;

        return is_array($selected) && ($selected['structure_id'] ?? null) === $structure ? $selected : null;
    }

    /**
     * @param array<string, mixed> $selected
     * @return list<array<string, mixed>>
     */
    private function fields(array $selected, bool $includeHidden = false): array
    {
        $fields = [];
        foreach (is_array($selected['fields'] ?? null) ? $selected['fields'] : [] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)
                && ($includeHidden || ($field['hidden'] ?? false) !== true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * The list toolbar's filters: every field type with the operators the host offers for it
     * and, for user, file and record fields, choices from the section's scope-bound option source.
     *
     * @param list<array<string, mixed>> $fields @param array<string, mixed> $operators
     * @return list<array<string, mixed>>
     */
    private function filterFields(Request $request, array $fields, string $structure, ?string $scope, array $operators): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[(string) $field['key']] = $field;
        }
        $sources = $this->referenceSources($request, $structure, $scope);
        $operatorsByType = [];
        foreach ($operators as $type => $list) {
            if (is_string($type) && is_array($list) && array_is_list($list)) {
                $operatorsByType[$type] = array_values(array_filter($list, 'is_string'));
            }
        }

        return RecordFilterFields::describe($byKey, [], ['yes' => $this->copy('yes'), 'no' => $this->copy('no')], $operatorsByType, [
            'endpoint' => $sources['filter_options_url'],
            'scope_ref' => $scope,
            'structure_id' => $structure,
            'relation_available' => $sources['relation_options_url'] !== null,
            'supported_types' => $sources['reference_types'],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function dataview(AdminDataviewPage $page): ?array
    {
        try {
            return $this->dataviews->present($page, AdminProductAssetManifest::dataviewActivation());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * «Перенос на другой сайт» of the settings page, drawn only for a person with the right to export a storage
     * (storage.sitepack.export) while SitePack is installed: what the export carries, «Выгрузить хранилище» and
     * the person's latest exports of this storage.
     *
     * A storage with encrypted fields says their values stay out of the pack; a person who may also read them
     * (storage.workbench.record.decrypt) is offered them in plaintext while the bound SitePack takes that option.
     *
     * @param array<string, mixed> $state @param array<string, mixed> $permissions @param array<string, mixed> $selected
     */
    private function sitePackPanel(Request $request, array $state, string $structure, array $permissions, array $selected = []): ?HtmlString
    {
        if (($permissions['sitepack.export'] ?? false) !== true || !is_string($state['scope_ref'] ?? null)) {
            return null;
        }
        try {
            $exports = $this->container->make(StorageSitePackExports::class);
        } catch (Throwable) {
            return null;
        }
        $runs = $exports->runs($this->actor($request), $state['scope_ref'], $structure);

        $encrypted = StorageEncryptedFields::keys($this->fields($selected, true)) !== [];

        if ($runs === null) {
            return null;
        }
        // The panel reads itself again while an export is queued or running (StorageLiveRegions).
        $said = [];
        foreach ($runs as $run) {
            $said[] = $this->copy('sitepack.kind_export').': '.$this->copy('sitepack.state.'.(is_string($run['state'] ?? null) ? $run['state'] : 'queued'));
        }

        return $this->draw(new AdminLiveRegionPart(StorageLiveRegions::SITEPACK_EXPORT, StorageLiveRegions::sitePackExportActive($runs),
            $this->sectionPath().'/'.rawurlencode($structure).'/settings', implode('; ', $said), [(new StorageSitePackPanel($this->translator))->describe($runs,
            $this->sectionPath().'/'.rawurlencode($structure).'/sitepack/export', $this->sectionPath().'/sitepack',
            $request->hasSession() ? (string) $request->session()->token() : '',
            $encrypted, $encrypted && ($permissions['record.decrypt'] ?? false) === true && $exports->supportsPlaintext())]));
    }

    /**
     * «Шифрование» of the settings page, drawn only for a person with storage.encryption.manage while Storage tells
     * about keys: the key's state in plain words and «Сменить ключ шифрования», closed while the storage takes no
     * change.
     *
     * @param array<string, mixed> $state @param array<string, mixed> $permissions @param array<string, mixed> $selected
     */
    private function encryptionPanel(Request $request, array $state, string $structure, array $permissions, array $selected): ?HtmlString
    {
        if (($permissions['encryption.manage'] ?? false) !== true || !is_string($state['scope_ref'] ?? null) || ($selected['archived'] ?? false) === true) {
            return null;
        }
        try {
            $keys = $this->container->make(StorageEncryptionKeyStatus::class);
        } catch (Throwable) {
            return null;
        }
        if (!$keys->available()) {
            return null;
        }

        return $this->draw((new StorageEncryptionPanel($this->translator))->describe($keys->status($this->actor($request), $state['scope_ref'], $structure),
            $this->sectionPath().'/'.rawurlencode($structure).'/encryption/rotate',
            $request->hasSession() ? (string) $request->session()->token() : '',
            StorageFieldChangeState::changing($selected) || ($permissions['structure.configure'] ?? false) !== true));
    }

    /**
     * «Загрузить хранилище» in the list's header, for a person who may import storages and create storages and records
     * while SitePack is installed.
     *
     * @param array<string, mixed> $state
     * @return list<array{text: string, href: string, attributes: array<string, string>}>
     */
    private function sitePackImportAction(array $state): array
    {
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        if (($state['status'] ?? null) !== 'ok' || !StorageSitePackImportController::mayImport($permissions)) {
            return [];
        }
        try {
            $this->container->make(\Larena\Storage\Admin\StorageSitePackImports::class);
        } catch (Throwable) {
            return [];
        }

        return [[
            'text' => $this->copy('sitepack_import.action'),
            'href' => $this->sectionPath().'/sitepack/import',
            'attributes' => ['data-larena-storage-sitepack-import' => ''],
        ]];
    }

    private function sectionPath(): string
    {
        return '/'.trim((string) $this->config->get('larena-admin.storage_section.uri', 'admin/storage'), '/');
    }

    /** @param array<string, mixed> $field */
    private function displayValue(mixed $value, array $field = []): string
    {
        // A choice reads as its option's label, as in the records list, not as the stored value.
        $options = in_array($field['type'] ?? null, ['choice', 'choices'], true) && is_array($field['constraints']['options'] ?? null)
            ? array_column(array_filter($field['constraints']['options'], 'is_array'), 'label', 'value') : [];
        if ($options !== [] && (is_scalar($value) || (is_array($value) && array_is_list($value)))) {
            $labels = array_map(static fn (mixed $one): string => is_scalar($one) ? (string) ($options[(string) $one] ?? $one) : '',
                is_array($value) ? $value : [$value]);

            return implode(', ', array_filter($labels, static fn (string $label): bool => $label !== ''));
        }

        return match (true) {
            // An encrypted value is never drawn.
            StorageEncryptedFields::isSealed($value) => StorageEncryptedFields::maskText(),
            is_bool($value) => $this->copy($value ? 'yes' : 'no'),
            is_scalar($value) => (string) $value,
            $value === null => '',
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * The storage's field change as its pages draw it: a Framework alert and, while it runs, the
     * progress of the records checked and, for a person with the right to migrate the structure,
     * «Отменить изменение», which asks first and comes back to the page it was pressed on.
     *
     * @param array<string, mixed> $selected @param array<string, mixed> $permissions @param 'records'|'settings' $page
     * @return array{kind:string,title:string,parts:list<AdminNode>}|null
     */
    private function fieldChangeNotice(Request $request, array $selected, string $structure, array $permissions, string $page): ?array
    {
        $notice = $this->fieldChanges()->notice($selected);
        if ($notice === null) {
            return null;
        }
        $progress = $notice['progress'] === null ? [] : [new AdminElement('progress', ['value' => (string) (int) $notice['progress']['value'],
            'max' => (string) (int) $notice['progress']['max'], 'aria-label' => $notice['progress']['label'], 'data-larena-storage-field-change-progress' => null])];
        $token = new AdminElement('input', ['type' => 'hidden', 'name' => '_token', 'value' => $request->hasSession() ? (string) $request->session()->token() : '']);
        $back = new AdminElement('input', ['type' => 'hidden', 'name' => 'return', 'value' => $page]);
        // A stopped encryption run is continued with «Повторить», by a person who may manage encryption.
        $retry = ($permissions['encryption.manage'] ?? false) === true ? (new StorageEncryptionRunState($this->translator))->retryOffer($selected) : null;
        $again = $retry === null ? [] : [new AdminElement('form', ['method' => 'post',
            'action' => $this->sectionPath().'/'.rawurlencode($structure).'/encryption/retry', 'data-larena-storage-encryption-retry' => null],
            [$token, $back, AdminControls::submitNode($retry['label'], 'primary')])];
        $offer = ($permissions['structure.migrate'] ?? false) === true ? $this->fieldChanges()->cancelOffer($selected) : null;
        $cancel = $offer === null ? [] : [new AdminElement('form', ['method' => 'post',
            'action' => $this->sectionPath().'/'.rawurlencode($structure).'/field-change/cancel', 'data-larena-storage-field-change-cancel' => null,
            'data-larena-confirm-submit' => $offer['question']], [
            $token,
            // The question the page asks before posting stands for the confirmation.
            new AdminElement('input', ['type' => 'hidden', 'name' => 'confirmed', 'value' => '1']),
            $back,
            AdminControls::submitNode($offer['label'], 'danger'),
        ])];

        return ['kind' => $notice['kind'], 'title' => $notice['title'],
            'parts' => [AdminSfElement::alert($notice['text'], $notice['tone'], $notice['title']), ...$progress, ...$again, ...$cancel]];
    }

    /**
     * The field change or encryption run notice in its holder, drawn on the records and settings pages whenever the
     * storage is shown, so that a change that ends well clears it in place. It reads itself again while the change or
     * run goes on (StorageLiveRegions), from the page it stands on.
     *
     * @param array{kind:string,title:string,parts:list<AdminNode>}|null $notice
     */
    private function fieldChangeRegion(?array $notice, string $url): HtmlString
    {
        return $this->draw(new AdminLiveRegionPart(StorageLiveRegions::FIELD_CHANGE, StorageLiveRegions::fieldChangeActive($notice), $url,
            $notice === null ? $this->translated('live_finished') : $notice['title'],
            $notice === null ? [] : [new AdminElement('div', ['role' => 'status', 'data-larena-storage-field-change' => $notice['kind']], $notice['parts'])]));
    }

    /**
     * The records page's field change notice alone (X-Larena-Fragment: live:storage-field-change), read without the
     * list: the person's own snapshot of the storage, with the same rights as the page. A storage the person cannot
     * read is refused; one that is not there answers 404, and the asking stops.
     */
    private function fieldChangeAnswer(Request $request, string $structure): JsonResponse
    {
        $state = $this->snapshot($request, ['structure_id' => $structure, 'include_archived_structures' => true, 'records' => 'skip']);
        if (($state['status'] ?? null) === 'denied') {
            throw new AccessDeniedHttpException('larena_admin_storage_denied');
        }
        $selected = ($state['status'] ?? null) === 'ok' ? $this->selected($state, $structure) : null;
        if ($selected === null) {
            return AdminLiveRegion::answer(StorageLiveRegions::FIELD_CHANGE, null);
        }
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        $region = $this->fieldChangeRegion($this->fieldChangeNotice($request, $selected, $structure, $permissions, 'records'),
            $this->sectionPath().'/'.rawurlencode($structure));

        return AdminLiveRegion::answer(StorageLiveRegions::FIELD_CHANGE, AdminLiveRegion::cut((string) $region, StorageLiveRegions::FIELD_CHANGE));
    }

    /**
     * «Отменить изменение» of a field change running in the background: the change stops and the
     * storage keeps its fields; a change that has ended meanwhile says so. Storage checks the right
     * to migrate the structure; the scope is the actor's own.
     */
    public function cancelFieldChange(Request $request, string $structure): RedirectResponse
    {
        try {
            $receipt = $this->gateway->dispatch($this->actor($request), null, 'structure.field_change_cancel',
                ['structure_id' => $structure, 'confirmed' => $request->input('confirmed')]);
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            $receipt = ['status' => 'unavailable'];
        }
        $redirect = $this->redirector->route($request->input('return') === 'records' ? 'larena.admin.storage.records' : 'larena.admin.storage.settings',
            ['structure' => $structure]);
        $status = $receipt['status'] ?? null;
        $reason = $receipt['reason_code'] ?? null;
        if ($status === 'ok') {
            return $redirect->with('status', $this->fieldChanges()->cancelDone());
        }

        return $redirect->withErrors(['storage' => match (true) {
            $reason === StorageSectionGateway::FIELD_CHANGE_NOT_RUNNING => $this->fieldChanges()->cancelEnded(),
            $status === 'denied' => $this->copy('notice_denied'),
            default => $this->copy('notice_rejected'),
        }]);
    }

    /**
     * «Сменить ключ шифрования» of the settings page, once the person confirmed it: the storage gets a new key and the
     * run that seals its values again goes on in the background. Storage checks storage.encryption.manage and the
     * structure's update right; the scope is the actor's own.
     */
    public function rotateEncryptionKey(Request $request, string $structure): RedirectResponse
    {
        return $this->encryptionAction($request, $structure, 'structure.encryption_rotate',
            ['structure_id' => $structure, 'confirmed' => $request->input('confirmed')], 'encryption_keys.rotated');
    }

    /**
     * «Повторить» of a stopped encryption run: it goes on from where it stopped, back on the page it was pressed on.
     * Storage checks storage.encryption.manage and refuses a run that has not failed.
     */
    public function retryEncryptionRun(Request $request, string $structure): RedirectResponse
    {
        return $this->encryptionAction($request, $structure, 'structure.encryption_retry', ['structure_id' => $structure], 'encryption_run.retry_done');
    }

    /** @param array<string, mixed> $payload */
    private function encryptionAction(Request $request, string $structure, string $action, array $payload, string $done): RedirectResponse
    {
        try {
            $receipt = $this->gateway->dispatch($this->actor($request), null, $action, $payload);
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            $receipt = ['status' => 'unavailable'];
        }
        $redirect = $this->redirector->route($request->input('return') === 'records' ? 'larena.admin.storage.records' : 'larena.admin.storage.settings',
            ['structure' => $structure]);
        $status = $receipt['status'] ?? null;
        $reason = is_string($receipt['reason_code'] ?? null) ? $receipt['reason_code'] : '';
        if ($status === 'ok') {
            return $redirect->with('status', $reason === StorageSectionGateway::ENCRYPTION_STARTED
                ? $this->fieldChanges()->started($this->current($request, $structure)) : $this->copy($done));
        }

        return $redirect->withErrors(['storage' => match (true) {
            $status === 'denied' => $this->copy('notice_denied'),
            in_array($reason, [StorageSectionGateway::FIELD_CHANGE_RUNNING, StorageEncryptionRunState::RUNNING, StorageEncryptionRunState::FAILED], true)
                => (string) $this->fieldChanges()->refusal($reason, $this->current($request, $structure)),
            in_array($reason, StorageSectionGateway::ENCRYPTION_REASONS, true) => $this->encryptionError($reason),
            default => $this->copy('notice_rejected'),
        }]);
    }

    /** Storage's refusal about encryption in the section's words; an unknown one in general words. */
    private function encryptionError(string $reason): string
    {
        $key = 'larena-storage::admin.section.encryption.errors.'.$reason;
        $text = $this->translator->get($key);

        return is_string($text) && $text !== $key && $text !== '' ? $text : $this->copy('notice_rejected');
    }

    /** The storage as it is now, without its list; null when it cannot be read. @return array<string, mixed>|null */
    private function current(Request $request, string $structure): ?array
    {
        return $this->selected($this->snapshot($request, ['structure_id' => $structure, 'include_archived_structures' => true, 'records' => 'skip']), $structure);
    }

    private function fieldChanges(): StorageFieldChangeState
    {
        return new StorageFieldChangeState($this->translator);
    }

    private function copy(string $key): string
    {
        return (string) $this->translator->get('larena-storage::admin.section.'.$key);
    }

    private function translated(string $key): string
    {
        return (string) $this->translator->get('larena-admin::admin.'.$key);
    }

    private function recordId(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::RECORD_PATTERN, $value) === 1 ? $value : null;
    }

    private function actor(Request $request): string
    {
        $attribute = (string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor');
        $actor = $request->attributes->get($attribute);
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            throw new AccessDeniedHttpException('larena_admin_storage_section_actor_missing');
        }

        return $actor;
    }
}
