<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;

// Structure archive, restore and permanent deletion; permanent deletion of
// archived records; the hidden field flag. Runs on the shared workbench fixture.

require __DIR__ . '/StorageWorkbenchTest.php';

$lifecyclePath = tempnam(sys_get_temp_dir(), 'larena-workbench-lifecycle-');
if (!is_string($lifecyclePath)) {
    throw new RuntimeException('lifecycle_tempfile_failed');
}
$lifecycleOpened = workbenchOpen($lifecyclePath);
try {
    workbenchInstall();
    (require __DIR__ . '/../../database/migrations/platform/2026_09_26_000001_create_larena_storage_structure_role_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/platform/2026_09_27_000001_create_larena_storage_record_relations_table.php')->up();
    $connection = $lifecycleOpened['connection'];
    $runtime = workbenchRuntime($connection);
    $workbench = $runtime['workbench'];
    $sink = $runtime['sink'];
    $scope = 'scope:tenant-alpha';
    $actor = 'actor:admin:alpha';
    $field = static fn (string $key, int $position, array $extra = []): array => [
        'key' => $key, 'label' => ucfirst($key), 'position' => $position, 'type' => 'string', 'type_version' => 1,
        'required' => false, 'visibility' => 'admin', 'constraints' => [],
    ] + $extra;
    $events = static fn (string $type): int => count(array_filter(
        $sink->events,
        static fn ($event): bool => $event->type === $type,
    ));

    // The hidden flag is optional, kept in the descriptor and changeable like a label.
    $clients = $workbench->createStructure($scope, [
        'structure_id' => 'workbench.clients',
        'label' => 'Clients',
        'fields' => [$field('name', 10), $field('phone', 20)],
    ], $actor);
    workbenchExpect(!array_key_exists('hidden', $clients->fields[1]), 'a field that says nothing gained a hidden key');
    $clients = $workbench->updateStructure($scope, 'workbench.clients', $clients->version, [
        'structure_id' => 'workbench.clients',
        'label' => 'Clients',
        'fields' => [$field('name', 10), $field('phone', 20, ['hidden' => true])],
    ], $actor);
    workbenchExpect(($clients->fields[1]['hidden'] ?? null) === true, 'hiding a field was not kept');
    workbenchExpect($clients->schema->version === 1, 'hiding a field changed the storage schema');
    workbenchRejects(static fn () => $workbench->updateStructure($scope, 'workbench.clients', $clients->version, [
        'structure_id' => 'workbench.clients',
        'label' => 'Clients',
        'fields' => [$field('name', 10), $field('phone', 20, ['hidden' => 'yes'])],
    ], $actor), 'storage_workbench_structure_field_invalid');

    $first = $workbench->createRecord($scope, 'workbench.clients', ['name' => 'Anna', 'phone' => '1'], $actor);
    $second = $workbench->createRecord($scope, 'workbench.clients', ['name' => 'Boris'], $actor);

    // Permanent deletion takes archived records only, at their current revision.
    workbenchRejects(
        static fn () => $workbench->purgeRecords($scope, 'workbench.clients', [$first->recordId => $first->revision], $actor),
        'storage_workbench_record_not_archived',
    );
    $archived = $workbench->archiveRecord($scope, 'workbench.clients', $first->recordId, $first->revision, $actor);
    workbenchRejects(
        static fn () => $workbench->purgeRecords($scope, 'workbench.clients', [$first->recordId => $first->revision], $actor),
        'storage_workbench_record_revision_conflict',
    );
    $receipt = $workbench->purgeRecords($scope, 'workbench.clients', [$archived->recordId => $archived->revision], $actor);
    workbenchExpect($receipt->recordIds === [$first->recordId] && $receipt->versionCount === 2, 'record purge receipt is wrong');
    workbenchExpect(
        $connection->table('larena_storage_record_versions')->where('record_id', $first->recordId)->count() === 0
            && $connection->table('larena_storage_records')->where('record_id', $first->recordId)->count() === 0,
        'purged record versions remain',
    );
    workbenchRejects(
        static fn () => $workbench->readRecord($scope, 'workbench.clients', $first->recordId, $actor),
        'storage_workbench_record_unknown',
    );
    workbenchExpect($events('storage.record.purged') === 1, 'record purge was not audited once');
    workbenchExpect(!str_contains(json_encode(array_map(static fn ($event) => $event->payload, $sink->events), JSON_THROW_ON_ERROR), 'Anna'), 'audit carries a value');

    // An archived structure keeps its records, refuses record writes and edits, and is listed only on request.
    workbenchRejects(
        static fn () => $workbench->purgeStructure($scope, 'workbench.clients', $clients->version, $actor),
        'storage_workbench_structure_not_archived',
    );
    $archivedStructure = $workbench->archiveStructure($scope, 'workbench.clients', $clients->version, $actor);
    workbenchExpect($archivedStructure->isArchived() && $archivedStructure->archivedBy === $actor, 'structure is not archived');
    workbenchExpect($archivedStructure->version === $clients->version, 'archiving changed the structure version');
    workbenchRejects(static fn () => $workbench->archiveStructure($scope, 'workbench.clients', $clients->version, $actor), 'storage_workbench_structure_archived');
    workbenchRejects(static fn () => $workbench->createRecord($scope, 'workbench.clients', ['name' => 'Vera'], $actor), 'storage_workbench_structure_archived');
    workbenchRejects(static fn () => $workbench->updateRecord($scope, 'workbench.clients', $second->recordId, $second->revision, ['name' => 'B'], $actor), 'storage_workbench_structure_archived');
    workbenchRejects(static fn () => $workbench->updateStructure($scope, 'workbench.clients', $clients->version, [
        'structure_id' => 'workbench.clients', 'label' => 'Renamed', 'fields' => [$field('name', 10), $field('phone', 20, ['hidden' => true])],
    ], $actor), 'storage_workbench_structure_archived');
    $active = array_map(static fn ($structure) => $structure->structureId, $workbench->listStructures($scope, $actor));
    $all = array_map(static fn ($structure) => $structure->structureId, $workbench->listStructures($scope, $actor, true));
    workbenchExpect(!in_array('workbench.clients', $active, true) && in_array('workbench.clients', $all, true), 'archived structure listing is wrong');
    $page = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.clients', limit: 10), $actor);
    workbenchExpect($page->matchedCount === 1, 'an archived structure stopped reading its records');

    $restored = $workbench->restoreStructure($scope, 'workbench.clients', $clients->version, $actor);
    workbenchExpect(!$restored->isArchived(), 'structure was not restored');
    workbenchRejects(static fn () => $workbench->restoreStructure($scope, 'workbench.clients', $clients->version, $actor), 'storage_workbench_structure_not_archived');
    $workbench->createRecord($scope, 'workbench.clients', ['name' => 'Vera'], $actor);
    workbenchExpect($events('storage.workbench.structure.archived') === 1 && $events('storage.workbench.structure.restored') === 1, 'structure state change was not audited');

    // Another tenant cannot touch the structure.
    workbenchRejects(static fn () => $workbench->archiveStructure($scope, 'workbench.clients', $clients->version, 'actor:admin:beta'), 'storage_workbench_scope_denied');

    // A record referenced from another structure blocks deleting its structure; the edge from inside does not.
    $orders = $workbench->createStructure($scope, [
        'structure_id' => 'workbench.orders',
        'label' => 'Orders',
        'fields' => [$field('title', 10)],
    ], $actor);
    $order = $workbench->createRecord($scope, 'workbench.orders', ['title' => 'Order 1'], $actor);
    $clientsNow = $workbench->readStructure($scope, 'workbench.clients', $actor);
    $clientRecords = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.clients', limit: 10), $actor)->items;
    $relation = static fn (string $id, string $from, string $to, string $schemaId): array => [
        'relation_id' => $id, 'relation_key' => 'owner', 'schema_id' => $schemaId, 'from_record_id' => $from,
        'to_record_id' => $to, 'kind' => 'reference', 'delete_policy' => 'restrict', 'created_by' => 'actor:admin:alpha',
        'created_at' => '2026-09-27 00:00:00', 'updated_at' => '2026-09-27 00:00:00',
    ];
    $connection->table('larena_storage_record_relations')->insert($relation('rel-external', $order->recordId, $clientRecords[0]->recordId, $orders->schema->schemaId));
    $connection->table('larena_storage_record_relations')->insert($relation('rel-internal', $clientRecords[1]->recordId, $clientRecords[0]->recordId, $clientsNow->schema->schemaId));
    $workbench->archiveStructure($scope, 'workbench.clients', $clientsNow->version, $actor);
    workbenchRejects(static fn () => $workbench->purgeStructure($scope, 'workbench.clients', $clientsNow->version, $actor), 'storage_record_purge_referenced');
    workbenchExpect($connection->table('larena_storage_workbench_structures')->where('structure_id', 'workbench.clients')->exists(), 'a refused purge removed the structure');
    $connection->table('larena_storage_record_relations')->where('relation_id', 'rel-external')->delete();

    // A bound structure role blocks deletion too.
    $connection->table('larena_storage_structure_role_bindings')->insert([
        'binding_id' => 'binding-1', 'role_ref' => 'role:site_tree', 'schema_id' => $clientsNow->schema->schemaId,
        'scope_ref' => $scope, 'bound_by' => $actor, 'created_at' => '2026-09-27 00:00:00', 'updated_at' => '2026-09-27 00:00:00',
    ]);
    workbenchRejects(static fn () => $workbench->purgeStructure($scope, 'workbench.clients', $clientsNow->version, $actor), 'storage_workbench_structure_role_bound');
    $connection->table('larena_storage_structure_role_bindings')->delete();

    workbenchRejects(static fn () => $workbench->purgeStructure($scope, 'workbench.clients', $clientsNow->version + 1, $actor), 'storage_workbench_structure_version_conflict');
    $purged = $workbench->purgeStructure($scope, 'workbench.clients', $clientsNow->version, $actor);
    workbenchExpect($purged->structurePurged && count($purged->recordIds) === 2, 'structure purge receipt is wrong');
    workbenchExpect(
        !$connection->table('larena_storage_workbench_structures')->where('structure_id', 'workbench.clients')->exists()
            && !$connection->table('larena_storage_workbench_structure_versions')->where('structure_id', 'workbench.clients')->exists()
            && $connection->table('larena_storage_records')->where('schema_id', $clientsNow->schema->schemaId)->count() === 0
            && $connection->table('larena_storage_record_relations')->count() === 0,
        'structure purge left rows behind',
    );
    workbenchRejects(static fn () => $workbench->readStructure($scope, 'workbench.clients', $actor), 'storage_workbench_structure_unknown');
    workbenchExpect($events('storage.workbench.structure.purged') === 1 && $events('storage.record.purged') === 3, 'structure purge was not audited');
    workbenchExpect($workbench->readRecord($scope, 'workbench.orders', $order->recordId, $actor)->revision === 1, 'an unrelated structure was touched');

    // The structure id is not reusable: its storage schema identity stays as a tombstone.
    workbenchRejects(static fn () => $workbench->createStructure($scope, [
        'structure_id' => 'workbench.clients', 'label' => 'Clients again', 'fields' => [$field('name', 10)],
    ], $actor), 'storage_schema_already_exists');

    $operations = array_unique($runtime['authorizer']->operations);
    foreach (['storage.workbench.structure.archive', 'storage.workbench.structure.restore', 'storage.workbench.structure.purge', 'storage.workbench.record.purge', 'storage.record.purge'] as $operation) {
        workbenchExpect(in_array($operation, $operations, true), 'operation not checked: ' . $operation);
    }
} finally {
    Facade::clearResolvedInstances();
    foreach ([$lifecyclePath, $lifecyclePath . '-wal', $lifecyclePath . '-shm', $lifecyclePath . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageWorkbenchLifecycleTest passed.\n";
