<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\Site\PublishedSiteViewChecker;
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewRebuilder;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRebuildQueue;
use Larena\Storage\View\StorageViewRegistration;
use Larena\Storage\View\StorageViewSync;
use Throwable;

/**
 * Builds the витрина of existing storages from their versions, in chunks:
 * inline by default, or as one `storage.view.rebuild` queue job per storage with
 * --queue. A build that stops resumes where it was on the next run or job.
 *
 *   larena:storage:view:rebuild workbench.scope.x.y   one storage (from scratch, or resumed)
 *   larena:storage:view:rebuild --missing             every structure whose витрина is not ready, or has rows to backfill
 *   larena:storage:view:rebuild --all                 every structure
 *   larena:storage:view:rebuild --missing --queue     enqueue the builds for the queue worker
 *   larena:storage:view:rebuild --all --search        refill every search table in place (lists keep working)
 *   larena:storage:view:rebuild --site <schema>|--all|--missing   the site витрины (published public lists) only
 *
 * `--missing` covers the site витрины too: every registration or slot that is not
 * ready (a silenced scope, a changed locale chain or public fields), and every
 * published storage with a public field that has none yet.
 */
final class StorageViewRebuildCommand extends Command
{
    protected $signature = 'larena:storage:view:rebuild
        {schema? : The storage schema id to build}
        {--all : Every workbench structure}
        {--missing : Every workbench structure whose витрина is missing, not ready, or has rows to backfill}
        {--chunk=1000 : Records per transaction}
        {--queue : Enqueue a storage.view.rebuild job per storage instead of building inline}
        {--search : Refill only the search text, into an emptied search table (a fresh FULLTEXT index after heavy editing); the витрина stays ready}
        {--site : Build the site витрины (the published public lists) instead of the admin ones}';

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
        $siteOnly = (bool) $this->option('site');
        if ($siteOnly && (bool) $this->option('search')) {
            $this->error('--search refills the admin search text; it does not go with --site.');

            return self::INVALID;
        }
        if ($siteOnly) {
            $site = $this->siteIndex();
            if ($site === null) {
                $this->error('The site витрина is not available on this connection (unsupported driver or the registry is not migrated).');

                return self::FAILURE;
            }
            $status = $this->site($site, is_string($schema) && $schema !== '' ? $schema : null, $all, $chunk);
            if ($status['targets'] === 0) {
                $this->line('Nothing to build.');
            }

            return $status['failed'] === 0 ? self::SUCCESS : self::FAILURE;
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
                if ($missing && $registration?->state === StorageViewRegistration::STATE_READY && !$registration->backfillPending()) {
                    continue;
                }
                $targets[] = $id;
            }
        }

        if ((bool) $this->option('search')) {
            foreach ($targets as $target) {
                // The search text is refilled into an emptied search table; the витрина stays ready.
                $views->refreshSearch($target);
            }
        }
        // --missing heals the site витрины as well, when the application has them.
        $site = $missing ? $this->siteIndex() : null;
        if ((bool) $this->option('queue')) {
            $status = $this->enqueue($targets, $chunk, $site === null);
            $siteStatus = $site === null ? ['targets' => 0, 'failed' => 0] : $this->site($site, null, false, $chunk);
            if ($site !== null && $targets === [] && $siteStatus['targets'] === 0) {
                $this->line('Nothing to build.');
            }

            return $status === self::SUCCESS && $siteStatus['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $rebuilder = new StorageViewRebuilder(
            $views,
            $this->laravel->bound(StorageMetrics::class) ? $this->laravel->make(StorageMetrics::class) : new StorageMetrics(),
        );
        $failed = 0;
        foreach ($targets as $target) {
            try {
                // An explicit schema or --all builds from scratch unless a build or a backfill is under way.
                $current = $views->registry()->find($target, true);
                $result = $rebuilder->rebuild(
                    $target,
                    $chunk,
                    $current !== null && $current->state === StorageViewRegistration::STATE_READY && !$current->backfillPending(),
                    fn (string $line) => $this->line($line, null, 'v'),
                );
                $this->line(sprintf('%s: ready, %d records%s', $result['schema_id'], $result['records'], $result['resumed'] ? ' (resumed)' : ''));
            } catch (Throwable $exception) {
                $failed++;
                $this->error($target . ': ' . $exception->getMessage());
            }
        }
        $siteStatus = $site === null ? ['targets' => 0, 'failed' => 0] : $this->site($site, null, false, $chunk);
        if ($targets === [] && $siteStatus['targets'] === 0) {
            $this->line('Nothing to build.');
        }

        return $failed === 0 && $siteStatus['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** The site index, when the application binds it and its registry is migrated. */
    private function siteIndex(): ?PublishedSiteViewIndex
    {
        if (!$this->laravel->bound(PublishedSiteViewIndex::class)) {
            return null;
        }
        try {
            $site = $this->laravel->make(PublishedSiteViewIndex::class);
        } catch (Throwable) {
            return null;
        }

        return $site->enabled() ? $site : null;
    }

    /**
     * Builds (or with --queue enqueues) the site витрины: one storage, every published
     * one (--all), or the ones not ready (--missing, the default here).
     *
     * @return array{targets: int, failed: int}
     */
    private function site(PublishedSiteViewIndex $site, ?string $schema, bool $all, int $chunk): array
    {
        $rebuilder = new PublishedSiteViewRebuilder(
            $site,
            $this->laravel->bound(StorageMetrics::class) ? $this->laravel->make(StorageMetrics::class) : new StorageMetrics(),
        );
        if ($schema !== null) {
            $targets = [$schema];
        } elseif ($all) {
            $targets = (new PublishedSiteViewChecker($site))->targets();
        } else {
            $targets = $rebuilder->missing();
        }
        $failed = 0;
        $queue = null;
        if ((bool) $this->option('queue') && $targets !== []) {
            try {
                $queue = $this->laravel->make(StorageViewRebuildQueue::class);
            } catch (Throwable $exception) {
                $this->error('The queue is not available: ' . $exception->getMessage());

                return ['targets' => count($targets), 'failed' => count($targets)];
            }
        }
        foreach ($targets as $target) {
            try {
                if ($queue !== null) {
                    $result = $queue->enqueueSite($target, $chunk);
                    $this->line(sprintf('site %s: queued, job %s%s', $target, $result->jobId, $result->duplicate ? ' (already queued)' : ''));

                    continue;
                }
                // A named storage or --all builds every slot from scratch; --missing resumes and fills what is not ready.
                $result = $rebuilder->rebuild(
                    $target,
                    min($chunk, PublishedSiteViewRebuilder::MAX_CHUNK),
                    $schema !== null || $all,
                    fn (string $line) => $this->line($line, null, 'v'),
                );
                $this->line(sprintf('site %s: %s, %d slots, %d records%s', $result['schema_id'], $result['state'], $result['slots'], $result['records'], $result['resumed'] ? ' (resumed)' : ''));
            } catch (Throwable $exception) {
                $failed++;
                $this->error('site ' . $target . ': ' . $exception->getMessage());
            }
        }

        return ['targets' => count($targets), 'failed' => $failed];
    }

    /** @param list<string> $targets */
    private function enqueue(array $targets, int $chunk, bool $sayNothing = true): int
    {
        try {
            $queue = $this->laravel->make(StorageViewRebuildQueue::class);
        } catch (Throwable $exception) {
            $this->error('The queue is not available: ' . $exception->getMessage());

            return self::FAILURE;
        }
        $failed = 0;
        foreach ($targets as $target) {
            try {
                $result = $queue->enqueue($target, $chunk);
                $this->line(sprintf('%s: queued, job %s%s', $target, $result->jobId, $result->duplicate ? ' (already queued)' : ''));
            } catch (Throwable $exception) {
                $failed++;
                $this->error($target . ': ' . $exception->getMessage());
            }
        }
        if ($targets === [] && $sayNothing) {
            $this->line('Nothing to build.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
