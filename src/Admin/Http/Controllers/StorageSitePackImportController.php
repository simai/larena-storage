<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Redirector;
use Illuminate\Support\HtmlString;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\StorageSitePackImports;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminElementDocumentRenderer;
use Larena\Admin\Runtime\AdminLiveRegionPart;
use Larena\Admin\Runtime\AdminNode;
use Larena\Admin\Runtime\AdminSfElement;
use Larena\Storage\Admin\Runtime\StorageSitePackImportPage;
use Larena\Storage\Admin\Runtime\StorageLiveRegions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * «Загрузить хранилище» (larena-specs docs/architecture/2026-10-22-storage-sitepack.md): a SitePack archive uploaded
 * into the person's scope, its plan, the person's choices planned again, «Загрузить» bound to the plan's digest, and
 * the import's progress, cancel and retry.
 *
 * The pages are there only for a person who may import storages (storage.sitepack.import) and create storages and
 * records in their scope; for anyone else they are not found. Storage checks the same rights again on every call, and
 * a run belongs to the person who started it: another person's run, an export and a run that does not exist are not
 * found. The actor and the scope never come from input; an option a person sends is checked by Storage.
 */
final readonly class StorageSitePackImportController
{
    public const string RUN_PATTERN = 'storage-sitepack-[0-9a-f]{32}';
    /** Choices of a local storage for the storages of the source site, at most. */
    private const int MAX_REFERENCES = 100;

    public function __construct(
        private ConfigRepository $config,
        private ViewFactory $views,
        private Redirector $redirector,
        private Translator $translator,
        private StorageWorkbenchGateway $gateway,
        private Container $container,
    ) {
    }

    /** The upload, and the person's latest imports. */
    public function form(Request $request): View
    {
        [$imports, $actor] = $this->guard($request);
        $page = $this->page();
        $html = $this->draw($this->notice($request), $page->upload($this->importPath(), $this->token($request), $this->maxBytes()),
            $page->runs($imports->runs($actor) ?? [], $this->importPath()));

        return $this->views->make('larena-storage::admin.sitepack-import', [
            'title' => $this->copy('title'), 'description' => $this->copy('description'), 'html' => $html, 'back' => $this->sectionPath(),
        ]);
    }

    /** Receives the archive and plans it with the defaults; the person goes on on the run's page. */
    public function upload(Request $request): RedirectResponse
    {
        [$imports, $actor, $state] = $this->guard($request);
        $back = $this->redirector->to($this->importPath());
        $file = $request->file('archive');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $back->withErrors(['storage' => $this->copy('upload_missing')]);
        }
        if ((int) $file->getSize() > $this->maxBytes()) {
            return $back->withErrors(['storage' => $this->copy('upload_too_large')]);
        }
        $path = (string) $file->getRealPath();
        if (!self::isZip($path, (string) $file->getClientOriginalName())) {
            return $back->withErrors(['storage' => $this->copy('upload_not_zip')]);
        }
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', basename((string) $file->getClientOriginalName()));
        $received = $imports->receive($actor, (string) $state['scope_ref'], $path, $name !== '' ? $name : 'sitepack.zip');
        if ($received['run'] === null) {
            return $back->withErrors(['storage' => $this->page()->reason($received['reason'] ?? 'other')]);
        }
        $to = $this->redirector->to($this->runPath((string) $received['run']['ref']));

        if ($received['reason'] !== null) {
            return $to->withErrors(['storage' => $this->page()->reason($received['reason'])]);
        }

        // While the archive is checked the run's own panel says so and follows the check in place; a notice above it
        // would stay behind once the plan is there (QA 2026-10-22).
        return ($received['run']['planning'] ?? false) === true ? $to : $to->with('status', $this->copy('status_received'));
    }

    /** A run's page: its state, its plan, its progress or its end. */
    public function show(Request $request, string $run): View
    {
        [$imports, $actor, $state, $permissions] = $this->guard($request);
        $found = $imports->read($actor, $run) ?? throw new NotFoundHttpException();
        $structures = [];
        foreach (is_array($state['structures'] ?? null) ? $state['structures'] : [] as $structure) {
            if (is_array($structure) && is_string($structure['structure_id'] ?? null) && ($structure['archived'] ?? false) !== true) {
                $structures[$structure['structure_id']] = is_string($structure['label'] ?? null) ? $structure['label'] : $structure['structure_id'];
            }
        }
        // The run's part reads itself again while its archive is checked or the import runs (StorageLiveRegions).
        $html = $this->draw($this->notice($request), new AdminLiveRegionPart(StorageLiveRegions::SITEPACK_IMPORT, StorageLiveRegions::sitePackImportActive($found),
            $this->runPath($run), $this->page()->stateText($found), [$this->page()->run($found, [
                'run_path' => $this->runPath($run),
                'storage_path' => $this->sectionPath(),
                'token' => $this->token($request),
                'can_publish' => ($permissions['publication.publish'] ?? false) === true,
                'structures' => $structures,
            ])]));

        return $this->views->make('larena-storage::admin.sitepack-import', [
            'title' => $this->copy('run_title'), 'description' => $this->copy('description'), 'html' => $html, 'back' => $this->importPath(),
        ]);
    }

    /** Plans the run again with what the person chose. */
    public function plan(Request $request, string $run): RedirectResponse
    {
        [$imports, $actor] = $this->owned($request, $run);

        return $this->outcome($run, $imports->plan($actor, $run, self::choices($request)), 'status_planned');
    }

    /** Applies the plan the person saw, by its digest. */
    public function apply(Request $request, string $run): RedirectResponse
    {
        [$imports, $actor] = $this->owned($request, $run);
        $digest = $request->input('digest');
        if (!is_string($digest) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $digest) !== 1) {
            return $this->redirector->to($this->runPath($run))->withErrors(['storage' => $this->page()->reason('storage_sitepack_plan_digest_mismatch')]);
        }

        return $this->outcome($run, $imports->apply($actor, $run, $digest), 'status_applying');
    }

    public function cancel(Request $request, string $run): RedirectResponse
    {
        [$imports, $actor] = $this->owned($request, $run);

        return $this->outcome($run, $imports->cancel($actor, $run), 'status_cancelled');
    }

    public function retry(Request $request, string $run): RedirectResponse
    {
        [$imports, $actor] = $this->owned($request, $run);

        return $this->outcome($run, $imports->retry($actor, $run), 'status_retried');
    }

    /**
     * What the person chose on the plan: the storage's id and name, the publication, a local storage for each storage
     * of the source site (pairs `reference_from_<n>` → `reference_to_<n>`; «the same ID» maps nothing), a copy.
     *
     * @return array{target_structure_id: ?string, target_label: ?string, publication: string, reference_targets: array<string, string>, copy: bool}
     */
    public static function choices(Request $request): array
    {
        $text = static function (mixed $value): ?string {
            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };
        $targets = [];
        for ($index = 0; $index < self::MAX_REFERENCES; $index++) {
            $from = $text($request->input('reference_from_'.$index));
            if ($from === null) {
                continue;
            }
            $to = $text($request->input('reference_to_'.$index));
            if ($to !== null && $to !== StorageSitePackImportPage::SAME_ID && $to !== \Larena\Admin\Runtime\AdminFrameworkForm::NONE) {
                $targets[$from] = $to;
            }
        }

        return [
            'target_structure_id' => $text($request->input('target_structure_id')),
            'target_label' => $text($request->input('target_label')),
            'publication' => $request->input('publication') === 'keep' ? 'keep' : 'draft',
            'reference_targets' => $targets,
            'copy' => $request->input('copy') === '1',
        ];
    }

    /** Whether the upload is a ZIP archive: its name ends in .zip and it starts as one. */
    public static function isZip(string $path, string $name): bool
    {
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'zip' || !is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 4);
        fclose($handle);

        return in_array($head, ["PK\x03\x04", "PK\x05\x06"], true);
    }

    /**
     * @param array{run: array<string, mixed>|null, reason: string|null} $outcome
     */
    private function outcome(string $run, array $outcome, string $said): RedirectResponse
    {
        $back = $this->redirector->to($this->runPath($run));
        if ($outcome['reason'] !== null || $outcome['run'] === null) {
            return $back->withErrors(['storage' => $this->page()->reason($outcome['reason'] ?? 'other')]);
        }
        $state = $outcome['run']['state'] ?? null;
        if ($state === 'failed') {
            return $back->withErrors(['storage' => $this->page()->reason(is_string($outcome['run']['reason'] ?? null) ? $outcome['run']['reason'] : 'other')]);
        }
        if ($state === 'done' && $said !== 'status_cancelled') {
            return $back->with('status', $this->copy('status_done'));
        }

        return $back->with('status', $this->copy($said));
    }

    /**
     * The import service and the person, for a person who may import into their scope; anyone else is not found.
     *
     * @return array{0: StorageSitePackImports, 1: string, 2: array<string, mixed>, 3: array<string, mixed>}
     */
    private function guard(Request $request): array
    {
        $actor = $this->actor($request);
        try {
            $state = $this->gateway->snapshot($actor, null, ['surface' => 'storage_section', 'records' => 'skip']);
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new NotFoundHttpException();
        }
        if (($state['status'] ?? null) === 'denied') {
            throw new AccessDeniedHttpException('larena_admin_storage_sitepack_import_denied');
        }
        $permissions = is_array($state['permissions'] ?? null) ? $state['permissions'] : [];
        if (($state['status'] ?? null) !== 'ok' || !is_string($state['scope_ref'] ?? null) || !self::mayImport($permissions)) {
            throw new NotFoundHttpException();
        }
        $imports = $this->imports() ?? throw new NotFoundHttpException();

        return [$imports, $actor, $state, $permissions];
    }

    /** @return array{0: StorageSitePackImports, 1: string} the person's own import run, or not found */
    private function owned(Request $request, string $run): array
    {
        [$imports, $actor] = $this->guard($request);
        if ($imports->read($actor, $run) === null) {
            throw new NotFoundHttpException();
        }

        return [$imports, $actor];
    }

    /**
     * Who sees «Загрузить хранилище»: a person who may import storages and create storages and records.
     *
     * @param array<array-key, mixed> $permissions the section's permissions
     */
    public static function mayImport(array $permissions): bool
    {
        return ($permissions['sitepack.import'] ?? false) === true
            && ($permissions['structure.create'] ?? false) === true
            && ($permissions['record.create'] ?? false) === true;
    }

    private function imports(): ?StorageSitePackImports
    {
        try {
            return $this->container->make(StorageSitePackImports::class);
        } catch (Throwable) {
            return null;
        }
    }

    /** The largest archive accepted: the section's setting, within what PHP accepts for an upload and a post. */
    private function maxBytes(): int
    {
        $limit = (int) $this->config->get('larena-admin.storage_section.sitepack_import_max_bytes', 2_147_483_648);
        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = self::bytes((string) ini_get($setting));
            if ($bytes > 0) {
                $limit = min($limit, $bytes);
            }
        }

        return max(1, $limit);
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if (preg_match('/\A(\d+)\s*([kmg]?)\z/i', $value, $match) !== 1) {
            return 0;
        }

        return (int) $match[1] * match (strtolower($match[2])) {
            'g' => 1_073_741_824,
            'm' => 1_048_576,
            'k' => 1024,
            default => 1,
        };
    }

    /** A refused action said on the page it comes back to. */
    private function notice(Request $request): ?AdminElement
    {
        $bag = $request->hasSession() ? $request->session()->get('errors') : null;
        $text = $bag instanceof \Illuminate\Support\ViewErrorBag ? $bag->first('storage') : '';

        return $text !== '' ? new AdminElement('div', ['role' => 'alert', 'data-larena-sitepack-import-notice' => null], [AdminSfElement::alert($text, 'danger')]) : null;
    }

    /** The page's described parts (developer remark 3', stage 5), drawn by the shared renderer for the view to print with {{ }}. */
    private function draw(?AdminNode ...$parts): HtmlString
    {
        return (new AdminElementDocumentRenderer())->tree(...array_values(array_filter($parts, static fn (?AdminNode $part): bool => $part !== null)));
    }

    private function page(): StorageSitePackImportPage
    {
        return new StorageSitePackImportPage($this->translator);
    }

    private function token(Request $request): string
    {
        return $request->hasSession() ? (string) $request->session()->token() : '';
    }

    private function sectionPath(): string
    {
        return '/'.trim((string) $this->config->get('larena-admin.storage_section.uri', 'admin/storage'), '/');
    }

    private function importPath(): string
    {
        return $this->sectionPath().'/sitepack/import';
    }

    private function runPath(string $run): string
    {
        return $this->importPath().'/'.rawurlencode($run);
    }

    private function copy(string $key): string
    {
        return (string) $this->translator->get('larena-storage::admin.section.sitepack_import.'.$key);
    }

    private function actor(Request $request): string
    {
        $attribute = (string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor');
        $actor = $request->attributes->get($attribute);
        if (!is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            throw new AccessDeniedHttpException('larena_admin_storage_sitepack_actor_missing');
        }

        return $actor;
    }
}
