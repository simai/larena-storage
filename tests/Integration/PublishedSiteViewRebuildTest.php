<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Runtime\QueueControlService;
use Larena\Queue\Storage\DatabaseQueueStore;
use Larena\Storage\Console\Commands\StorageViewCheckCommand;
use Larena\Storage\Console\Commands\StorageViewRebuildCommand;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewRebuilder;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewRebuildJob;
use Larena\Storage\View\StorageViewRebuildQueue;
use Larena\Storage\View\StorageViewSync;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/published-site-view-fixture.php';
require_once __DIR__ . '/../Support/storage-view-queue-fixture.php';

// The site витрина rebuilt from the publications on SQLite: slot by slot in chunks,
// resumable after a checkpoint, a timeout and retry, or a cancel, ending equal to a
// full rebuild; the storage.view.rebuild job with `view: site` (and an old payload
// without `view` still an admin job); the commands (`--site`, `--missing` covering
// site slots, `check --site` and its repair); and parity with the read contracts
// after a random run of publications and translations.

/** Calls the checkpoint hook after each committed rebuild chunk. */
final class PublishedSiteViewChunkHook
{
    /** @var (Closure(): void)|null */
    public ?Closure $onChunk = null;

    /** @param array<string, string> $tags */
    public function timing(string $id, float $ms, array $tags = []): void
    {
        if ($id === 'storage.view.rebuild' && $this->onChunk !== null) {
            ($this->onChunk)();
        }
    }

    /** @param array<string, string> $tags */
    public function count(string $id, int $n = 1, array $tags = []): void
    {
    }
}

$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$opened = storageViewQueueOpen();
$connection = $opened['connection'];

try {
    storageViewQueueInstall($connection);
    (require __DIR__ . '/../../database/migrations/platform/2026_09_28_000001_create_larena_storage_localized_values_table.php')->up();
    (require __DIR__ . '/../../database/migrations/platform/2026_09_29_000001_create_larena_storage_publication_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/platform/2026_10_20_000002_create_larena_storage_published_key_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/platform/2026_10_22_000001_create_larena_storage_site_view_tables.php')->up();
    $ddl = new SqliteStorageViewDdl($connection);
    $runtime = publishedSiteViewRuntime($connection, $ddl, $actor, $scope);
    $workbench = $runtime['workbench'];
    $publication = $runtime['publication'];
    $site = $runtime['site'];
    $views = $runtime['views'];
    $registry = $site->registry();
    $hook = new PublishedSiteViewChunkHook();
    $metrics = new StorageMetrics(static fn (): object => $hook);
    $clock = new StorageViewTestClock(new DateTimeImmutable('2026-10-21T00:00:00Z'));
    $jobs = new JobTypeRegistry();
    $jobs->register(StorageViewRebuildJob::descriptor(), new StorageViewRebuildJob($views, $metrics, static fn (): PublishedSiteViewIndex => $site));
    $store = new DatabaseQueueStore($connection);
    $dispatcher = new DurableQueueDispatcher($jobs, $store, $clock);
    $worker = new DurableQueueWorker($jobs, $store, $clock);
    $control = new QueueControlService($store, $clock);
    $rebuilds = new StorageViewRebuildQueue($views, $jobs, $dispatcher, $clock);

    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => [
        publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], true),
        publishedSiteViewField('slug', 20, 'string', 'public', ['max_length' => 120]),
        publishedSiteViewField('tags', 30, 'choices', 'public', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]),
        publishedSiteViewField('secret', 40, 'string', 'admin', ['max_length' => 200]),
    ]], $actor);
    $schemaId = $structure->schema->schemaId;
    $table = StorageViewNaming::siteTable($schemaId);
    $records = [];
    for ($index = 0; $index < 7; $index++) {
        $records[$index] = $workbench->createRecord($scope, 'workbench.pages', ['title' => 'Page ' . $index, 'slug' => 'page-' . $index, 'tags' => $index % 2 === 0 ? ['a'] : ['a', 'b'], 'secret' => 'SENTINEL-' . $index], $actor);
        // Published around the site index, as before the site витрина existed.
        $runtime['plain']->publish($schemaId, $records[$index]->recordId, 'site:main', 'en', 1, $actor);
        if ($index < 3) {
            $runtime['plain']->publish($schemaId, $records[$index]->recordId, 'site:main', 'kk', 1, $actor);
        }
    }
    // As if the storage was published before the site витрина: no registration, no tables.
    $forget = static function () use ($registry, $ddl, $schemaId, $table): void {
        $registry->delete($schemaId);
        $ddl->dropTables($table);
    };
    $forget();
    $rebuilder = new PublishedSiteViewRebuilder($site, $metrics);
    storageViewExpect($rebuilder->missing() === [$schemaId], 'a published storage without a site витрина is not missing');
    $full = $rebuilder->rebuild($schemaId, 1000, true);
    storageViewExpect($full['records'] === 10 && $full['slots'] === 2 && !$full['resumed'], 'the full rebuild did not walk every slot: ' . json_encode($full));
    publishedSiteViewParity($site, $schemaId, 'full rebuild');
    storageViewExpect($rebuilder->missing() === [], 'a ready site витрина is still missing');
    $reference = publishedSiteViewSnapshot($site, $schemaId);
    storageViewExpect(count($reference['rows']) === 10 && count($reference['multi']) === 14, 'the reference build holds the wrong rows');
    storageViewExpect(!str_contains(publishedSiteViewDump($connection, $schemaId), 'SENTINEL'), 'the rebuild wrote an admin field');

    // The checkpoint between chunks stops the build after a committed chunk; the next run resumes it.
    $forget();
    try {
        $rebuilder->rebuild($schemaId, 2, false, null, static function (): void {
            throw new RuntimeException('checkpoint stop');
        });
        throw new LogicException('the checkpoint did not stop the build');
    } catch (RuntimeException $exception) {
        storageViewExpect($exception->getMessage() === 'checkpoint stop', 'the build stopped for another reason: ' . $exception->getMessage());
    }
    $building = $registry->slot($schemaId, 'site:main', 'en', null, true);
    storageViewExpect($building !== null && $building->state === 'building' && $building->buildCursor !== null && $connection->table($table)->count() === 2, 'a stopped build did not keep its position');
    $resumed = $rebuilder->rebuild($schemaId, 2);
    storageViewExpect($resumed['resumed'] && $resumed['records'] === 8, 'the build did not resume: ' . json_encode($resumed));
    storageViewExpect(publishedSiteViewSnapshot($site, $schemaId) === $reference, 'a resumed build differs from a full rebuild');
    publishedSiteViewParity($site, $schemaId, 'resumed');

    // Writers keep a slot that is being built current: a publication behind the walk's position counts.
    $forget();
    $calls = 0;
    try {
        $rebuilder->rebuild($schemaId, 2, false, null, static function () use (&$calls, $publication, $schemaId, $records, $actor): void {
            if (++$calls === 1) {
                $publication->unpublish($schemaId, $records[0]->recordId, 'site:main', 'en', $actor);
                throw new RuntimeException('checkpoint stop');
            }
        });
    } catch (RuntimeException) {
    }
    $rebuilder->rebuild($schemaId, 2);
    publishedSiteViewParity($site, $schemaId, 'written while building');
    $publication->publish($schemaId, $records[0]->recordId, 'site:main', 'en', 1, $actor);
    storageViewExpect(count(publishedSiteViewEntries($site, $schemaId, 'site:main', 'en')) === 7, 'the republished record has no row');
    publishedSiteViewParity($site, $schemaId, 'republished');
    // The reference moves with the record's projection version.
    $reference = publishedSiteViewSnapshot($site, $schemaId);

    // The queue job (`view: site`) times out after its first chunk, is retried, and resumes.
    $forget();
    $first = $rebuilds->enqueueSite($schemaId, 2);
    $again = $rebuilds->enqueueSite($schemaId, 2);
    storageViewExpect(!$first->duplicate && $again->duplicate && $again->jobId === $first->jobId, 'asking twice for an unchanged site витрина did not give one job');
    $hook->onChunk = static function () use ($clock, $hook): void {
        $hook->onChunk = null;
        $clock->advance(StorageViewRebuildJob::TIMEOUT_SECONDS + 1);
    };
    $run = $worker->runNext('site-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Retrying, 'the timed out site job was not set to retry: ' . ($run?->status->value ?? 'none'));
    storageViewExpect($registry->slot($schemaId, 'site:main', 'en', null, true)?->state === 'building', 'the timed out job left its slot ready or pending');
    $clock->advance(StorageViewRebuildJob::RETRY_DELAY_SECONDS + 1);
    $run = $worker->runNext('site-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && $run->attempts === 2 && ($run->result['resumed'] ?? null) === true, 'the retried site job did not resume: ' . json_encode($run?->result));
    storageViewExpect(publishedSiteViewSnapshot($site, $schemaId) === $reference, 'the retried site job differs from a full rebuild');

    // A cancel stops the job after its chunk; a new job resumes the build.
    $forget();
    $clock->advance(60);
    $cancelled = $rebuilds->enqueueSite($schemaId, 2);
    $hook->onChunk = static function () use ($control, $cancelled, $hook): void {
        $hook->onChunk = null;
        $control->cancel($cancelled->jobId);
    };
    $run = $worker->runNext('site-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Cancelled, 'the cancelled site job did not stop');
    $next = $rebuilds->enqueueSite($schemaId, 2);
    storageViewExpect(!$next->duplicate && $next->jobId !== $cancelled->jobId, 'a site build that moved on did not get a new job');
    $run = $worker->runNext('site-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && ($run->result['resumed'] ?? null) === true, 'the next job did not resume the cancelled build');
    storageViewExpect(publishedSiteViewSnapshot($site, $schemaId) === $reference, 'the build resumed after a cancel differs from a full rebuild');

    // A payload written before `view` existed is the admin job, as it was; bad views fail without a retry.
    $views->registry()->delete($schemaId);
    $ddl->dropTables(StorageViewNaming::table($schemaId));
    $dispatcher->dispatch(new DispatchRequest(StorageViewRebuildJob::JOB_TYPE, ['schema_id' => $schemaId, 'chunk' => 3], 'site-test-old-payload', 'site-test-old-payload'));
    $run = $worker->runNext('site-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && ($run->result['records'] ?? null) === 7, 'the old payload did not build the admin витрина');
    storageViewParity($connection, $views, $schemaId, 'old payload');
    foreach ([['view' => 'public'], ['view' => 1], ['view' => 'site', 'restart' => true]] as $index => $extra) {
        $dispatcher->dispatch(new DispatchRequest(StorageViewRebuildJob::JOB_TYPE, ['schema_id' => $schemaId, 'chunk' => 2] + $extra, 'site-test-bad-' . $index, 'site-test-bad-' . $index));
        $run = $worker->runNext('site-worker');
        storageViewExpect($run !== null && $run->status === JobStatus::Failed && $run->failureReason === 'storage_view_rebuild_payload_invalid', 'bad payload ' . $index . ' did not fail');
    }
    $dispatcher->dispatch(new DispatchRequest(StorageViewRebuildJob::JOB_TYPE, ['schema_id' => 'workbench.unknown', 'chunk' => 2, 'view' => 'site'], 'site-test-unknown', 'site-test-unknown'));
    $run = $worker->runNext('site-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Failed && $run->failureReason === 'storage_view_schema_unknown', 'an unknown storage did not fail');
    $plainJob = new StorageViewRebuildJob($views, $metrics);
    $jobsWithout = new JobTypeRegistry();
    $jobsWithout->register(StorageViewRebuildJob::descriptor(), $plainJob);
    $storeWithout = new DatabaseQueueStore($connection);
    (new DurableQueueDispatcher($jobsWithout, $storeWithout, $clock))->dispatch(new DispatchRequest(StorageViewRebuildJob::JOB_TYPE, ['schema_id' => $schemaId, 'view' => 'site'], 'site-test-no-index', 'site-test-no-index'));
    $run = (new DurableQueueWorker($jobsWithout, $storeWithout, $clock))->runNext('site-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Failed && $run->failureReason === 'storage_view_unavailable', 'a site job without the site index did not fail closed');

    // The commands: --site builds inline, --missing --queue enqueues the site витрины too, check --site reports and repairs.
    $container = new class extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance(StorageViewSync::class, $views);
    $container->instance(StorageViewRebuildQueue::class, $rebuilds);
    $container->instance(PublishedSiteViewIndex::class, $site);
    $run = static function (Command $command, array $arguments) use ($container): array {
        $command->setLaravel($container);
        $output = new BufferedOutput();
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);
        $status = $command->run($input, $output);

        return [$status, $output->fetch()];
    };
    $forget();
    [$status, $printed] = $run(new StorageViewRebuildCommand(), ['--site' => true, '--missing' => true, '--chunk' => '3']);
    storageViewExpect($status === 0 && str_contains($printed, 'site ' . $schemaId . ': ready, 2 slots, 10 records'), 'the --site --missing command did not build: ' . $printed);
    storageViewExpect(publishedSiteViewSnapshot($site, $schemaId) === $reference, 'the command build differs from a full rebuild');
    [$status, $printed] = $run(new StorageViewRebuildCommand(), ['--site' => true, '--missing' => true]);
    storageViewExpect($status === 0 && trim($printed) === 'Nothing to build.', 'a second --missing run built something: ' . $printed);
    [$status, $printed] = $run(new StorageViewRebuildCommand(), ['--site' => true, '--all' => true, '--search' => true]);
    storageViewExpect($status === 2, '--site went with --search');
    // A silenced scope is missing: the scheduled --missing --queue run enqueues it beside the admin builds.
    $runtime['plain']->unpublish($schemaId, $records[6]->recordId, 'site:main', 'en', $actor);
    $clock->advance(60);
    [$status, $printed] = $run(new StorageViewRebuildCommand(), ['--missing' => true, '--queue' => true]);
    storageViewExpect($status === 0 && str_contains($printed, 'site ' . $schemaId . ': queued, job '), 'the --missing --queue run did not enqueue the site витрина: ' . $printed);
    $run2 = $worker->runNext('site-worker');
    storageViewExpect($run2 !== null && $run2->status === JobStatus::Completed, 'the enqueued site build did not run');
    publishedSiteViewParity($site, $schemaId, 'queued by --missing');

    [$status, $printed] = $run(new StorageViewCheckCommand(), ['--site' => true]);
    storageViewExpect($status === 0 && str_contains($printed, 'site ' . $schemaId . ': ok, 2 slots, 9 records'), 'check --site did not report ok: ' . $printed);
    // The checker sees a changed entry, a missing row and an extra one, and the repair rebuilds them.
    $victim = $records[1]->recordId;
    $connection->table($table)->where('record_id', $victim)->update(['entry_json' => '{"record_id":"' . $victim . '"}']);
    $report = $runtime['checker']->check($schemaId);
    storageViewExpect($report['stale'] === 2 && in_array('rows_stale', $report['problems'], true), 'a changed entry was not stale: ' . json_encode($report));
    $connection->table($table)->where('record_id', $records[2]->recordId)->delete();
    $slot = $registry->slot($schemaId, 'site:main', 'en', null, true);
    $connection->table($table)->insert(['slot_id' => $slot->id, 'record_id' => $records[6]->recordId, 'revision' => 1, 'schema_version' => 1, 'projection_version' => 1,
        'entry_hash' => str_repeat('0', 64), 'entry_json' => '{}', 'title' => null, 'updated_at' => '2026-10-21 00:00:00']);
    $connection->table($table . '_s')->where('record_id', $records[3]->recordId)->update(['search_text' => ' other']);
    $report = $runtime['checker']->check($schemaId);
    storageViewExpect($report['missing'] === 2 && $report['extra'] === 1 && $report['stale'] === 3, 'the checker missed a difference: ' . json_encode($report));
    [$status, $printed] = $run(new StorageViewCheckCommand(), ['--site' => true]);
    storageViewExpect($status === 1 && str_contains($printed, 'rows_missing') && str_contains($printed, 'rows_extra') && str_contains($printed, 'rows_stale'), 'check --site did not report the differences: ' . $printed);
    [$status, $printed] = $run(new StorageViewCheckCommand(), ['--site' => true, '--repair' => true]);
    storageViewExpect($status === 0 && str_contains($printed, '-> repaired'), 'check --site --repair did not repair: ' . $printed);
    publishedSiteViewParity($site, $schemaId, 'repaired');

    // A random run of publications, translations and new revisions through the indexes keeps parity.
    mt_srand(20261021);
    $locales = ['en', 'kk', 'ru'];
    $scopes = ['site:main', 'site:other'];
    $current = [];
    foreach ($records as $index => $record) {
        $current[$index] = $record;
    }
    for ($step = 0; $step < 160; $step++) {
        $index = mt_rand(0, 6);
        $record = $current[$index];
        $scopeRef = $scopes[mt_rand(0, 1)];
        $locale = $locales[mt_rand(0, 2)];
        $revision = mt_rand(1, $record->revision);
        try {
            match (mt_rand(0, 7)) {
                0, 1 => $publication->publish($schemaId, $record->recordId, $scopeRef, $locale, $revision, $actor),
                2 => $publication->unpublish($schemaId, $record->recordId, $scopeRef, $locale, $actor),
                3 => $publication->schedule($schemaId, $record->recordId, $scopeRef, $locale, $revision, '2020-01-01 00:00:00', $actor),
                4 => $publication->archive($schemaId, $record->recordId, $scopeRef, $locale, $actor),
                5 => $runtime['localized']->write($schemaId, $record->recordId, $revision, $locale, ['title' => 'T' . $step . ' ' . $locale], ['title', 'secret'], $actor),
                6 => $current[$index] = $workbench->updateRecord($scope, 'workbench.pages', $record->recordId, $record->revision, ['title' => 'Page ' . $index . ' r' . $step, 'slug' => 'page-' . $index . '-' . $step, 'tags' => $step % 2 === 0 ? ['b'] : ['a'], 'secret' => 'SENTINEL-' . $step], $actor),
                default => $publication->sweep('system:scheduler', '2026-10-21 12:00:00'),
            };
        } catch (Larena\Storage\Exceptions\PublicationRejected|Larena\Storage\Exceptions\LocalizedValueRejected) {
            // An illegal move or a translation already written: nothing happened.
        }
    }
    $report = $runtime['checker']->check($schemaId);
    storageViewExpect($report['problems'] === [] && $report['records'] > 0, 'the random run broke parity: ' . json_encode($report));
    publishedSiteViewParity($site, $schemaId, 'random run');
    storageViewExpect(!str_contains(publishedSiteViewDump($connection, $schemaId), 'SENTINEL'), 'the random run wrote an admin field');
    $kept = publishedSiteViewSnapshot($site, $schemaId);
    $rebuilder->rebuild($schemaId, 3, true);
    storageViewExpect(publishedSiteViewSnapshot($site, $schemaId) === $kept, 'the rows kept by publication differ from a rebuild');
    echo 'Random run: ' . $report['records'] . ' published entries in ' . $report['slots'] . " slots kept equal to the read contracts.\n";
} finally {
    Facade::clearResolvedInstances();
    @unlink($opened['path']);
}

echo "PublishedSiteViewRebuildTest passed.\n";
