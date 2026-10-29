<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\HtmlString;
use Illuminate\Routing\Redirector;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Admin\Assets\AdminProductAssetManifest;
use Larena\Storage\Admin\StorageListOrder;
use Larena\Admin\Runtime\AdminDataviewPage;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final readonly class StorageWorkbenchController
{
    private const MAX_INPUT_BYTES = 1048576;
    private const MAX_OUTPUT_BYTES = 2097152;
    private const MAX_UPLOAD_BYTES = 10485760;
    private const MAX_PROJECTION_DEPTH = 24;
    private const MAX_PROJECTION_NODES = 5000;

    public function __construct(
        private ConfigRepository $config,
        private ViewFactory $views,
        private Redirector $redirector,
        private Translator $translator,
        private StorageWorkbenchGateway $gateway,
    ) {
    }

    public function show(Request $request): View|Response|JsonResponse
    {
        $actor = $this->actor($request);
        $requestedScope = $this->scope($request->query('scope_ref'));
        try {
            $state = $this->safeState($this->gateway->snapshot(
                $actor,
                $requestedScope,
                $this->query($request),
            ));
        } catch (Throwable) {
            $state = $this->unavailableState('storage_workbench_gateway_failed');
        }

        if ($request->expectsJson()) {
            return new JsonResponse(
                $state,
                $this->statusCode((string) $state['status']),
                ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
            );
        }

        $view = $this->views->make('larena-storage::admin.workbench', [
            ...$this->presentation($state, $request),
            'workbenchAction' => $this->redirector->getUrlGenerator()->route('larena.admin.workbench.dispatch'),
            'workbenchQueryAction' => $this->redirector->getUrlGenerator()->route('larena.admin.workbench.index'),
        ]);
        $status = $this->statusCode((string) $state['status']);

        return $status === 200 ? $view : new Response($view->render(), $status);
    }

    public function dispatch(Request $request): RedirectResponse|JsonResponse
    {
        $actor = $this->actor($request);
        $requestedScope = $this->scope($request->input('scope_ref'));
        $action = $request->input('action');
        $payload = $request->input('payload', []);
        if (!is_string($action)
            || preg_match('/^[a-z][a-z0-9_.]{1,80}$/', $action) !== 1
            || !is_array($payload)
            || array_is_list($payload)) {
            $receipt = $this->rejectedReceipt('storage_workbench_request_invalid', $requestedScope);
        } else {
            try {
                $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (strlen($encoded) > self::MAX_INPUT_BYTES) {
                    $receipt = $this->rejectedReceipt('storage_workbench_request_too_large', $requestedScope);
                } else {
                    $upload = $request->file('payload.file');
                    if ($upload !== null) {
                        if (!$upload instanceof UploadedFile
                            || !$upload->isValid()
                            || $upload->getSize() === false
                            || $upload->getSize() > self::MAX_UPLOAD_BYTES) {
                            $receipt = $this->rejectedReceipt('storage_workbench_upload_invalid', $requestedScope);
                        } else {
                            $payload['_uploaded_file'] = $upload;
                            $receipt = $this->safeReceipt($this->gateway->dispatch(
                                $actor,
                                $requestedScope,
                                $action,
                                $payload,
                            ));
                        }
                    } else {
                        $receipt = $this->safeReceipt($this->gateway->dispatch(
                            $actor,
                            $requestedScope,
                            $action,
                            $payload,
                        ));
                    }
                }
            } catch (Throwable) {
                $receipt = $this->rejectedReceipt('storage_workbench_gateway_failed', $requestedScope, 'unavailable');
            }
        }

        $status = $this->statusCode((string) $receipt['status']);
        if ($request->expectsJson()) {
            return new JsonResponse(
                $receipt,
                $status,
                ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
            );
        }
        $redirect = is_array($receipt['redirect'] ?? null) ? $receipt['redirect'] : [];
        $safeRedirect = [];
        foreach (['scope_ref', 'structure_id', 'record_id', 'document_id', 'page_id'] as $key) {
            $value = $redirect[$key] ?? null;
            if (is_string($value) && $value !== '' && strlen($value) <= 191) {
                $safeRedirect[$key] = $value;
            }
        }
        $response = $this->redirector->route('larena.admin.workbench.index', $safeRedirect);
        if (($receipt['status'] ?? null) === 'ok') {
            return $response->with('status', (string) $this->translator->get('larena-storage::admin.workbench.notice_success'));
        }

        return $response->withErrors([
            'workbench' => (string) $this->translator->get('larena-storage::admin.workbench.notice_rejected'),
        ]);
    }

    private function actor(Request $request): string
    {
        $attribute = (string) $this->config->get(
            'larena-auth.admin_entry.access_actor_attribute',
            'larena_access_actor',
        );
        $actor = $request->attributes->get($attribute);
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            throw new AccessDeniedHttpException('larena_admin_storage_workbench_actor_missing');
        }

        return $actor;
    }

    private function scope(mixed $scope): ?string
    {
        if ($scope === null || $scope === '') {
            return null;
        }
        if (!is_string($scope) || preg_match('/^scope:[a-z][a-z0-9_.:-]{0,184}$/', $scope) !== 1) {
            return 'scope:invalid';
        }

        return $scope;
    }

    /** @return array<string, mixed> */
    private function query(Request $request): array
    {
        $query = [];
        foreach (['structure_id', 'record_id', 'document_id', 'page_id', 'search', 'continuation', 'filter_field', 'filter_value', 'sort_field', 'sort_direction'] as $key) {
            $value = $request->query($key);
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value) || strlen($value) > ($key === 'continuation' ? 2048 : 191)) {
                $query['input_invalid'] = true;
                continue;
            }
            $query[$key] = $value;
        }
        $limit = $request->query('limit');
        if ($limit !== null && $limit !== '') {
            $parsed = filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            $query['limit'] = is_int($parsed) ? $parsed : 0;
        }
        $query['include_archived'] = $request->query('include_archived') === '1';
        // A direction means nothing without the field it orders by; an empty search submitted from
        // the list must not turn into a refused query.
        if (!isset($query['sort_field'])) {
            unset($query['sort_direction']);
        }

        return $query;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function safeState(array $state): array
    {
        $allowed = [
            'status', 'reason_code', 'scope_ref', 'structures', 'selected_structure', 'records',
            'files', 'documents', 'pages', 'publication', 'permissions', 'limits',
        ];
        foreach (array_keys($state) as $key) {
            if (!in_array($key, $allowed, true)) {
                return $this->unavailableState('storage_workbench_projection_invalid');
            }
        }
        $projectionNodes = 0;
        if (!in_array($state['status'] ?? null, ['ok', 'denied', 'rejected', 'unavailable'], true)
            || !$this->safeProjectionValue($state, 0, $projectionNodes)) {
            return $this->unavailableState('storage_workbench_projection_invalid');
        }
        try {
            $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            return $this->unavailableState('storage_workbench_projection_invalid');
        }
        if (strlen($encoded) > self::MAX_OUTPUT_BYTES) {
            return $this->unavailableState('storage_workbench_projection_too_large');
        }

        return $state;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function presentation(array $state, Request $request): array
    {
        $status = is_string($state['status'] ?? null) ? $state['status'] : 'unavailable';
        $scopeRef = is_string($state['scope_ref'] ?? null) ? $state['scope_ref'] : '';
        $structures = is_array($state['structures'] ?? null) ? $state['structures'] : [];
        $selected = is_array($state['selected_structure'] ?? null) ? $state['selected_structure'] : null;
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        $records = is_array($state['records'] ?? null)
            ? $state['records']
            : ['items' => [], 'continuation' => null, 'matched_count' => 0];
        $fields = is_array($selected['fields'] ?? null) ? $selected['fields'] : [];
        $items = is_array($records['items'] ?? null) ? $records['items'] : [];
        foreach ($items as $recordIndex => $record) {
            if (!is_array($record)) {
                continue;
            }
            $values = is_array($record['values'] ?? null) ? $record['values'] : [];
            $displayValues = [];
            foreach ($fields as $field) {
                if (!is_array($field) || !is_string($field['key'] ?? null)) {
                    continue;
                }
                $displayValues[$field['key']] = $this->displayValue($values[$field['key']] ?? null);
            }
            $record['display_values'] = $displayValues;
            $items[$recordIndex] = $record;
        }
        $records['items'] = $items;
        $state['records'] = $records;

        $documents = is_array($state['documents'] ?? null) ? $state['documents'] : [];
        foreach ($documents as $index => $document) {
            if (is_array($document)) {
                $document['document_json'] = $this->prettyJson($document['document'] ?? []);
                $documents[$index] = $document;
            }
        }
        $state['documents'] = $documents;

        $pages = is_array($state['pages'] ?? null) ? $state['pages'] : [];
        foreach ($pages as $index => $page) {
            if (is_array($page)) {
                $page['descriptor_json'] = $this->prettyJson($page['descriptor'] ?? []);
                $page['preview_json'] = is_array($page['preview'] ?? null)
                    ? $this->prettyJson($page['preview'])
                    : null;
                $pages[$index] = $page;
            }
        }
        $state['pages'] = $pages;

        $query = $this->query($request);
        $selectedId = is_string($selected['structure_id'] ?? null) ? $selected['structure_id'] : '';

        return [
            'workbench' => $state,
            'status' => $status,
            'scopeRef' => $scopeRef,
            'structures' => $structures,
            'selected' => $selected,
            'selectedId' => $selectedId,
            'newFieldIndex' => count($fields),
            'permissions' => $permissions,
            'records' => $records,
            'dataViewFilterFields' => $this->dataViewFilterFields($fields),
            'dataViewHtml' => $status === 'ok' && $selectedId !== ''
                ? $this->dataViewHtml($scopeRef, $selectedId, $fields, $permissions, $records)
                : null,
            'dataViewQuery' => [
                'page' => 1,
                'per_page' => 20,
                'search' => is_string($query['search'] ?? null) ? $query['search'] : '',
                'sort_field' => is_string($query['sort_field'] ?? null) ? $query['sort_field'] : '',
                'sort_direction' => ($query['sort_direction'] ?? null) === 'desc' ? 'desc' : 'asc',
            ],
            'publication' => is_array($state['publication'] ?? null) ? $state['publication'] : null,
            'query' => [
                'search' => is_string($query['search'] ?? null) ? $query['search'] : '',
                'include_archived' => ($query['include_archived'] ?? false) === true,
                'document_id' => is_string($query['document_id'] ?? null) ? $query['document_id'] : '',
                'page_id' => is_string($query['page_id'] ?? null) ? $query['page_id'] : '',
                // The sort the form shows: the person's, or the list's own order, newest first (StorageListOrder).
                'sort_field' => is_string($query['sort_field'] ?? null) ? $query['sort_field'] : StorageListOrder::CREATED_AT,
                'sort_direction' => is_string($query['sort_field'] ?? null)
                    ? (($query['sort_direction'] ?? null) === 'desc' ? 'desc' : 'asc') : 'desc',
            ],
        ];
    }

    /** @param list<mixed> $fields @return list<array<string, mixed>> */
    private function dataViewFilterFields(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null) || !is_string($field['label'] ?? null)) {
                continue;
            }
            $type = $field['type'] ?? null;
            $filter = match ($type) {
                'string', 'text' => ['control' => 'text', 'defaultOperator' => 'contains', 'operators' => ['contains', 'eq']],
                'integer', 'number' => ['control' => 'number', 'defaultOperator' => 'eq', 'operators' => ['eq']],
                'date' => ['control' => 'date', 'defaultOperator' => 'eq', 'operators' => ['eq']],
                'boolean' => ['control' => 'single-select', 'defaultOperator' => 'eq', 'operators' => ['eq'],
                    'options' => [['value' => 'true', 'label' => (string) $this->translator->get('larena-admin::admin.minimal_cms.yes')],
                        ['value' => 'false', 'label' => (string) $this->translator->get('larena-admin::admin.minimal_cms.no')]]],
                default => null,
            };
            if ($filter !== null) {
                $result[] = ['key' => $field['key'], 'label' => $field['label'], 'type' => $type, 'filter' => $filter];
            }
        }

        return $result;
    }

    /** @param list<mixed> $fields @param array<string, mixed> $permissions @param array<string, mixed> $records */
    private function dataViewHtml(string $scopeRef, string $structureId, array $fields, array $permissions, array $records): ?HtmlString
    {
        $columns = [];
        foreach ($fields as $field) {
            if (is_array($field) && is_string($field['key'] ?? null) && is_string($field['label'] ?? null)) {
                $columns[] = ['key' => $field['key'], 'label' => $field['label'],
                    'sortable' => !in_array($field['type'] ?? null, ['file', 'relation'], true)];
            }
        }
        if ($columns === []) {
            return null;
        }
        $bulk = ($permissions['record.bulk_archive'] ?? false) === true;
        try {
            $page = (new AdminDataviewPagePresenter())->present(new AdminDataviewPage(
                instanceId: 'storage-data-view-'.substr(hash('sha256', $scopeRef."\0".$structureId), 0, 12),
                title: (string) $this->translator->get('larena-storage::admin.workbench.records'),
                columns: $columns,
                total: max(0, (int) ($records['matched_count'] ?? 0)),
                pageId: 'page.admin.storage-workbench',
                portUrl: '/'.trim((string) $this->config->get('larena-admin.storage_workbench.uri', 'admin/storage-workbench'), '/').'/port',
                selectable: $bulk,
                bulkActions: $bulk ? ['bulk_delete' => (string) $this->translator->get('larena-storage::admin.workbench.bulk_archive')] : [],
                applyText: (string) $this->translator->get('larena-storage::admin.workbench.apply'),
            ), AdminProductAssetManifest::dataviewActivation());
        } catch (Throwable) {
            return null;
        }

        return new HtmlString($page['composite_html']);
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return $value === null ? '' : $this->prettyJson($value, false);
    }

    private function prettyJson(mixed $value, bool $pretty = true): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0),
            );
        } catch (Throwable) {
            return '{}';
        }
    }

    /** @param array<string, mixed> $receipt @return array<string, mixed> */
    private function safeReceipt(array $receipt): array
    {
        $keys = array_keys($receipt);
        sort($keys, SORT_STRING);
        $projectionNodes = 0;
        if ($keys !== ['notice', 'reason_code', 'redirect', 'scope_ref', 'status']
            || !in_array($receipt['status'] ?? null, ['ok', 'denied', 'rejected', 'unavailable'], true)
            || !$this->safeProjectionValue($receipt, 0, $projectionNodes)) {
            return $this->rejectedReceipt('storage_workbench_receipt_invalid', null, 'unavailable');
        }

        return $receipt;
    }

    private function safeProjectionValue(mixed $value, int $depth, int &$items): bool
    {
        if ($depth > self::MAX_PROJECTION_DEPTH || ++$items > self::MAX_PROJECTION_NODES) {
            return false;
        }
        if (is_string($value)) {
            return strlen($value) <= self::MAX_INPUT_BYTES;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return true;
        }
        if (!is_array($value)) {
            return false;
        }
        $forbidden = ['path', 'physical_path', 'disk', 'storage_key', 'secret', 'token', 'password', 'private_metadata', 'previous', 'exception', 'trace', 'stack', 'html', 'raw_html'];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                return false;
            }
            if (!$this->safeProjectionValue($item, $depth + 1, $items)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function unavailableState(string $reason): array
    {
        return [
            'status' => 'unavailable', 'reason_code' => $reason, 'scope_ref' => null,
            'structures' => [], 'selected_structure' => null,
            'records' => ['items' => [], 'continuation' => null, 'matched_count' => 0],
            'files' => ['nodes' => []], 'documents' => [], 'pages' => [], 'publication' => null,
            'permissions' => [], 'limits' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function rejectedReceipt(string $reason, ?string $scope, string $status = 'rejected'): array
    {
        return ['status' => $status, 'reason_code' => $reason, 'scope_ref' => $scope, 'notice' => $reason, 'redirect' => []];
    }

    private function statusCode(string $status): int
    {
        return match ($status) {
            'ok' => 200,
            'denied' => 403,
            'rejected' => 422,
            default => 503,
        };
    }
}
