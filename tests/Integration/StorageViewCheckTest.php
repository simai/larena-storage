<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Larena\Queue\Enums\JobStatus;
use Larena\Storage\Console\Commands\StorageViewCheckCommand;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewChecker;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewRebuildQueue;
use Larena\Storage\View\StorageViewSync;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';
require_once __DIR__ . '/../Support/storage-view-queue-fixture.php';

// larena:storage:view:check on SQLite: a витрина in step with the versions is ok;
// missing, extra and stale rows, a missing or not ready registration and a
// storage that is gone are reported; --repair rebuilds inline what differs (a
// storage that is gone is only reported), and --repair --queue enqueues the
// storage.view.rebuild job the worker then runs.

$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$opened = storageViewQueueOpen();
$connection = $opened['connection'];

/** @param array<string, mixed> $arguments @return array{status: int, output: string} */
function storageViewCheckRun(Container $container, array $arguments): array
{
    $command = new StorageViewCheckCommand();
    $command->setLaravel($container);
    $output = new BufferedOutput();
    $input = new ArrayInput($arguments);
    $input->setInteractive(false);
    $status = $command->run($input, $output);

    return ['status' => $status, 'output' => $output->fetch()];
}

try {
    storageViewQueueInstall($connection);
    $ddl = new SqliteStorageViewDdl($connection);
    $runtime = storageViewRuntime($connection, $ddl, $actor, $scope);
    $workbench = $runtime['workbench'];
    $views = $runtime['views'];
    $clock = new StorageViewTestClock(new DateTimeImmutable('2026-10-20T00:00:00Z'));
    $queue = storageViewQueue($connection, $clock, $views, new StorageMetrics());
    $container = new class extends Container {
        public function runningUnitTests(): bool
        {
            return true;
        }
    };
    $container->instance(StorageViewSync::class, $views);
    $container->instance(StorageViewRebuildQueue::class, $queue['rebuilds']);

    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('tags', 20, 'choices', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]),
    ]], $actor);
    $records = [];
    for ($index = 0; $index < 5; $index++) {
        $records[] = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Item ' . $index, 'tags' => ['a']], $actor);
    }
    $schemaId = $structure->schema->schemaId;
    $table = StorageViewNaming::table($schemaId);
    $checker = new StorageViewChecker($views);

    // In step: ok, and the command says so.
    $report = $checker->check($schemaId);
    storageViewExpect($report['problems'] === [] && $report['records'] === 5 && $report['rows'] === 5, 'a витрина in step was reported: ' . json_encode($report));
    $run = storageViewCheckRun($container, []);
    storageViewExpect($run['status'] === 0 && str_contains($run['output'], $schemaId . ': ok, 5 records'), 'the check of a витрина in step failed: ' . $run['output']);

    // Drift: a row gone, a row that is no record, a row at an older revision.
    $connection->table($table)->where('record_id', $records[0]->recordId)->delete();
    $extra = (array) $connection->table($table)->where('record_id', $records[1]->recordId)->first();
    $extra['record_id'] = 'record-' . str_repeat('0', 32);
    $connection->table($table)->insert($extra);
    $connection->table($table)->where('record_id', $records[2]->recordId)->update(['revision' => 0]);
    $report = $checker->check($schemaId);
    storageViewExpect(
        $report['problems'] === ['rows_missing', 'rows_extra', 'rows_stale'] && $report['missing'] === 1 && $report['extra'] === 1 && $report['stale'] === 1,
        'the drift was not reported: ' . json_encode($report),
    );
    $run = storageViewCheckRun($container, []);
    storageViewExpect($run['status'] === 1 && str_contains($run['output'], 'rows_missing, rows_extra, rows_stale'), 'the check did not fail on drift: ' . $run['output']);
    // A changed hash at the same revision is stale too.
    $connection->table($table)->where('record_id', $records[3]->recordId)->update(['content_hash' => str_repeat('f', 64)]);
    storageViewExpect($checker->check($schemaId)['stale'] === 2, 'a changed hash was not reported stale');

    // --repair rebuilds inline and checks again.
    $run = storageViewCheckRun($container, ['schema' => $schemaId, '--repair' => true, '--chunk' => '2']);
    storageViewExpect($run['status'] === 0 && str_contains($run['output'], '-> repaired, 5 records'), 'the repair did not rebuild: ' . $run['output']);
    storageViewParity($connection, $views, $schemaId, 'repaired');

    // A registration that is not ready, or missing, is reported and repaired.
    $registration = $views->registry()->find($schemaId, true);
    storageViewExpect($registration !== null, 'the repaired витрина lost its registration');
    $views->registry()->put($registration->withState('failed'));
    storageViewExpect($checker->check($schemaId)['problems'] === ['view_failed'], 'a failed витрина was not reported');
    $views->registry()->put($registration->withBuiltVersion(0));
    storageViewExpect($checker->check($schemaId)['problems'] === ['schema_version_behind'], 'a витрина built for an older schema version was not reported');
    $views->registry()->delete($schemaId);
    $ddl->dropTables($table);
    storageViewExpect($checker->check($schemaId)['problems'] === ['view_missing'], 'a missing витрина was not reported');

    // --queue goes with --repair only.
    storageViewExpect(storageViewCheckRun($container, ['--queue' => true])['status'] === 2, '--queue without --repair was accepted');

    // --repair --queue enqueues the rebuild; the worker runs it.
    $run = storageViewCheckRun($container, ['--repair' => true, '--queue' => true, '--chunk' => '2']);
    storageViewExpect($run['status'] === 0 && str_contains($run['output'], $schemaId . ': view_missing') && str_contains($run['output'], '-> queued, job '), 'the repair did not enqueue: ' . $run['output']);
    storageViewExpect($views->registry()->find($schemaId, true) === null, 'the queued repair built inline');
    $job = $queue['worker']->runNext('storage-view-check-worker');
    storageViewExpect($job !== null && $job->status === JobStatus::Completed && ($job->result['records'] ?? null) === 5, 'the queued repair did not run');
    storageViewExpect($checker->check($schemaId)['problems'] === [], 'the queued repair did not bring the витрина in step');
    storageViewParity($connection, $views, $schemaId, 'queued repair');

    // A витрина whose storage is gone is reported, never repaired or dropped.
    $connection->table('larena_storage_views')->where('schema_id', $schemaId)->update(['schema_id' => 'workbench.gone']);
    $views->registry()->forget();
    storageViewExpect(in_array('workbench.gone', $checker->targets(), true), 'a registered витрина without its storage is not checked');
    $run = storageViewCheckRun($container, ['schema' => 'workbench.gone', '--repair' => true]);
    storageViewExpect($run['status'] === 1 && str_contains($run['output'], 'workbench.gone: schema_unknown'), 'a витрина without its storage was not reported: ' . $run['output']);
    storageViewExpect($connection->table('larena_storage_views')->where('schema_id', 'workbench.gone')->exists() && $ddl->tableExists($table), 'the repair touched a витрина without its storage');
} finally {
    Facade::clearResolvedInstances();
    @unlink($opened['path']);
}

echo "StorageViewCheckTest passed.\n";
