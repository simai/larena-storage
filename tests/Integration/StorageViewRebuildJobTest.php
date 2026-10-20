<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Enums\JobStatus;
use Larena\Storage\Console\Commands\StorageViewRebuildCommand;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRebuildJob;
use Larena\Storage\View\StorageViewRebuildQueue;
use Larena\Storage\View\StorageViewSync;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';
require_once __DIR__ . '/../Support/storage-view-queue-fixture.php';

// The витрина rebuild as the storage.view.rebuild queue job, on SQLite: it builds
// in chunks and saves its position, so a build stopped after one chunk (by the
// caller's checkpoint, a timeout and retry, or a cancel) resumes where it was and
// ends equal to a full rebuild; the витрина is ready only at the end; asking twice
// for an unchanged storage gives one job; bad payloads fail without a retry; the
// command enqueues with --queue; each chunk is timed and its rows counted.

/** Hears each rebuild chunk the rebuilder reports, and can act on it. */
final class StorageViewChunkHook
{
    /** @var (Closure(): void)|null */
    public ?Closure $onChunk = null;

    public int $chunks = 0;

    public int $rows = 0;

    /** @var list<array<string, string>> */
    public array $tags = [];

    /** @param array<string, string> $tags */
    public function timing(string $id, float $ms, array $tags = []): void
    {
        if ($id === 'storage.view.rebuild') {
            $this->chunks++;
            $this->tags[] = $tags;
            if ($this->onChunk !== null) {
                ($this->onChunk)();
            }
        }
    }

    /** @param array<string, string> $tags */
    public function count(string $id, int $n = 1, array $tags = []): void
    {
        if ($id === 'storage.view.rebuild_rows') {
            $this->rows += $n;
        }
    }
}

$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$opened = storageViewQueueOpen();
$connection = $opened['connection'];

try {
    storageViewQueueInstall($connection);
    $ddl = new SqliteStorageViewDdl($connection);
    $runtime = storageViewRuntime($connection, $ddl, $actor, $scope);
    $workbench = $runtime['workbench'];
    $views = $runtime['views'];
    $hook = new StorageViewChunkHook();
    $metrics = new StorageMetrics(static fn (): object => $hook);
    $clock = new StorageViewTestClock(new DateTimeImmutable('2026-10-20T00:00:00Z'));
    $queue = storageViewQueue($connection, $clock, $views, $metrics);
    $worker = $queue['worker'];
    $rebuilds = $queue['rebuilds'];

    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'integer'),
        storageViewField('tags', 30, 'choices', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]),
    ]], $actor);
    for ($index = 0; $index < 7; $index++) {
        $workbench->createRecord($scope, 'workbench.items', ['title' => 'Item ' . $index, 'qty' => $index, 'tags' => $index % 2 === 0 ? ['a'] : ['a', 'b']], $actor);
    }
    $schemaId = $structure->schema->schemaId;
    $table = StorageViewNaming::table($schemaId);
    // As if the storage existed before the витрина: no registration, no tables.
    $forget = static function () use ($views, $ddl, $schemaId, $table): void {
        $views->registry()->delete($schemaId);
        $ddl->dropTables($table);
    };

    // The reference: a full rebuild from scratch.
    $forget();
    $rebuilder = new StorageViewRebuilder($views, $metrics);
    $full = $rebuilder->rebuild($schemaId, 1000, true);
    storageViewExpect($full['records'] === 7 && !$full['resumed'], 'the full rebuild did not walk every record');
    storageViewParity($connection, $views, $schemaId, 'full rebuild');
    $reference = storageViewSnapshot($connection, $table);

    // The caller's checkpoint runs between chunks: what it throws stops the build after a committed chunk.
    $forget();
    $calls = 0;
    try {
        $rebuilder->rebuild($schemaId, 2, false, null, static function () use (&$calls): void {
            $calls++;
            throw new RuntimeException('checkpoint stop');
        });
        throw new LogicException('the checkpoint did not stop the build');
    } catch (RuntimeException $exception) {
        storageViewExpect($exception->getMessage() === 'checkpoint stop', 'the build stopped for another reason: ' . $exception->getMessage());
    }
    $building = $views->registry()->find($schemaId, true);
    storageViewExpect($calls === 1, 'the checkpoint ran before the first chunk');
    storageViewExpect($building !== null && $building->state === 'building' && $building->buildCursor !== null, 'a stopped build did not keep its position');
    storageViewExpect($connection->table($table)->count() === 2, 'the first chunk was not committed before the checkpoint');
    $resumed = $rebuilder->rebuild($schemaId, 2);
    storageViewExpect($resumed['resumed'] && $resumed['records'] === 5, 'the build did not resume after the first chunk');
    storageViewExpect(storageViewSnapshot($connection, $table) === $reference, 'a resumed build differs from a full rebuild');

    // The queue job times out after its first chunk, is retried, and resumes.
    $forget();
    $chunksBefore = $hook->chunks;
    $rowsBefore = $hook->rows;
    $tagsBefore = count($hook->tags);
    $first = $rebuilds->enqueue($schemaId, 2);
    $again = $rebuilds->enqueue($schemaId, 2);
    storageViewExpect(!$first->duplicate && $again->duplicate && $again->jobId === $first->jobId, 'asking twice for an unchanged storage did not give one job');
    $hook->onChunk = static function () use ($clock, $hook): void {
        $hook->onChunk = null;
        $clock->advance(StorageViewRebuildJob::TIMEOUT_SECONDS + 1);
    };
    $run = $worker->runNext('storage-view-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Retrying && $run->failureReason === 'execution_timed_out', 'the timed out job was not set to retry: ' . ($run?->status->value ?? 'none'));
    $building = $views->registry()->find($schemaId, true);
    storageViewExpect($building !== null && $building->state === 'building' && $connection->table($table)->count() === 2, 'the timed out job did not stop after its first chunk');
    storageViewExpect($building->state !== 'ready', 'the витрина was ready before the build ended');
    storageViewExpect($worker->runNext('storage-view-worker') === null, 'the retry ran before its delay');
    $clock->advance(StorageViewRebuildJob::RETRY_DELAY_SECONDS + 1);
    $run = $worker->runNext('storage-view-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && $run->attempts === 2, 'the retried job did not finish: ' . ($run?->status->value ?? 'none'));
    storageViewExpect(($run->result['resumed'] ?? null) === true && ($run->result['records'] ?? null) === 5 && ($run->result['state'] ?? null) === 'ready', 'the retried job did not resume: ' . json_encode($run->result));
    storageViewParity($connection, $views, $schemaId, 'queue job retried');
    storageViewExpect(storageViewSnapshot($connection, $table) === $reference, 'the retried job differs from a full rebuild');
    // Each committed chunk is timed (code-only tags) and its rows counted: 1 + 3 chunks of rows, then the end.
    storageViewExpect($hook->rows - $rowsBefore === 7, 'the rebuild rows were not counted: ' . ($hook->rows - $rowsBefore));
    storageViewExpect($hook->chunks - $chunksBefore === 4, 'every written chunk is timed once: ' . ($hook->chunks - $chunksBefore));
    foreach (array_slice($hook->tags, $tagsBefore) as $tags) {
        storageViewExpect($tags === ['outcome' => 'ok'], 'a rebuild chunk carries more than its outcome: ' . json_encode($tags));
    }

    // A cancel stops the job after its chunk; a new job resumes the build.
    $forget();
    // A minute later the same unchanged storage is a new request.
    $clock->advance(60);
    $cancelled = $rebuilds->enqueue($schemaId, 2);
    $hook->onChunk = static function () use ($queue, $cancelled, $hook): void {
        $hook->onChunk = null;
        $queue['control']->cancel($cancelled->jobId);
    };
    $run = $worker->runNext('storage-view-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Cancelled, 'the cancelled job did not stop: ' . ($run?->status->value ?? 'none'));
    $building = $views->registry()->find($schemaId, true);
    storageViewExpect($building !== null && $building->state === 'building' && $connection->table($table)->count() === 2, 'the cancelled job did not stop after its chunk');
    $next = $rebuilds->enqueue($schemaId, 2);
    storageViewExpect(!$next->duplicate && $next->jobId !== $cancelled->jobId, 'a build that moved on did not get a new job');
    $run = $worker->runNext('storage-view-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && ($run->result['resumed'] ?? null) === true, 'the next job did not resume the cancelled build');
    storageViewExpect(storageViewSnapshot($connection, $table) === $reference, 'the build resumed after a cancel differs from a full rebuild');

    // A retry after an attempt that finished the build (it timed out on the way out) does nothing.
    $forget();
    $clock->advance(60);
    $finished = $rebuilds->enqueue($schemaId, 3);
    $seen = 0;
    $hook->onChunk = static function () use ($clock, $hook, &$seen): void {
        if (++$seen === 3) {
            $hook->onChunk = null;
            $clock->advance(StorageViewRebuildJob::TIMEOUT_SECONDS + 1);
        }
    };
    $run = $worker->runNext('storage-view-worker');
    storageViewExpect($run !== null && $run->id === $finished->jobId && $run->status === JobStatus::Retrying, 'the attempt that timed out on the way out was not retried');
    storageViewExpect($views->registry()->find($schemaId, true)?->state === 'ready', 'the finished build was not ready');
    $before = storageViewSnapshot($connection, $table);
    $clock->advance(StorageViewRebuildJob::RETRY_DELAY_SECONDS + 1);
    $run = $worker->runNext('storage-view-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && ($run->result['records'] ?? null) === 0, 'the retry of a finished build did work again: ' . json_encode($run?->result));
    storageViewExpect(storageViewSnapshot($connection, $table) === $before && $before === $reference, 'the retry of a finished build changed the витрина');

    // Bad payloads and unknown storages fail at once, without a retry.
    foreach ([
        ['payload' => ['schema_id' => $schemaId, 'chunk' => 0], 'reason' => 'storage_view_rebuild_payload_invalid'],
        ['payload' => ['schema_id' => $schemaId, 'chunk' => 2, 'restart' => true], 'reason' => 'storage_view_rebuild_payload_invalid'],
        ['payload' => ['chunk' => 2], 'reason' => 'storage_view_rebuild_payload_invalid'],
        ['payload' => ['schema_id' => 'workbench.unknown', 'chunk' => 2], 'reason' => 'storage_view_schema_unknown'],
    ] as $index => $case) {
        $queue['dispatcher']->dispatch(new DispatchRequest(StorageViewRebuildJob::JOB_TYPE, $case['payload'], 'storage-view-bad-' . $index, 'storage-view-bad-' . $index));
        $run = $worker->runNext('storage-view-worker');
        storageViewExpect($run !== null && $run->status === JobStatus::Failed && $run->failureReason === $case['reason'], 'case ' . $index . ' did not fail with ' . $case['reason']);
    }

    // The command enqueues one job per storage with --queue; the worker builds it.
    $forget();
    $clock->advance(60);
    $container = new class extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance(StorageViewSync::class, $views);
    $container->instance(StorageViewRebuildQueue::class, $rebuilds);
    $command = new StorageViewRebuildCommand();
    $command->setLaravel($container);
    $output = new BufferedOutput();
    $input = new ArrayInput(['--missing' => true, '--queue' => true, '--chunk' => '3']);
    $input->setInteractive(false);
    $status = $command->run($input, $output);
    $printed = $output->fetch();
    storageViewExpect($status === 0 && str_contains($printed, $schemaId . ': queued, job '), 'the command did not enqueue: ' . $printed);
    storageViewExpect($views->registry()->find($schemaId, true) === null, 'the command built inline with --queue');
    $run = $worker->runNext('storage-view-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && ($run->result['records'] ?? null) === 7, 'the enqueued build did not run');
    storageViewExpect(storageViewSnapshot($connection, $table) === $reference, 'the enqueued build differs from a full rebuild');
} finally {
    Facade::clearResolvedInstances();
    @unlink($opened['path']);
}

echo "StorageViewRebuildJobTest passed.\n";
