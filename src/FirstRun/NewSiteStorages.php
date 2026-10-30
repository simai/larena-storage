<?php

declare(strict_types=1);

namespace Larena\Storage\FirstRun;

use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\SiteRedirect\SiteRedirectsStorage;

/**
 * The storages of a new site («Создать сайт», larena-specs docs/architecture/2026-10-29-sites-work-plan.md, step 2.9):
 * its «Страницы сайта» with a Home page as a draft, and its «Переадресации».
 *
 * The first site's pages are the schema `site.pages`, adopted in place (SitePagesStorage), and there is one such schema
 * per installation. Every other site's pages are an ordinary storage with its own schema, bound to the pages role in that
 * site, so the same address may be used on both.
 *
 * The workbench checks the person's rights in the scope they work in: the caller makes the site the one they work in
 * before calling. Each step sees what an earlier run left and goes on from there, so a run that stopped can run again.
 */
final readonly class NewSiteStorages
{
    private const STRUCTURES = 'larena_storage_workbench_structures';

    private const RECORDS = 'larena_storage_records';

    public function __construct(
        private ConnectionInterface $database,
        private StorageWorkbench $workbench,
        private StructureRoleRegistry $roles,
        private StarterStructureRoles $starterRoles,
        private SitePagesStorage $pages,
        private SiteRedirectsStorage $redirects,
    ) {
    }

    /** Whether a site has no page tree or no redirects yet: a new site that stopped half way. */
    public function unfinished(string $siteRef): bool
    {
        return $this->roles->listStructures($siteRef, StarterSite::ROLE_REF) === []
            || $this->roles->listStructures($siteRef, StarterStructureRoles::SITE_REDIRECT_REF) === [];
    }

    /**
     * @return array{site_ref: string, scope_ref: string, schema_id: string, home_record_id: ?string}
     *
     * @throws StorageRejected
     */
    public function create(string $siteRef, string $dataScope, string $siteName, string $locale, string $actor, ?string $correlationId = null): array
    {
        $this->starterRoles->install($actor, $correlationId);
        $present = $this->database->table(self::STRUCTURES)
            ->where('scope_ref', $dataScope)->where('structure_id', SitePagesStorage::STRUCTURE_ID)->exists();
        $structure = $present
            ? $this->workbench->readStructure($dataScope, SitePagesStorage::STRUCTURE_ID, $actor)
            : $this->workbench->createStructure($dataScope, $this->pages->descriptor($locale), $actor, $correlationId);
        $schemaId = $structure->schema->schemaId;
        $bound = false;
        foreach ($this->roles->listStructures($siteRef, StarterSite::ROLE_REF) as $binding) {
            $bound = $bound || $binding->schemaId === $schemaId;
        }
        if (!$bound) {
            $this->roles->bindStructure(StarterSite::ROLE_REF, $schemaId, $siteRef,
                array_map(static fn (array $field): array => ['key' => (string) $field['key'], 'type' => (string) $field['type']], $structure->fields),
                $actor, [StarterStructureRoles::SITE_TREE_RELATION], RoleLifecycle::Publishable->value, $correlationId);
        }
        $home = null;
        if (!$this->database->table(self::RECORDS)->where('schema_id', $schemaId)->exists()) {
            $home = $this->workbench->createRecord($dataScope, SitePagesStorage::STRUCTURE_ID, StarterSite::homeValues($siteName, $locale), $actor)->recordId;
        }
        foreach ($this->redirects->create($actor, $siteRef, $locale, $correlationId)['sites'] as $receipt) {
            if ($receipt['state'] === SiteRedirectsStorage::REFUSED) {
                throw new StorageRejected(is_string($receipt['reason_code'] ?? null) ? $receipt['reason_code'] : 'site_redirects_storage_refused');
            }
        }

        return ['site_ref' => $siteRef, 'scope_ref' => $dataScope, 'schema_id' => $schemaId, 'home_record_id' => $home];
    }
}
