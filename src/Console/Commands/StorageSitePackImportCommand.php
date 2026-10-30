<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * Imports a storage from a SitePack archive into a scope of the person's site, as the person named by --actor (who
 * needs `storage.sitepack.import` and, in the scope, the rights to create structures and records).
 *
 *   larena:storage:sitepack:import /tmp/items.sitepack.zip --scope=scope:site.main --actor=user:admin_identity:1
 *       receives and plans the import here, then queues it as the storage.sitepack.import job
 *   … --plan-only        receives and plans it, prints the plan and stops (apply it later from the admin)
 *   … --sync             plans and imports it in this process
 *   … --publication=keep keeps the pack's publication states (needs the publish rights); draft by default
 *   … --copy             gives every record a new id (and imports a pack this site already has again)
 *   … --target=workbench.items_copy  the structure id to import into
 *   … --with-domains     adds the domains the pack carries to the site (a domain another site holds refuses the plan)
 *
 * A plan with errors is printed and nothing is imported.
 */
final class StorageSitePackImportCommand extends Command
{
    protected $signature = 'larena:storage:sitepack:import
        {archive : The SitePack archive (.sitepack.zip)}
        {--scope= : The scope to import into}
        {--actor= : The person the import runs as}
        {--plan-only : Plan the import and stop}
        {--sync : Import in this process instead of the queue}
        {--publication=draft : draft (every record unpublished) or keep (the pack\'s publication states)}
        {--copy : Give every record a new id}
        {--target= : The structure id to import into}
        {--with-domains : Add the domains the pack carries to the site}';

    protected $description = 'Import a Larena Storage structure with its records, translations, relations and files from a SitePack archive.';

    public function handle(StorageSitePack $sitePack): int
    {
        $archive = (string) $this->argument('archive');
        $scope = $this->option('scope');
        $actor = $this->option('actor');
        $publication = $this->option('publication');
        $target = $this->option('target');
        if (!is_string($scope) || $scope === '' || !is_string($actor) || $actor === '') {
            $this->error('Name the scope (--scope) and the person the import runs as (--actor).');

            return self::INVALID;
        }
        if (!in_array($publication, ['draft', 'keep'], true) || ($target !== null && (!is_string($target) || $target === ''))) {
            $this->error('--publication is draft or keep; --target names a structure id (workbench.…).');

            return self::INVALID;
        }
        if (!is_file($archive)) {
            $this->error('No archive at ' . $archive . '.');

            return self::INVALID;
        }
        try {
            $options = new StorageSitePackImportOptions(
                is_string($target) ? $target : null,
                null,
                $publication,
                [],
                (bool) $this->option('copy'),
                null,
                (bool) $this->option('with-domains'),
            );
            $run = $sitePack->receive($scope, $archive, $actor);
            $run = $sitePack->plan($run->runRef, $options, $actor, null, false);
            $run = $sitePack->run($run->runRef, $actor);
            $plan = $run->plan;
            $summary = [
                'run_ref' => $run->runRef,
                'state' => $run->state,
                'phase' => $run->phase,
                'plan_digest' => $run->planDigest,
                'plan' => $plan?->toArray(),
            ];
            if ($plan === null || $run->planDigest === null || !$plan->ok()) {
                $this->line((string) json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $this->error('The plan has errors: ' . implode(', ', $plan?->errorCodes() ?? ['storage_sitepack_plan_missing']) . '. Nothing was imported.');

                return self::FAILURE;
            }
            if ((bool) $this->option('plan-only')) {
                $this->line((string) json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }
            $sync = (bool) $this->option('sync');
            $run = $sitePack->apply($run->runRef, $run->planDigest, $actor, null, !$sync);
            if ($sync) {
                $run = $sitePack->run($run->runRef, $actor);
            } elseif ($run->jobId === null) {
                $this->warn('No queue took the import; run it with --sync.');
            }
            $this->line((string) json_encode([
                'run_ref' => $run->runRef,
                'state' => $run->state,
                'phase' => $run->phase,
                'job_id' => $run->jobId,
                'structure_id' => $run->structureId,
                'plan_digest' => $run->planDigest,
                'counts' => $run->counts,
                'warnings' => $plan->warnings,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (StorageRejected $exception) {
            $this->error($exception->reasonCode);

            return self::FAILURE;
        }
    }
}
