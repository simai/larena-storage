<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SitePack\DatabaseStorageSitePack;

/**
 * Exports one storage structure as a SitePack archive, as the person named by --actor (who needs
 * `storage.sitepack.export` and, in the scope, the rights to read the structure and list its records).
 *
 *   larena:storage:sitepack:export workbench.items --scope=scope:site.main --actor=actor:admin
 *       starts the export as a storage.sitepack.export queue job and prints the run
 *   larena:storage:sitepack:export workbench.items --scope=… --actor=… --sync --output=/tmp/items.sitepack.zip
 *       runs it here and copies the archive to --output (a new file, 0600)
 *   … --include-encrypted-plaintext
 *       the pack carries the encrypted fields' values in plaintext (needs storage.workbench.record.decrypt; audited,
 *       the manifest says contains_decrypted_values); an import seals them again with the target's key
 */
final class StorageSitePackExportCommand extends Command
{
    protected $signature = 'larena:storage:sitepack:export
        {structure : The structure id (workbench.…)}
        {--scope= : The scope the structure belongs to}
        {--actor= : The person the export runs as}
        {--sync : Run the export in this process instead of the queue}
        {--output= : With --sync, copy the archive to this new file}
        {--include-encrypted-plaintext : Carry the encrypted fields in plaintext (decrypt right, audited)}';

    protected $description = 'Export a Larena Storage structure (structure, records, translations, publication states, relations, files) as a SitePack archive.';

    public function handle(StorageSitePack $sitePack): int
    {
        $structure = (string) $this->argument('structure');
        $scope = $this->option('scope');
        $actor = $this->option('actor');
        $sync = (bool) $this->option('sync');
        $output = $this->option('output');
        if (!is_string($scope) || $scope === '' || !is_string($actor) || $actor === '') {
            $this->error('Name the scope (--scope) and the person the export runs as (--actor).');

            return self::INVALID;
        }
        if (is_string($output) && $output !== '' && (!$sync || file_exists($output) || !is_dir(dirname($output)))) {
            $this->error('--output goes with --sync and names a new file in an existing directory.');

            return self::INVALID;
        }
        try {
            if ((bool) $this->option('include-encrypted-plaintext')) {
                if (!$sitePack instanceof DatabaseStorageSitePack) {
                    $this->error('This SitePack binding cannot export encrypted values in plaintext.');

                    return self::FAILURE;
                }
                $run = $sitePack->startExport($scope, $structure, $actor, null, !$sync, true);
            } else {
                $run = $sitePack->startExport($scope, $structure, $actor, null, !$sync);
            }
            if (!$sync) {
                $this->line((string) json_encode(['run_ref' => $run->runRef, 'state' => $run->state, 'job_id' => $run->jobId], JSON_UNESCAPED_SLASHES));
                if ($run->jobId === null) {
                    $this->warn('No queue took the export; run it with --sync.');
                }

                return self::SUCCESS;
            }
            $run = $sitePack->run($run->runRef, $actor);
            $archive = $sitePack->archive($run->runRef, $actor);
            if (is_string($output) && $output !== '') {
                if (!copy($archive->path, $output)) {
                    $this->error('The archive could not be copied to ' . $output . '.');

                    return self::FAILURE;
                }
                @chmod($output, 0600);
            }
            $this->line((string) json_encode([
                'run_ref' => $run->runRef,
                'state' => $run->state,
                'package_id' => $archive->packageId,
                'archive_sha256' => $archive->sha256,
                'archive_bytes' => $archive->sizeBytes,
                'counts' => $run->counts,
                'output' => is_string($output) && $output !== '' ? $output : null,
            ], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (StorageRejected $exception) {
            $this->error($exception->reasonCode);

            return self::FAILURE;
        }
    }
}
