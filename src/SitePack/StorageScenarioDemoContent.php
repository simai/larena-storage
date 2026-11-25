<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Illuminate\Database\Connection;
use Larena\Core\Contracts\SiteScenarioDemoContent;
use Larena\Core\Scenario\SiteScenario;
use Larena\Core\SitePack\SitePackRejected;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\FirstRun\StarterSite;

/**
 * A scenario's demo pages (core's SiteScenarioApplier): its SitePack of the site pages imported through the ordinary
 * SitePack import (receive, plan as a dry run, apply with the plan's digest), as the person applying the scenario, in
 * the scope where the site pages are a storage, with `addMissing` (StorageSitePackImportOptions): a merge that only
 * adds. A page the site has already (the same record id, or a page at the same address) stays as the person has it and
 * is counted skipped; the pages arrive as drafts, nothing is published, overwritten or deleted (the untouched starter
 * Home page, when it is the site's only page, is replaced by the scenario's). The same pack applied again adds
 * nothing, and a second scenario adds its pages beside the first one's. The import run audits itself
 * (`storage.sitepack.import_*`, with `add_missing` and `records_skipped`).
 *
 * The strict restore of a whole site stays as it was: a person's import (the admin page, the command) never sets
 * `addMissing`, so a used site still refuses it.
 */
final readonly class StorageScenarioDemoContent implements SiteScenarioDemoContent
{
    public function __construct(private Connection $database, private StorageSitePack $sitePack)
    {
    }

    public function available(): bool
    {
        return $this->database->getSchemaBuilder()->hasTable(StorageSitePackRuns::TABLE);
    }

    public function import(SiteScenario $scenario, string $sitePackPath, string $actor): string
    {
        $scope = $this->pagesScope();
        if ($scope === null) {
            return self::FAILED;
        }
        $correlationId = 'site-scenario:' . $scenario->id;
        try {
            $run = $this->sitePack->receive($scope, $sitePackPath, $actor, $correlationId);
            $this->sitePack->plan($run->runRef, new StorageSitePackImportOptions(addMissing: true), $actor, $correlationId, false);
            $planned = $this->sitePack->run($run->runRef, $actor);
            if ($planned->plan === null || $planned->planDigest === null || !$planned->plan->ok()) {
                $this->sitePack->cancel($run->runRef, $actor, $correlationId);

                return self::FAILED;
            }
            $this->sitePack->apply($run->runRef, $planned->planDigest, $actor, $correlationId, false);
            $done = $this->sitePack->run($run->runRef, $actor);
        } catch (StorageRejected | SitePackRejected) {
            // A broken pack, or one refused at a step: reported failed, the scenario's other changes stay.
            return self::FAILED;
        }

        return $done->state === StorageSitePackRun::STATE_DONE ? self::IMPORTED : self::FAILED;
    }

    /** The scope whose storage adopted the site pages, or null when the site pages are not a storage. */
    private function pagesScope(): ?string
    {
        $schema = $this->database->getSchemaBuilder();
        if (!$schema->hasTable('larena_storage_workbench_structures') || !$schema->hasColumn('larena_storage_workbench_structures', 'adopted_schema_id')) {
            return null;
        }
        $scope = $this->database->table('larena_storage_workbench_structures')->where('adopted_schema_id', StarterSite::SCHEMA_ID)->value('scope_ref');

        return is_string($scope) && $scope !== '' ? $scope : null;
    }
}
