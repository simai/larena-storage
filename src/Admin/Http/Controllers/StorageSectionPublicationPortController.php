<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\StorageSectionPublication;
use Larena\Storage\Admin\StorageSectionPublicationActions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * The host port of the records list of a storage bound to a publishable role (simai.dataview-port): the list's own
 * port answers the rows (larena.storage-section.port, its filters, sort and rights), this one adds the publication
 * badges and answers «Опубликовать» and «Снять с публикации» for a row and for checked rows. A records port cannot:
 * its shared bridge keeps only the record actions of a row, so these rows go to the composite directly.
 *
 * The composite's confirmation comes back with a digest of what it said; the operations run for the binding's site.
 */
final readonly class StorageSectionPublicationPortController
{
    private const LIST_PORT = 'larena.storage-section.port';

    private const MAX_BYTES = 1_048_576;

    /** How many records «for all» changes in one request, the same limit as the list's own archive under a filter. */
    private const MAX_UNDER_FILTER = 1000;

    private const PAGE_UNDER_FILTER = 100;

    public function __construct(
        private Router $router,
        private StorageWorkbenchGateway $gateway,
        private StorageSectionPublicationActions $actions,
        private ConfigRepository $config,
    ) {
    }

    /**
     * The capabilities of this list, for a person with these rights: as the storage's other lists offer them.
     *
     * @param array<string, mixed> $permissions
     * @return list<string>
     */
    public static function capabilities(array $permissions): array
    {
        return array_values(array_filter([
            'row-actions',
            'personal-column-settings',
            ($permissions['record.create'] ?? false) === true ? 'create-record' : null,
            ($permissions['dataview.saved_view.save'] ?? false) === true ? 'saved-views' : null,
            ($permissions['dataview.saved_view.save_shared'] ?? false) === true ? 'shared-saved-views' : null,
            ($permissions['dataview.preferences.save_shared'] ?? false) === true ? 'shared-column-settings' : null,
            'bulk-under-filter',
        ]));
    }

    public function __invoke(Request $request, string $structure): JsonResponse
    {
        $attribute = (string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor');
        $actor = $request->attributes->get($attribute);
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            throw new AccessDeniedHttpException('storage_publication_port_actor_missing');
        }
        $intent = $request->json('intent');
        $payload = $request->json('payload');
        if (!$request->isJson() || strlen($request->getContent()) > self::MAX_BYTES
            || !is_string($intent) || !is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return self::answer(['answer' => 'refused', 'reason' => 'port_request_invalid']);
        }
        $base = $this->gateway->snapshot($actor, null, ['structure_id' => $structure, 'surface' => 'storage_section', 'records' => 'skip']);
        if (($base['status'] ?? null) === 'denied') {
            return self::answer(['answer' => 'refused', 'reason' => 'access_denied']);
        }
        $scope = $base['scope_ref'] ?? null;
        $binding = ($base['status'] ?? null) === 'ok' && is_string($scope) && ($base['selected_structure']['structure_id'] ?? null) === $structure
            ? $this->actions->binding($actor, $scope, $structure) : null;
        if (!is_string($scope) || $binding === null) {
            return self::answer(['answer' => 'unavailable', 'reason' => 'collection_unavailable']);
        }
        try {
            return self::answer(match ($intent) {
                'query.change', 'view.open' => $this->query($request, $actor, $scope, $structure, $binding, $intent, $payload,
                    is_array($base['permissions'] ?? null) ? $base['permissions'] : []),
                'record.open', 'view.save', 'view.save_shared', 'view.delete', 'settings.save_personal', 'settings.save_shared' => $this->listPort($request, $scope, $structure, $intent, $payload),
                'record.mutate' => $this->mutate($request, $actor, $scope, $structure, $binding, $payload),
                'bulk.apply_selected' => $this->bulk($request, $actor, $scope, $structure, $binding, $payload),
                'bulk.apply_under_filter' => $this->underFilter($request, $actor, $scope, $structure, $binding, $payload),
                default => ['answer' => 'unavailable', 'reason' => 'capability-not-provided'],
            });
        } catch (Throwable) {
            return self::answer(['answer' => 'unavailable', 'reason' => 'port_failed']);
        }
    }

    /**
     * The list's own answer with the badges and the row's actions, as the composite takes a row: its cells beside its id.
     *
     * @param array{schema: string, site: string} $binding
     * @param array<mixed> $payload
     * @param array<string, mixed> $permissions
     * @return array<string, mixed>
     */
    private function query(Request $request, string $actor, string $scope, string $structure, array $binding, string $intent, array $payload, array $permissions): array
    {
        $answer = $this->listPort($request, $scope, $structure, $intent, $payload);
        $data = is_array($answer['data'] ?? null) ? $answer['data'] : null;
        if (($answer['answer'] ?? null) !== 'applied' || $data === null || !is_array($data['records'] ?? null) || !is_array($data['columns'] ?? null)) {
            return $answer;
        }
        $publication = $this->actions->publication();
        $can = $this->actions->can($actor);
        $records = [];
        foreach ($data['records'] as $record) {
            if (!is_array($record) || !is_string($record['id'] ?? null) || !is_int($record['revision'] ?? null)) {
                continue;
            }
            $own = array_values(array_intersect_key(['record.view' => 'view', 'record.edit' => 'edit'],
                array_flip(array_filter(is_array($record['actions'] ?? null) ? $record['actions'] : [], 'is_string'))));
            $records[] = ['id' => $record['id'], 'revision' => $record['revision'],
                'actions' => [...$own, ...$publication->actions($binding['schema'], $record['id'], $binding['site'], $record['revision'], $can)],
                StorageSectionPublication::COLUMN => $publication->badges($binding['schema'], $record['id'], $binding['site']),
            ] + (is_array($record['display_values'] ?? null) ? $record['display_values'] : []);
        }
        $data['records'] = $records;
        // Beside the name: a narrow list shows only its first columns, and where a record is out is what this list is about.
        array_splice($data['columns'], 2, 0, [$publication->column()]);
        $data['row_actions'] = $publication->rowActions($binding['site']);
        $data['capabilities'] = array_fill_keys(self::capabilities($permissions), true);
        $answer['data'] = $data;

        return $answer;
    }

    /**
     * @param array{schema: string, site: string} $binding
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    private function mutate(Request $request, string $actor, string $scope, string $structure, array $binding, array $payload): array
    {
        $chosen = $this->actions->publication()->parse($payload['action_id'] ?? null, $binding['site']);
        if ($chosen === null) {
            return $this->listPort($request, $scope, $structure, 'record.mutate', $payload);
        }
        $ids = $payload['record_ids'] ?? null;
        $id = is_array($ids) && count($ids) === 1 && is_string($ids[0] ?? null) ? $ids[0] : null;
        $revision = is_array($payload['revisions'] ?? null) && $id !== null ? ($payload['revisions'][$id] ?? null) : null;
        if ($id === null || !is_int($revision)) {
            return ['answer' => 'refused', 'reason' => 'record_ids_invalid'];
        }
        $refusal = $this->actions->write($actor, $scope, $structure, $binding, $id, $chosen[0], $chosen[1], $revision);

        // No rows in the answer: the view reads the page again, with the row as it now is.
        return $refusal === null ? ['answer' => 'applied', 'count' => 1] : $this->refused($refusal);
    }

    /**
     * The checked rows, all or nothing as far as their revisions go: a stale row is a conflict before anything is written
     * and the person confirms what is about to happen. A row the action means nothing for (already out) is left as it is.
     *
     * @param array{schema: string, site: string} $binding
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    private function bulk(Request $request, string $actor, string $scope, string $structure, array $binding, array $payload): array
    {
        $publication = $this->actions->publication();
        $action = $payload['action_id'] ?? null;
        $chosen = $publication->parse($action, $binding['site']);
        $ids = $payload['record_ids'] ?? null;
        $revisions = $payload['revisions'] ?? null;
        if (($chosen === null && $action !== 'bulk_delete') || !is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 100
            || !is_array($revisions) || array_is_list($revisions) || count($revisions) !== count($ids)) {
            return ['answer' => 'refused', 'reason' => 'selection_invalid'];
        }
        $digest = hash('sha256', (string) json_encode([$actor, $structure, $action, $ids, $revisions]));
        $confirm = $payload['confirm'] ?? null;
        if (!is_string($confirm)) {
            $key = $chosen === null ? 'larena-storage::admin.section.confirm_bulk' : 'larena-storage::admin.section.confirm_bulk_'.$chosen[0];

            return ['answer' => 'refused', 'reason' => 'confirm_required', 'data' => ['confirm' => ['digest' => $digest,
                'summary' => $publication->word($key, ['count' => (string) count($ids), 'locale' => $chosen[1] ?? ''])]]];
        }
        if (!hash_equals($digest, $confirm)) {
            return ['answer' => 'refused', 'reason' => 'confirm-mismatch'];
        }
        // The list's own archive of the checked rows, now that the person has confirmed them.
        if ($chosen === null) {
            return $this->listPort($request, $scope, $structure, 'bulk.apply_selected',
                ['action_id' => 'archive', 'record_ids' => $ids, 'revisions' => $revisions, 'confirmed' => true]);
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !is_int($revisions[$id] ?? null)) {
                return ['answer' => 'refused', 'reason' => 'selection_invalid'];
            }
        }
        [$verb, $locale] = $chosen;
        $done = 0;
        foreach ($ids as $id) {
            $revision = $revisions[$id];
            if (!StorageSectionPublication::allows($verb, $publication->head($binding['schema'], $id, $binding['site'], $locale), $revision)) {
                continue;
            }
            $refusal = $this->actions->write($actor, $scope, $structure, $binding, $id, $verb, $locale, $revision);
            if ($refusal === StorageSectionPublicationActions::CHANGED) {
                return ['answer' => 'conflict', 'reason' => 'stale-revision', 'count' => $done];
            }
            if ($refusal !== null) {
                return ['count' => $done] + $this->refused($refusal);
            }
            $done++;
        }

        return ['answer' => 'applied', 'count' => $done];
    }

    /**
     * «For all»: everything the list's filters and search find, as the person saw it counted. The list's own archive
     * takes it as it is; publishing goes record by record, each through the same operation as a row, read from the list
     * page by page. A number that moved is a conflict before anything is written, and the person confirms what will happen.
     *
     * @param array{schema: string, site: string} $binding
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    private function underFilter(Request $request, string $actor, string $scope, string $structure, array $binding, array $payload): array
    {
        $publication = $this->actions->publication();
        $action = $payload['action_id'] ?? null;
        $chosen = $publication->parse($action, $binding['site']);
        $total = $payload['confirmed_total'] ?? null;
        $search = $payload['search'] ?? '';
        $filters = $payload['filters'] ?? [];
        if (($chosen === null && $action !== 'bulk_delete') || !is_int($total) || $total < 0 || !is_string($search) || !is_array($filters)) {
            return ['answer' => 'refused', 'reason' => 'selection_invalid'];
        }
        if ($total > self::MAX_UNDER_FILTER) {
            return $this->refused('bulk_too_many');
        }
        if ($total === 0) {
            return ['answer' => 'applied', 'count' => 0];
        }
        $digest = hash('sha256', (string) json_encode([$actor, $structure, $action, $total, $search, $filters]));
        $confirm = $payload['confirm'] ?? null;
        if (!is_string($confirm)) {
            $key = $chosen === null ? 'larena-storage::admin.section.confirm_bulk' : 'larena-storage::admin.section.confirm_bulk_'.$chosen[0];

            return ['answer' => 'refused', 'reason' => 'confirm_required', 'data' => ['confirm' => ['digest' => $digest,
                'summary' => $publication->word($key, ['count' => (string) $total, 'locale' => $chosen[1] ?? ''])]]];
        }
        if (!hash_equals($digest, $confirm)) {
            return ['answer' => 'refused', 'reason' => 'confirm-mismatch'];
        }
        if ($chosen === null) {
            return $this->listPort($request, $scope, $structure, 'bulk.apply_under_filter',
                ['action_id' => 'archive', 'confirmed_total' => $total, 'search' => $search, 'filters' => $filters]);
        }
        $found = [];
        for ($page = 1; count($found) < $total; $page++) {
            $answer = $this->listPort($request, $scope, $structure, 'query.change', ['sequence' => $page, 'reason' => 'host-connected',
                'query' => ['filters' => $filters, 'search' => $search, 'sort' => [], 'page' => $page, 'per_page' => self::PAGE_UNDER_FILTER]]);
            $rows = is_array($answer['data']['records'] ?? null) ? $answer['data']['records'] : null;
            if (($answer['answer'] ?? null) !== 'applied' || $rows === null) {
                return ['answer' => 'refused', 'reason' => 'filters_invalid'];
            }
            if (($answer['data']['total'] ?? null) !== $total) {
                return ['answer' => 'conflict', 'reason' => 'count_changed', 'current_total' => $answer['data']['total'] ?? null];
            }
            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['id'] ?? null) && is_int($row['revision'] ?? null)) {
                    $found[$row['id']] = $row['revision'];
                }
            }
            if ($rows === []) {
                break;
            }
        }
        [$verb, $locale] = $chosen;
        $done = 0;
        foreach ($found as $id => $revision) {
            $id = (string) $id;
            if (!StorageSectionPublication::allows($verb, $publication->head($binding['schema'], $id, $binding['site'], $locale), $revision)) {
                continue;
            }
            $refusal = $this->actions->write($actor, $scope, $structure, $binding, $id, $verb, $locale, $revision);
            if ($refusal === StorageSectionPublicationActions::CHANGED) {
                return ['answer' => 'conflict', 'reason' => 'stale-revision', 'count' => $done];
            }
            if ($refusal !== null) {
                return ['count' => $done] + $this->refused($refusal);
            }
            $done++;
        }

        return ['answer' => 'applied', 'count' => $done];
    }

    /**
     * The list's own port, in this request: the section's middleware has passed, and the same actor and scope stand.
     *
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    private function listPort(Request $request, string $scope, string $structure, string $intent, array $payload): array
    {
        $route = $this->router->getRoutes()->getByName(self::LIST_PORT);
        if ($route === null) {
            return ['answer' => 'unavailable', 'reason' => 'collection_unavailable'];
        }
        $request->merge(['scope_ref' => $scope, 'structure_id' => $structure, 'intent' => $intent, 'payload' => $payload]);
        $response = $route->bind($request)->run();
        $answer = $response instanceof JsonResponse ? $response->getData(true) : null;

        return is_array($answer) ? $answer : ['answer' => 'unavailable', 'reason' => 'port_failed'];
    }

    /** @return array<string, mixed> */
    private function refused(string $reason): array
    {
        $publication = $this->actions->publication();
        if ($reason === StorageSectionPublicationActions::CHANGED) {
            return ['answer' => 'conflict', 'reason' => 'stale-revision'];
        }
        // A notice is a sentence a person can act on; the code stays in the reason (owner decision 2026-10-12).
        $key = 'larena-admin::structure_editors.refusals.'.$reason;
        $known = $publication->word($key);

        return ['answer' => 'refused', 'reason' => $reason,
            'message' => $known !== $key ? $known : $publication->word('larena-admin::structure_editors.refusals.default')];
    }

    /** @param array<string, mixed> $answer */
    private static function answer(array $answer): JsonResponse
    {
        $status = match ($answer['answer'] ?? null) {
            'applied' => 200, 'conflict' => 409, 'refused' => 422, default => 503,
        };

        return new JsonResponse($answer, $status, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
