<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRegistration;
use Larena\Storage\View\StorageViewSync;
use Throwable;

/**
 * Builds the витрина of existing storages from their versions, synchronously and
 * in chunks. A build that stops resumes where it was on the next run.
 *
 *   larena:storage:view:rebuild workbench.scope.x.y   one storage (from scratch, or resumed)
 *   larena:storage:view:rebuild --missing             every structure whose витрина is not ready
 *   larena:storage:view:rebuild --all                 every structure
 */
final class StorageViewRebuildCommand extends Command
{
    protected $signature = 'larena:storage:view:rebuild
        {schema? : The storage schema id to build}
        {--all : Every workbench structure}
        {--missing : Every workbench structure whose витрина is missing or not ready}
        {--chunk=1000 : Records per transaction}';

    protected $description = 'Build Larena Storage витрины (the typed read tables) from the record versions; resumable.';

    public function handle(StorageViewSync $views): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => StorageViewRebuilder::MAX_CHUNK]]);
        if ($chunk === false) {
            $this->error('The chunk must be an integer from 1 to ' . StorageViewRebuilder::MAX_CHUNK . '.');

            return self::INVALID;
        }
        if (!$views->enabled()) {
            $this->error('The витрина is not available on this connection (unsupported driver or the registry is not migrated).');

            return self::FAILURE;
        }
        $schema = $this->argument('schema');
        $all = (bool) $this->option('all');
        $missing = (bool) $this->option('missing');
        if ((is_string($schema) && $schema !== '' ? 1 : 0) + ($all ? 1 : 0) + ($missing ? 1 : 0) !== 1) {
            $this->error('Name one schema, or pass --all or --missing.');

            return self::INVALID;
        }

        $targets = [];
        if (is_string($schema) && $schema !== '') {
            $targets[] = $schema;
        } else {
            $connection = $views->connection();
            $ids = $connection->table('larena_storage_workbench_structures')->orderBy('storage_schema_id')->pluck('storage_schema_id')->all();
            foreach ($ids as $id) {
                if (!is_string($id)) {
                    continue;
                }
                $registration = $missing ? $views->registry()->find($id, true) : null;
                if ($missing && $registration?->state === StorageViewRegistration::STATE_READY) {
                    continue;
                }
                $targets[] = $id;
            }
        }

        $rebuilder = new StorageViewRebuilder($views);
        $failed = 0;
        foreach ($targets as $target) {
            try {
                // An explicit schema or --all builds from scratch unless a build is under way.
                $current = $views->registry()->find($target, true);
                $result = $rebuilder->rebuild(
                    $target,
                    $chunk,
                    $current !== null && $current->state === StorageViewRegistration::STATE_READY,
                    fn (string $line) => $this->line($line, null, 'v'),
                );
                $this->line(sprintf('%s: ready, %d records%s', $result['schema_id'], $result['records'], $result['resumed'] ? ' (resumed)' : ''));
            } catch (Throwable $exception) {
                $failed++;
                $this->error($target . ': ' . $exception->getMessage());
            }
        }
        if ($targets === []) {
            $this->line('Nothing to build.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
