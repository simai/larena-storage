<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Runtime\QueueControlService;
use Larena\Queue\Storage\DatabaseQueueStore;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\SchemaEvolution\StorageFieldChangeJob;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewRebuilder;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';
require_once __DIR__ . '/../Support/storage-view-queue-fixture.php';

// Field changes without rewriting records, on SQLite:
// - an optional field is added: a new schema version, an empty column, no version row written,
//   no backfill; older versions read through the converter;
// - a field is removed: no version row written, the column dropped after the switch, history
//   and a revert read under the current fields (the removed value does not come back), the
//   published projection loses it;
// - a field is retyped on a storage above the inline limit: the storage.schema.migrate job
//   checks every record in chunks and fills the new column while the list answers from the old
//   one; it resumes after a timeout and after a queue cancel; writes during the job are checked
//   again before the switch; a write that cannot convert fails the change; a cancel changes
//   nothing; the structure exposes the витрина and change state throughout.

/** A queue clock the test moves by hand, or that moves itself when a condition is met. */
final class StorageFieldChangeTestClock implements QueueClock
{
    /** @var (Closure(self): void)|null */
    public ?Closure $tick = null;

    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        if ($this->tick !== null) {
            $tick = $this->tick;
            $tick($this);
        }

        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->add(new DateInterval('PT' . $seconds . 'S'));
    }
}

$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$opened = storageViewQueueOpen();
$connection = $opened['connection'];

try {
    storageViewQueueInstall($connection);
    (require __DIR__ . '/../../database/migrations/platform/2026_09_29_000001_create_larena_storage_publication_tables.php')->up();
    $ddl = new SqliteStorageViewDdl($connection);
    $clock = new StorageFieldChangeTestClock(new DateTimeImmutable('2026-10-21T00:00:00Z'));
    $registry = new JobTypeRegistry();
    $store = new DatabaseQueueStore($connection);
    $dispatcher = new DurableQueueDispatcher($registry, $store, $clock);
    $worker = new DurableQueueWorker($registry, $store, $clock);
    $control = new QueueControlService($store, $clock);
    $jobs = [];
    // Above three records a field change runs in the background, two records a chunk.
    $runtime = storageViewRuntime($connection, $ddl, $actor, $scope, null, null, static function (string $changeRef) use ($dispatcher, &$jobs): bool {
        $jobs[$changeRef] = $dispatcher->dispatch(StorageFieldChangeJob::request($changeRef, 2))->jobId;

        return true;
    }, 3);
    $workbench = $runtime['workbench'];
    $views = $runtime['views'];
    $registry->register(StorageFieldChangeJob::descriptor(), new StorageFieldChangeJob($workbench));
    $versionRows = static fn (string $schemaId): int => $connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->count();
    $change = static fn (string $changeRef): stdClass => $connection->table('larena_storage_field_changes')->where('change_ref', $changeRef)->first()
        ?? throw new RuntimeException('the field change row is missing');
    $list = static fn (array $filters = []): array => $workbench->listRecords(
        new StorageWorkbenchRecordQuery($scope, 'workbench.items', $filters, null, [['field' => '_record_id', 'direction' => 'asc']], 100, includeArchived: true),
        $actor,
    )->items;
    $byTitle = static function () use ($list): array {
        $records = [];
        foreach ($list() as $record) {
            $records[(string) $record->values['title']] = $record;
        }

        return $records;
    };

    $fields = [
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'integer'),
        storageViewField('note', 30, 'text'),
    ];
    $descriptor = static fn (array $fields): array => ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $fields];
    $structure = $workbench->createStructure($scope, $descriptor($fields), $actor);
    $schemaId = $structure->schema->schemaId;
    $table = StorageViewNaming::table($schemaId);
    $created = [];
    for ($index = 1; $index <= 7; $index++) {
        $created[] = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Item ' . $index, 'qty' => $index, 'note' => 'note ' . $index], $actor);
    }
    $structure = $workbench->readStructure($scope, 'workbench.items', $actor);
    storageViewExpect($structure->viewState === 'ready' && !$structure->viewBackfillPending && $structure->viewPendingFields === [] && $structure->fieldChange === null,
        'a new structure does not say its витрина is ready: ' . json_encode([$structure->viewState, $structure->viewBackfillPending, $structure->viewPendingFields]));

    // --- An optional field is added: no record version is written, nothing is backfilled. ---
    $before = $versionRows($schemaId);
    $columnsBefore = $ddl->columns($table);
    $withColor = array_merge($fields, [storageViewField('color', 40, 'string')]);
    $structure = $workbench->updateStructure($scope, 'workbench.items', $structure->version, $descriptor($withColor), $actor);
    storageViewExpect($structure->schema->version === 2, 'the added field gave no new schema version');
    storageViewExpect($versionRows($schemaId) === $before, 'adding an optional field wrote record versions');
    storageViewExpect(count(array_diff($ddl->columns($table), $columnsBefore)) === 1, 'the added field got no column');
    $registration = $views->registry()->find($schemaId, true);
    storageViewExpect($registration !== null && $registration->state === 'ready' && $registration->builtSchemaVersion === 2 && !$registration->backfillPending(),
        'the added field left the витрина to backfill');
    storageViewExpect(!$structure->viewBackfillPending && $structure->viewPendingFields === [], 'the structure says an added field is being filled');
    storageViewParity($connection, $views, $schemaId, 'field added');
    $old = $workbench->readRecord($scope, 'workbench.items', $created[0]->recordId, $actor);
    storageViewExpect($old->revision === 1 && $old->schemaVersion === 1 && !array_key_exists('color', $old->values) && $old->values['qty'] === 1,
        'an older version does not read under the new fields: ' . json_encode($old->values));
    storageViewExpect($list(['color' => ['operator' => 'eq', 'value' => 'red']]) === [], 'an empty added field matched a filter');
    $red = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Item 8', 'qty' => 8, 'color' => 'red'], $actor);
    storageViewExpect(array_map(static fn ($record): string => $record->recordId, $list(['color' => ['operator' => 'eq', 'value' => 'red']])) === [$red->recordId], 'a written added field is not filtered');
    $updated = $workbench->updateRecord($scope, 'workbench.items', $old->recordId, $old->revision, $old->values + ['color' => 'blue'], $actor);
    storageViewExpect($updated->revision === 2 && $updated->schemaVersion === 2, 'a write over an older version is not at the current schema version');
    storageViewParity($connection, $views, $schemaId, 'written after the field was added');

    // --- A field is removed: no version written; history, revert and the list read without it. ---
    $before = $versionRows($schemaId);
    $structure = $workbench->migrateStructure($scope, 'workbench.items', $structure->version, $descriptor([
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'integer'),
        storageViewField('color', 40, 'string'),
    ]), $actor);
    storageViewExpect($structure->schema->version === 3 && $structure->fieldChange === null, 'removing a field did not switch at once');
    storageViewExpect($versionRows($schemaId) === $before, 'removing a field wrote record versions');
    storageViewExpect(!in_array('note', array_merge(...array_map(static fn ($record): array => array_keys($record->values), $list())), true), 'the list still reads the removed field');
    $stored = json_decode((string) $connection->table('larena_storage_record_versions')->where('record_id', $created[1]->recordId)->where('revision', 1)->value('values_json'), true);
    storageViewExpect(is_array($stored) && ($stored['note'] ?? null) === 'note 2', 'the stored version lost the removed value');
    $history = $workbench->recordHistory($scope, 'workbench.items', $updated->recordId, $actor);
    storageViewExpect(count($history) === 2 && !array_key_exists('note', $history[1]->values), 'history resurrects the removed field');
    $reverted = $workbench->updateRecord($scope, 'workbench.items', $updated->recordId, $updated->revision, $history[1]->values, $actor);
    storageViewExpect(!array_key_exists('note', $reverted->values) && !array_key_exists('color', $reverted->values) && $reverted->values['qty'] === 1, 'a revert brought a removed value back');
    $registration = $views->registry()->find($schemaId, true);
    storageViewExpect($registration !== null && $registration->layout->inState('dropping') === [] && count($ddl->columns($table)) === count($columnsBefore), 'the removed field kept its column');
    // note was searched: its search text is refilled by the backfill; the structure says so.
    storageViewExpect($registration->backfillPending() && $structure->viewBackfillPending, 'the removed searched field left the search text as it was');
    (new StorageViewRebuilder($views))->rebuild($schemaId, 3);
    storageViewParity($connection, $views, $schemaId, 'field removed');
    storageViewExpect(!$workbench->readStructure($scope, 'workbench.items', $actor)->viewBackfillPending, 'the backfill did not end');

    // --- A retype above the inline limit runs as the storage.schema.migrate job. ---
    $before = $versionRows($schemaId);
    $oldQty = null;
    foreach ($registration->layout->columns as $column) {
        $oldQty = $column->fieldKey === 'qty' ? $column->column : $oldQty;
    }
    $retyped = [
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'number'),
        storageViewField('color', 40, 'string'),
    ];
    $running = $workbench->migrateStructure($scope, 'workbench.items', $structure->version, $descriptor($retyped), $actor);
    storageViewExpect($running->version === $structure->version && $running->schema->version === 3 && $running->changing(), 'a large retype did not go to the background');
    $changeRef = $running->fieldChange->changeRef ?? '';
    storageViewExpect(isset($jobs[$changeRef]) && $running->fieldChange?->total === 8 && $running->fieldChange->fields === ['qty'] && $running->viewPendingFields === ['qty'],
        'the running change is not exposed: ' . json_encode($running->fieldChange));
    storageViewRejects(static fn () => $workbench->updateStructure($scope, 'workbench.items', $running->version, $descriptor(array_merge($retyped, [storageViewField('extra', 50, 'string')])), $actor), 'storage_workbench_field_change_running');
    storageViewRejects(static fn () => $workbench->archiveStructure($scope, 'workbench.items', $running->version, $actor), 'storage_workbench_field_change_running');
    // The list keeps answering from the old integer column.
    storageViewExpect(count($list(['qty' => ['operator' => 'gte', 'value' => 6]])) === 3, 'the list does not answer from the old column during the change');

    // The job times out after its first chunk, is retried, and resumes.
    $armed = true;
    $clock->tick = static function (StorageFieldChangeTestClock $clock) use (&$armed, $change, $changeRef): void {
        if ($armed && (int) $change($changeRef)->checked >= 2) {
            $armed = false;
            $clock->advance(StorageFieldChangeJob::TIMEOUT_SECONDS + 1);
        }
    };
    $run = $worker->runNext('field-change-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Retrying && $run->failureReason === 'execution_timed_out', 'the timed out job was not set to retry: ' . ($run?->status->value ?? 'none'));
    $row = $change($changeRef);
    storageViewExpect($row->state === 'running' && (int) $row->checked === 2 && $row->cursor !== null, 'the timed out job did not keep its position');
    storageViewExpect($workbench->readStructure($scope, 'workbench.items', $actor)->fieldChange?->checked === 2, 'the structure does not show the progress');
    // Writes go on during the change: one behind the walk's position, one new record.
    $behind = $workbench->readRecord($scope, 'workbench.items', (string) $connection->table('larena_storage_records')->where('schema_id', $schemaId)->orderBy('record_id')->value('record_id'), $actor);
    $workbench->updateRecord($scope, 'workbench.items', $behind->recordId, $behind->revision, ['title' => $behind->values['title'], 'qty' => 100], $actor);
    $workbench->createRecord($scope, 'workbench.items', ['title' => 'Item 9', 'qty' => 9], $actor);
    $clock->advance(StorageFieldChangeJob::RETRY_DELAY_SECONDS + 1);
    $run = $worker->runNext('field-change-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && ($run->result['state'] ?? null) === 'done' && ($run->result['resumed'] ?? null) === true,
        'the retried job did not finish the change: ' . json_encode($run?->result) . ' ' . ($run->failureReason ?? ''));
    $structure = $workbench->readStructure($scope, 'workbench.items', $actor);
    storageViewExpect($structure->version === $running->version + 1 && $structure->schema->version === 4 && $structure->fieldChange === null && $structure->viewPendingFields === [],
        'the change did not switch the structure');
    storageViewExpect($versionRows($schemaId) === $before + 2, 'the retype wrote record versions beyond the two writes');
    $records = $byTitle();
    // A record nobody wrote since it was created: still its first version, read converted.
    $untouched = null;
    foreach ($created as $index => $record) {
        if ($index > 0 && $record->recordId !== $behind->recordId) {
            $untouched = ['title' => 'Item ' . ($index + 1), 'qty' => (string) ($index + 1)];
            break;
        }
    }
    storageViewExpect($untouched !== null && $records[$behind->values['title']]->values['qty'] === '100' && $records['Item 9']->values['qty'] === '9'
        && $records[$untouched['title']]->values['qty'] === $untouched['qty'], 'the retyped values do not read converted');
    storageViewExpect($records[$untouched['title']]->schemaVersion === 1 && $records[$untouched['title']]->revision === 1, 'an untouched record was rewritten');
    $registration = $views->registry()->find($schemaId, true);
    $qty = array_values(array_filter($registration?->layout->columns ?? [], static fn ($column): bool => $column->fieldKey === 'qty'));
    storageViewExpect(count($qty) === 1 && $qty[0]->sqlType === 'decimal' && $qty[0]->state === 'ready' && $qty[0]->column !== $oldQty
        && !in_array($oldQty, $ddl->columns($table), true), 'the retyped field did not swap to its filled column');
    storageViewParity($connection, $views, $schemaId, 'retyped by the job');
    $expected = count(array_filter($list(), static fn ($record): bool => is_string($record->values['qty'] ?? null) && (float) $record->values['qty'] >= 5 && (float) $record->values['qty'] <= 9));
    storageViewExpect($expected >= 4 && count($list(['qty' => ['operator' => 'between', 'values' => ['5', '9']]])) === $expected, 'the new column does not answer the filter');

    // --- A write during the change that cannot convert fails it; nothing switches. ---
    $failing = $workbench->migrateStructure($scope, 'workbench.items', $structure->version, $descriptor([
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'integer'),
        storageViewField('color', 40, 'string'),
    ]), $actor);
    $failingRef = $failing->fieldChange->changeRef ?? '';
    storageViewExpect($failing->changing(), 'the second change did not run in the background');
    $armed = true;
    $clock->tick = static function (StorageFieldChangeTestClock $clock) use (&$armed, $change, $failingRef): void {
        if ($armed && (int) $change($failingRef)->checked >= 2) {
            $armed = false;
            $clock->advance(StorageFieldChangeJob::TIMEOUT_SECONDS + 1);
        }
    };
    $worker->runNext('field-change-worker');
    $workbench->createRecord($scope, 'workbench.items', ['title' => 'Item 10', 'qty' => '2.5'], $actor);
    $clock->advance(StorageFieldChangeJob::RETRY_DELAY_SECONDS + 1);
    $run = $worker->runNext('field-change-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Failed && $run->failureReason === 'storage_schema_migration_record_unconvertible',
        'an unconvertible write did not fail the change: ' . ($run?->status->value ?? 'none') . ' ' . ($run->failureReason ?? ''));
    $after = $workbench->readStructure($scope, 'workbench.items', $actor);
    storageViewExpect($after->version === $structure->version && $after->schema->version === 4 && !$after->changing()
        && $after->fieldChange?->state === 'failed' && $after->fieldChange->reason === 'storage_schema_migration_record_unconvertible', 'a failed change switched the structure or hid its failure');
    storageViewParity($connection, $views, $schemaId, 'after a failed change');

    // --- A cancel changes nothing; a queue cancel leaves the change to resume. ---
    $cancelled = $workbench->migrateStructure($scope, 'workbench.items', $after->version, $descriptor([
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'string'),
        storageViewField('color', 40, 'string'),
    ]), $actor);
    $cancelledRef = $cancelled->fieldChange->changeRef ?? '';
    $afterCancel = $workbench->cancelFieldChange($scope, 'workbench.items', $actor);
    storageViewExpect($afterCancel->fieldChange?->state === 'cancelled' && !$afterCancel->changing() && $afterCancel->version === $after->version, 'the cancel was not recorded');
    $run = $worker->runNext('field-change-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && ($run->result['state'] ?? null) === 'cancelled', 'the job of a cancelled change did work');
    storageViewExpect($workbench->readStructure($scope, 'workbench.items', $actor)->schema->version === 4, 'a cancelled change switched the structure');
    storageViewRejects(static fn () => $workbench->cancelFieldChange($scope, 'workbench.items', $actor), 'storage_workbench_field_change_not_running');
    $queueCancelled = $workbench->migrateStructure($scope, 'workbench.items', $after->version, $descriptor([
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
        storageViewField('qty', 20, 'string'),
        storageViewField('color', 40, 'string'),
    ]), $actor);
    $queueRef = $queueCancelled->fieldChange->changeRef ?? '';
    $armed = true;
    $clock->tick = static function (StorageFieldChangeTestClock $clock) use (&$armed, $change, $queueRef, $control, &$jobs): void {
        if ($armed && (int) $change($queueRef)->checked >= 2) {
            $armed = false;
            $control->cancel($jobs[$queueRef]);
        }
    };
    $run = $worker->runNext('field-change-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Cancelled, 'the queue cancel did not stop the job: ' . ($run?->status->value ?? 'none'));
    storageViewExpect($change($queueRef)->state === 'running' && $workbench->readStructure($scope, 'workbench.items', $actor)->changing(), 'a queue cancel ended the change');
    $clock->tick = null;
    $resumed = $dispatcher->dispatch(StorageFieldChangeJob::request($queueRef, 2, (string) $change($queueRef)->cursor));
    storageViewExpect(!$resumed->duplicate && $resumed->jobId !== $jobs[$queueRef], 'a moved change did not get a new job');
    $run = $worker->runNext('field-change-worker');
    storageViewExpect($run !== null && $run->status === JobStatus::Completed && ($run->result['resumed'] ?? null) === true, 'the new job did not resume the change');
    $structure = $workbench->readStructure($scope, 'workbench.items', $actor);
    storageViewExpect($structure->schema->version === 5 && $byTitle()['Item 10']->values['qty'] === '2.5' && $byTitle()[$untouched['title']]->values['qty'] === $untouched['qty'], 'the resumed change did not switch');
    // qty is a string now, and strings are searched: its text joins the search through the backfill.
    storageViewExpect($structure->viewBackfillPending && $views->registry()->find($schemaId, true)?->layout->searchState === 'backfilling', 'the newly searched field did not ask for the search refill');
    (new StorageViewRebuilder($views))->rebuild($schemaId, 4);
    storageViewParity($connection, $views, $schemaId, 'resumed after a queue cancel');

    // A field change of a small storage runs in the request, all or nothing.
    $small = $workbench->createStructure($scope, ['structure_id' => 'workbench.small', 'label' => 'Small', 'fields' => [storageViewField('title', 10, 'string'), storageViewField('n', 20, 'string')]], $actor);
    $workbench->createRecord($scope, 'workbench.small', ['title' => 'A', 'n' => '12'], $actor);
    $workbench->createRecord($scope, 'workbench.small', ['title' => 'B', 'n' => 'twelve'], $actor);
    storageViewRejects(static fn () => $workbench->migrateStructure($scope, 'workbench.small', $small->version, ['structure_id' => 'workbench.small', 'label' => 'Small', 'fields' => [storageViewField('title', 10, 'string'), storageViewField('n', 20, 'integer')]], $actor),
        'storage_schema_migration_record_unconvertible');
    storageViewExpect($workbench->readStructure($scope, 'workbench.small', $actor)->schema->version === 1, 'a refused small change switched');

    // A field removed and later added again under the same key starts empty: the converter walks
    // the changes one by one, so the removed value does not come back.
    $revive = $workbench->createStructure($scope, ['structure_id' => 'workbench.revive', 'label' => 'Revive', 'fields' => [storageViewField('title', 10, 'string'), storageViewField('x', 20, 'string')]], $actor);
    $kept = $workbench->createRecord($scope, 'workbench.revive', ['title' => 'Kept', 'x' => 'removed by the owner'], $actor);
    $revive = $workbench->migrateStructure($scope, 'workbench.revive', $revive->version, ['structure_id' => 'workbench.revive', 'label' => 'Revive', 'fields' => [storageViewField('title', 10, 'string')]], $actor);
    $revive = $workbench->updateStructure($scope, 'workbench.revive', $revive->version, ['structure_id' => 'workbench.revive', 'label' => 'Revive', 'fields' => [storageViewField('title', 10, 'string'), storageViewField('x', 20, 'string')]], $actor);
    $read = $workbench->readRecord($scope, 'workbench.revive', $kept->recordId, $actor);
    storageViewExpect($revive->schema->version === 3 && $read->schemaVersion === 1 && !array_key_exists('x', $read->values), 'a re-added field brought the removed value back');

    // --- Publication: the published projection reads under the current fields. ---
    $public = static fn (string $key, int $position, string $type): array => ['key' => $key, 'label' => ucfirst($key), 'position' => $position, 'type' => $type,
        'type_version' => 1, 'required' => false, 'visibility' => 'public', 'constraints' => []];
    $pages = $workbench->createStructure($scope, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => [
        $public('title', 10, 'string'), $public('price', 20, 'integer'), $public('secret', 30, 'string'),
    ]], $actor);
    $page = $workbench->createRecord($scope, 'workbench.pages', ['title' => 'Home', 'price' => 10, 'secret' => 'removed later'], $actor);
    (new DatabasePublicationLifecycle($connection))->publish($pages->schema->schemaId, $page->recordId, $scope, 'en', $page->revision, $actor);
    $reads = new DatabaseReadContracts($connection);
    $published = static fn (): array => $reads->publishedRecord($pages->schema->schemaId, $scope, 'en', $page->recordId)['values'] ?? [];
    storageViewExpect(($published()['secret'] ?? null) === 'removed later', 'the published record lacks a public field');
    $pages = $workbench->migrateStructure($scope, 'workbench.pages', $pages->version, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => [
        $public('title', 10, 'string'), $public('price', 20, 'number'),
    ]], $actor);
    storageViewExpect($pages->schema->version === 2, 'the small retype did not switch inline');
    $values = $published();
    storageViewExpect(!array_key_exists('secret', $values) && $values['price'] === '10' && $values['title'] === 'Home', 'the published projection does not read under the current fields: ' . json_encode($values));
} finally {
    Facade::clearResolvedInstances();
    @unlink($opened['path']);
}

echo "StorageFieldChangeTest passed.\n";
