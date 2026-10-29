<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\StorageSitePackExports;
use Larena\Storage\Admin\Runtime\StorageSitePackPanel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * «Перенос на другой сайт» of a storage (larena-specs docs/architecture/2026-10-22-storage-sitepack.md): starts the
 * person's SitePack export of a storage they may see, cancels or retries one of their runs, and gives them its
 * archive. Storage checks the export right and the rights in the storage's scope on every call; a run belongs to the
 * person who started it, so another person's run, a run that does not exist and an unfinished one are not found.
 * The actor and the scope never come from input.
 */
final readonly class StorageSitePackController
{
    public function __construct(
        private ConfigRepository $config,
        private Redirector $redirector,
        private Translator $translator,
        private StorageWorkbenchGateway $gateway,
        private Container $container,
    ) {
    }

    public function export(Request $request, string $structure): RedirectResponse
    {
        $actor = $this->actor($request);
        $scope = $this->scope($actor, $structure);
        $back = $this->redirector->to($this->settingsPath($structure));
        $exports = $this->exports();
        if ($exports === null) {
            return $back->withErrors(['storage' => $this->copy('reasons.storage_sitepack_unavailable')]);
        }
        // «Включить зашифрованные поля в открытом виде»: Storage checks the decrypt right and audits the start.
        $started = $exports->start($actor, $scope, $structure, null, $request->input('include_encrypted_plaintext') === '1');

        return $this->outcome($back, $started, 'started');
    }

    public function cancel(Request $request, string $run): RedirectResponse
    {
        [$exports, $actor, $found] = $this->owned($request, $run);
        $back = $this->redirector->to($this->settingsPath((string) $found['structure_id']));

        return $this->outcome($back, $exports->cancel($actor, $run), 'cancelled');
    }

    public function retry(Request $request, string $run): RedirectResponse
    {
        [$exports, $actor, $found] = $this->owned($request, $run);
        $back = $this->redirector->to($this->settingsPath((string) $found['structure_id']));

        return $this->outcome($back, $exports->retry($actor, $run), 'retried');
    }

    /** The finished export's archive, to the person who started it only. */
    public function download(Request $request, string $run): BinaryFileResponse
    {
        $actor = $this->actor($request);
        $archive = $this->exports()?->archive($actor, $run);
        if ($archive === null) {
            throw new NotFoundHttpException();
        }
        $response = new BinaryFileResponse($archive['path'], 200, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], false);
        $response->setContentDisposition('attachment', (string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $archive['name']));

        return $response;
    }

    /**
     * @param array{run: array<string, mixed>|null, reason: string|null} $outcome
     */
    private function outcome(RedirectResponse $back, array $outcome, string $said): RedirectResponse
    {
        if ($outcome['reason'] !== null || $outcome['run'] === null) {
            return $back->withErrors(['storage' => (new StorageSitePackPanel($this->translator))->reason($outcome['reason'] ?? 'other')]);
        }
        $run = $outcome['run'];
        if (($run['state'] ?? null) === 'done' && $said !== 'cancelled') {
            return $back->with('status', $this->copy('finished'));
        }
        if (($run['state'] ?? null) === 'failed' && is_string($run['reason'] ?? null)) {
            return $back->withErrors(['storage' => (new StorageSitePackPanel($this->translator))->reason($run['reason'])]);
        }

        return $back->with('status', $this->copy($said));
    }

    /**
     * The person's own run; anything else is not found.
     *
     * @return array{0: StorageSitePackExports, 1: string, 2: array<string, mixed>}
     */
    private function owned(Request $request, string $run): array
    {
        $actor = $this->actor($request);
        $exports = $this->exports();
        $found = $exports?->read($actor, $run);
        if ($exports === null || $found === null || !is_string($found['structure_id'] ?? null)) {
            throw new NotFoundHttpException();
        }

        return [$exports, $actor, $found];
    }

    /** The scope of a storage the person may see; one they cannot see is not found, a refused read is denied. */
    private function scope(string $actor, string $structure): string
    {
        try {
            $state = $this->gateway->snapshot($actor, null, ['surface' => 'storage_section', 'structure_id' => $structure,
                'include_archived_structures' => true, 'records' => 'skip']);
        } catch (AccessDeniedHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new NotFoundHttpException();
        }
        if (($state['status'] ?? null) === 'denied') {
            throw new AccessDeniedHttpException('larena_admin_storage_sitepack_denied');
        }
        $selected = is_array($state['selected_structure'] ?? null) ? $state['selected_structure'] : null;
        if (($state['status'] ?? null) !== 'ok' || ($selected['structure_id'] ?? null) !== $structure || !is_string($state['scope_ref'] ?? null)) {
            throw new NotFoundHttpException();
        }

        return $state['scope_ref'];
    }

    private function exports(): ?StorageSitePackExports
    {
        try {
            return $this->container->make(StorageSitePackExports::class);
        } catch (Throwable) {
            return null;
        }
    }

    private function settingsPath(string $structure): string
    {
        return '/'.trim((string) $this->config->get('larena-admin.storage_section.uri', 'admin/storage'), '/').'/'.rawurlencode($structure).'/settings';
    }

    private function copy(string $key): string
    {
        return (string) $this->translator->get('larena-storage::admin.section.sitepack.'.$key);
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
