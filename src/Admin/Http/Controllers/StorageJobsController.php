<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Redirector;
use InvalidArgumentException;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\StorageBackgroundJobs;
use Larena\Storage\Admin\StorageCsvExportRows;
use Larena\Admin\Runtime\AdminControls;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminElementDocumentRenderer;
use Larena\Admin\Runtime\AdminLiveRegionPart;
use Larena\Admin\Runtime\AdminSfElement;
use Larena\Storage\Admin\Runtime\StorageJobsPanel;
use Larena\Storage\Admin\Runtime\StorageLiveRegions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * A storage's background jobs (slice 4 of the storage engine for millions): the person's imports and exports with
 * their progress, «Повторить» and «Отменить» through the queue's control, «Применить» of an import the queue
 * checked, an export of the list in the background and its download. Everything goes through the section's owner gateway for the person's rights in the storage:
 * an import job needs the right to add or change records, an export job the right to read the list. The actor and
 * scope never come from input, and a job is found only in the person's own ledger.
 */
final readonly class StorageJobsController
{
    private const MAX_QUERY_BYTES = 65_536;

    public function __construct(
        private ConfigRepository $config,
        private ViewFactory $views,
        private Redirector $redirector,
        private Translator $translator,
        private StorageWorkbenchGateway $gateway,
        private Container $container,
    ) {
    }

    public function index(Request $request, string $structure): View|Response
    {
        $state = $this->state($request, $structure);
        $jobs = $this->jobs();
        $query = $request->query('query');
        $query = is_string($query) && strlen($query) <= self::MAX_QUERY_BYTES ? $query : '';
        $narrowed = $this->narrowed($query);
        $token = $request->hasSession() ? (string) $request->session()->token() : '';
        $listed = $jobs === null ? [] : $jobs->jobs($this->actor($request), $structure);
        $panel = new StorageJobsPanel($this->translator);
        $items = $panel->items($listed, $this->jobsPath($structure), $token, $state['may_import'],
            $this->sectionPath().'/'.rawurlencode($structure).'/import');

        // The page as described parts (developer remark 3', stage 5): the alerts, the export by the queue, and the jobs panel,
        // which follows its jobs by itself while one is queued or running (StorageLiveRegions); the view prints them with {{ }}.
        $parts = [];
        foreach ($this->alerts($request, $jobs === null) as $role => $alert) {
            if ($alert !== null) {
                $parts[] = new AdminElement('div', ['role' => $role], [$alert]);
            }
        }
        if ($jobs !== null) {
            $parts[] = new AdminElement('section', ['class' => 'larena-panel', 'aria-labelledby' => 'storage-jobs-export'], [
                new AdminElement('h2', ['id' => 'storage-jobs-export'], $this->copy('export_title')),
                new AdminElement('p', [], $this->copy($narrowed ? 'export_text_query' : 'export_text_all')),
                new AdminElement('form', ['method' => 'post', 'action' => $this->jobsPath($structure).'/export', 'data-larena-storage-export-queue' => null], [
                    ...AdminElement::hiddenInputs(['_token' => $token, 'query' => $query]),
                    AdminControls::submitNode($this->copy('export_start'), 'primary'),
                ]),
            ]);
        }
        $parts[] = new AdminLiveRegionPart(StorageLiveRegions::JOBS, StorageLiveRegions::jobsActive($listed), $this->jobsPath($structure),
            StorageLiveRegions::jobsSaid($items), [$panel->describe($items)]);

        return $this->views->make('larena-storage::admin.jobs', [
            'structureId' => $structure,
            'selected' => $state['selected'],
            'recordsPath' => $this->sectionPath().'/'.rawurlencode($structure),
            'jobs' => $items,
            'content' => (new AdminElementDocumentRenderer())->tree(...$parts),
        ]);
    }

    /** An export of the list (its search, filters and sort) to a private file, by the queue. */
    public function export(Request $request, string $structure): RedirectResponse
    {
        $state = $this->state($request, $structure);
        $jobs = $this->jobs();
        $back = $this->redirector->to($this->jobsPath($structure));
        if ($jobs === null) {
            return $back->withErrors(['storage' => $this->copy('unavailable')]);
        }
        $raw = $request->input('query');
        try {
            $query = StorageCsvExportRows::query(is_string($raw) && strlen($raw) <= self::MAX_QUERY_BYTES ? $raw : '',
                StorageCsvExportRows::shownKeys($state['fields']));
        } catch (InvalidArgumentException) {
            return $back->withErrors(['storage' => $this->copy('export_query_refused')]);
        }
        $locale = $this->translator->getLocale();
        $ref = $jobs->startExport($this->actor($request), $state['scope'], $structure, $query,
            preg_match('/\A[a-z]{2}(?:[_-][A-Za-z]{2})?\z/D', $locale) === 1 ? $locale : 'en');

        return $ref === null
            ? $back->withErrors(['storage' => $this->copy('start_refused')])
            : $back->with('status', $this->copy('export_queued'));
    }

    /**
     * «Применить» of an import the queue checked: the same job writes the plan (storage.import.apply), bound to the
     * digest of the plan the person saw. Only the person's own check, with the right to import, is applied.
     */
    public function apply(Request $request, string $structure, string $job): RedirectResponse
    {
        $jobs = $this->guarded($request, $structure, $job);
        $state = $this->state($request, $structure);
        $digest = $request->input('digest');
        $planned = $jobs->job($this->actor($request), $structure, $job);
        if (!is_string($digest) || $planned === null || $planned['plan'] === null
            || !$jobs->applyPlan($this->actor($request), $state['scope'], $structure, $job, $digest)) {
            return $this->redirector->to($this->sectionPath().'/'.rawurlencode($structure).'/import?job='.$job)
                ->withErrors(['storage' => $this->copy('apply_refused')]);
        }
        $counts = $planned['plan']['counts'];

        return $this->redirector->to($this->jobsPath($structure))->with('status', (string) $this->translator->get(
            'larena-storage::admin.section.import.queued', ['count' => $counts['create'] + $counts['update'] + $counts['same']]));
    }

    public function retry(Request $request, string $structure, string $job): RedirectResponse
    {
        $jobs = $this->guarded($request, $structure, $job);

        return $jobs->retry($this->actor($request), $structure, $job)
            ? $this->redirector->to($this->jobsPath($structure))->with('status', $this->copy('retried'))
            : $this->redirector->to($this->jobsPath($structure))->withErrors(['storage' => $this->copy('retry_refused')]);
    }

    public function cancel(Request $request, string $structure, string $job): RedirectResponse
    {
        $jobs = $this->guarded($request, $structure, $job);

        return $jobs->cancel($this->actor($request), $structure, $job)
            ? $this->redirector->to($this->jobsPath($structure))->with('status', $this->copy('cancelled'))
            : $this->redirector->to($this->jobsPath($structure))->withErrors(['storage' => $this->copy('cancel_refused')]);
    }

    /** The finished export, to the person who started it only. */
    public function download(Request $request, string $structure, string $job): BinaryFileResponse
    {
        $path = $this->guarded($request, $structure, $job)->download($this->actor($request), $structure, $job);
        if ($path === null) {
            throw new NotFoundHttpException();
        }
        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], false);
        $response->setContentDisposition('attachment', preg_replace('/[^a-z0-9_.-]/', '-', $structure).'-'.(new DateTimeImmutable())->format('Y-m-d').'.csv');

        return $response;
    }

    /**
     * The person's job in this storage, with the right its kind needs: an import's the right to add or change
     * records, an export's the right to read the list. Anything else is not found.
     */
    private function guarded(Request $request, string $structure, string $job): StorageBackgroundJobs
    {
        $state = $this->state($request, $structure);
        $jobs = $this->jobs();
        $kind = $jobs?->kind($this->actor($request), $structure, $job);
        if ($jobs === null || $kind === null || ($kind === 'import' && !$state['may_import'])) {
            throw new NotFoundHttpException();
        }

        return $jobs;
    }

    /**
     * The storage as the person may see it; a storage they cannot read is not found, a refused read is denied.
     *
     * @return array{selected: array<string, mixed>, scope: string, fields: list<array<string, mixed>>, may_import: bool}
     */
    private function state(Request $request, string $structure): array
    {
        try {
            $state = $this->gateway->snapshot($this->actor($request), null, ['surface' => 'storage_section', 'structure_id' => $structure, 'records' => 'skip']);
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new NotFoundHttpException();
        }
        if (($state['status'] ?? null) === 'denied') {
            throw new AccessDeniedHttpException('larena_admin_storage_jobs_denied');
        }
        $selected = is_array($state['selected_structure'] ?? null) ? $state['selected_structure'] : null;
        if (($state['status'] ?? null) !== 'ok' || $selected === null || ($selected['structure_id'] ?? null) !== $structure
            || !is_string($state['scope_ref'] ?? null)) {
            throw new NotFoundHttpException();
        }
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        $fields = [];
        foreach (is_array($selected['fields'] ?? null) ? $selected['fields'] : [] as $field) {
            if (is_array($field)) {
                $fields[] = $field;
            }
        }

        return [
            'selected' => $selected,
            'scope' => $state['scope_ref'],
            'fields' => $fields,
            'may_import' => ($selected['archived'] ?? false) !== true
                && (($permissions['record.create'] ?? false) === true || ($permissions['record.update'] ?? false) === true),
        ];
    }

    private function jobs(): ?StorageBackgroundJobs
    {
        try {
            $jobs = $this->container->make(StorageBackgroundJobs::class);
        } catch (Throwable) {
            return null;
        }

        return $jobs->available() ? $jobs : null;
    }

    /** Whether the list's query narrows the export (a search or a filter). */
    private function narrowed(string $query): bool
    {
        $decoded = $query === '' ? null : json_decode($query, true);

        return is_array($decoded) && ((is_string($decoded['search'] ?? null) && trim($decoded['search']) !== '')
            || (is_array($decoded['filters'] ?? null) && $decoded['filters'] !== []));
    }

    /** The page's own alerts, by their role; the session «status» is shown once by the admin shell. @return array{alert: ?AdminSfElement, status: ?AdminSfElement} */
    private function alerts(Request $request, bool $unavailable): array
    {
        $bag = $request->hasSession() ? $request->session()->get('errors') : null;
        $error = $bag instanceof \Illuminate\Support\ViewErrorBag ? $bag->first('storage') : '';

        return [
            'alert' => $error !== '' ? AdminSfElement::alert($error, 'danger') : null,
            'status' => $unavailable ? AdminSfElement::alert($this->copy('unavailable'), 'info') : null,
        ];
    }

    private function jobsPath(string $structure): string
    {
        return $this->sectionPath().'/'.rawurlencode($structure).'/jobs';
    }

    private function sectionPath(): string
    {
        return '/'.trim((string) $this->config->get('larena-admin.storage_section.uri', 'admin/storage'), '/');
    }

    private function copy(string $key): string
    {
        return (string) $this->translator->get('larena-storage::admin.section.jobs.'.$key);
    }

    private function actor(Request $request): string
    {
        $attribute = (string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor');
        $actor = $request->attributes->get($attribute);
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            throw new AccessDeniedHttpException('larena_admin_storage_jobs_actor_missing');
        }

        return $actor;
    }
}
