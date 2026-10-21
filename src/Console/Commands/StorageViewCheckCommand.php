<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\Site\PublishedSiteViewChecker;
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewRebuilder;
use Larena\Storage\View\StorageViewChecker;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRebuildQueue;
use Larena\Storage\View\StorageViewSync;
use Throwable;

/**
 * Checks that each storage's витрина agrees with its current versions (ready
 * for the current schema version, the table's shape, one row per record at its
 * current revision and hash), and with --repair rebuilds the ones that do not:
 * inline, or as a `storage.view.rebuild` queue job with --queue. A build under
 * way is resumed, not restarted.
 *
 *   larena:storage:view:check                        every storage
 *   larena:storage:view:check workbench.scope.x.y    one storage
 *   larena:storage:view:check --repair [--queue]     and repair what differs
 *   larena:storage:view:check --site [schema]        the site витрины: each ready slot holds
 *                                                    exactly the entries the read contracts return
 */
final class StorageViewCheckCommand extends Command
{
    protected $signature = 'larena:storage:view:check
        {schema? : The storage schema id to check}
        {--repair : Rebuild every витрина that does not agree with the versions}
        {--queue : With --repair, enqueue a storage.view.rebuild job instead of rebuilding inline}
        {--chunk=500 : Records per transaction for a repair}
        {--site : Check the site витрины (the published public lists) against the read contracts}';

    protected $description = 'Check Larena Storage витрины against the record versions; --repair rebuilds what differs.';

    public function handle(StorageViewSync $views): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => StorageViewRebuilder::MAX_CHUNK]]);
        if ($chunk === false) {
            $this->error('The chunk must be an integer from 1 to ' . StorageViewRebuilder::MAX_CHUNK . '.');

            return self::INVALID;
        }
        $repair = (bool) $this->option('repair');
        $queued = (bool) $this->option('queue');
        if ($queued && !$repair) {
            $this->error('--queue goes with --repair.');

            return self::INVALID;
        }
        if ((bool) $this->option('site')) {
            return $this->site($repair, $queued, $chunk);
        }
        if (!$views->enabled()) {
            $this->error('The витрина is not available on this connection (unsupported driver or the registry is not migrated).');

            return self::FAILURE;
        }
        $checker = new StorageViewChecker($views);
        $schema = $this->argument('schema');
        $targets = is_string($schema) && $schema !== '' ? [$schema] : $checker->targets();

        $queue = null;
        if ($queued) {
            try {
                $queue = $this->laravel->make(StorageViewRebuildQueue::class);
            } catch (Throwable $exception) {
                $this->error('The queue is not available: ' . $exception->getMessage());

                return self::FAILURE;
            }
        }
        $rebuilder = new StorageViewRebuilder(
            $views,
            $this->laravel->bound(StorageMetrics::class) ? $this->laravel->make(StorageMetrics::class) : new StorageMetrics(),
        );

        $unhealthy = 0;
        foreach ($targets as $target) {
            try {
                $report = $checker->check($target);
                if ($report['problems'] === []) {
                    $this->line(sprintf('%s: ok, %d records', $target, $report['records']));

                    continue;
                }
                $summary = sprintf(
                    '%s: %s (records %d, rows %d, missing %d, extra %d, stale %d)',
                    $target,
                    implode(', ', $report['problems']),
                    $report['records'],
                    $report['rows'],
                    $report['missing'],
                    $report['extra'],
                    $report['stale'],
                );
                if (!$repair || in_array('schema_unknown', $report['problems'], true)) {
                    // A витрина whose storage is gone is reported, never dropped here.
                    $this->warn($summary);
                    $unhealthy++;

                    continue;
                }
                if ($queue !== null) {
                    $result = $queue->enqueue($target, $chunk);
                    $this->line($summary . ' -> queued, job ' . $result->jobId . ($result->duplicate ? ' (already queued)' : ''));

                    continue;
                }
                $rebuilder->rebuild($target, $chunk);
                $after = $checker->check($target);
                if ($after['problems'] !== []) {
                    $this->warn($summary . ' -> still ' . implode(', ', $after['problems']));
                    $unhealthy++;

                    continue;
                }
                $this->line($summary . ' -> repaired, ' . $after['records'] . ' records');
            } catch (Throwable $exception) {
                $unhealthy++;
                $this->error($target . ': ' . $exception->getMessage());
            }
        }
        if ($targets === []) {
            $this->line('Nothing to check.');
        }

        return $unhealthy === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function site(bool $repair, bool $queued, int $chunk): int
    {
        $site = null;
        if ($this->laravel->bound(PublishedSiteViewIndex::class)) {
            try {
                $site = $this->laravel->make(PublishedSiteViewIndex::class);
            } catch (Throwable) {
                $site = null;
            }
        }
        if (!$site instanceof PublishedSiteViewIndex || !$site->enabled()) {
            $this->error('The site витрина is not available on this connection (unsupported driver or the registry is not migrated).');

            return self::FAILURE;
        }
        $queue = null;
        if ($queued) {
            try {
                $queue = $this->laravel->make(StorageViewRebuildQueue::class);
            } catch (Throwable $exception) {
                $this->error('The queue is not available: ' . $exception->getMessage());

                return self::FAILURE;
            }
        }
        $checker = new PublishedSiteViewChecker($site);
        $rebuilder = new PublishedSiteViewRebuilder(
            $site,
            $this->laravel->bound(StorageMetrics::class) ? $this->laravel->make(StorageMetrics::class) : new StorageMetrics(),
        );
        $schema = $this->argument('schema');
        $targets = is_string($schema) && $schema !== '' ? [$schema] : $checker->targets();
        $unhealthy = 0;
        foreach ($targets as $target) {
            try {
                $report = $checker->check($target);
                if ($report['problems'] === []) {
                    $this->line(sprintf('site %s: ok, %d slots, %d records', $target, $report['slots'], $report['records']));

                    continue;
                }
                $summary = sprintf(
                    'site %s: %s (records %d, rows %d, missing %d, extra %d, stale %d)',
                    $target,
                    implode(', ', $report['problems']),
                    $report['records'],
                    $report['rows'],
                    $report['missing'],
                    $report['extra'],
                    $report['stale'],
                );
                if (!$repair || in_array('schema_unknown', $report['problems'], true)) {
                    $this->warn($summary);
                    $unhealthy++;

                    continue;
                }
                if ($queue !== null) {
                    $result = $queue->enqueueSite($target, $chunk);
                    $this->line($summary . ' -> queued, job ' . $result->jobId . ($result->duplicate ? ' (already queued)' : ''));

                    continue;
                }
                // Rows that disagree are rebuilt from scratch; a silenced slot only needs filling.
                $stale = array_intersect($report['problems'], ['rows_missing', 'rows_extra', 'rows_stale', 'shape_mismatch']) !== [];
                $rebuilder->rebuild($target, min($chunk, PublishedSiteViewRebuilder::MAX_CHUNK), $stale);
                $after = $checker->check($target);
                if ($after['problems'] !== []) {
                    $this->warn($summary . ' -> still ' . implode(', ', $after['problems']));
                    $unhealthy++;

                    continue;
                }
                $this->line($summary . ' -> repaired, ' . $after['records'] . ' records');
            } catch (Throwable $exception) {
                $unhealthy++;
                $this->error('site ' . $target . ': ' . $exception->getMessage());
            }
        }
        if ($targets === []) {
            $this->line('Nothing to check.');
        }

        return $unhealthy === 0 ? self::SUCCESS : self::FAILURE;
    }
}
