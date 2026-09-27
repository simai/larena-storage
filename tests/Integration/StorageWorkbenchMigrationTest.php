<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;

// Declared structure migration: removing fields, changing their type and making
// them required rewrites every record in one transaction, or none of them.

require __DIR__ . '/StorageWorkbenchTest.php';

$migrationPath = tempnam(sys_get_temp_dir(), 'larena-workbench-migration-');
if (!is_string($migrationPath)) {
    throw new RuntimeException('migration_tempfile_failed');
}
$migrationOpened = workbenchOpen($migrationPath);
try {
    workbenchInstall();
    $connection = $migrationOpened['connection'];
    $runtime = workbenchRuntime($connection);
    $workbench = $runtime['workbench'];
    $scope = 'scope:tenant-alpha';
    $actor = 'actor:admin:alpha';
    $field = static fn (string $key, int $position, string $type = 'string', bool $required = false): array => [
        'key' => $key, 'label' => ucfirst($key), 'position' => $position, 'type' => $type, 'type_version' => 1,
        'required' => $required, 'visibility' => 'admin', 'constraints' => [],
    ];
    $descriptor = static fn (array $fields, string $label = 'Clients'): array => [
        'structure_id' => 'workbench.clients', 'label' => $label, 'fields' => $fields,
    ];
    $records = static fn (): array => array_column(array_map(
        static fn ($record): array => ['name' => $record->values['name'] ?? null, 'record' => $record],
        $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.clients', limit: 50, includeArchived: true), $actor)->items,
    ), 'record', 'name');

    $clients = $workbench->createStructure($scope, $descriptor([
        $field('name', 10, 'string', true), $field('age', 20), $field('vip', 30), $field('note', 40, 'text'),
    ]), $actor);
    $anna = $workbench->createRecord($scope, 'workbench.clients', ['name' => 'Anna', 'age' => '30', 'vip' => 'да', 'note' => 'first'], $actor);
    $boris = $workbench->createRecord($scope, 'workbench.clients', ['name' => 'Boris', 'age' => '41', 'vip' => 'no'], $actor);

    // The old update path still refuses these changes.
    workbenchRejects(static fn () => $workbench->updateStructure($scope, 'workbench.clients', $clients->version, $descriptor([
        $field('name', 10, 'string', true), $field('age', 20, 'integer'), $field('vip', 30), $field('note', 40, 'text'),
    ]), $actor), 'storage_workbench_structure_field_changed');

    // Remove "note", retype "age" to integer and "vip" to boolean.
    $migrated = $workbench->migrateStructure($scope, 'workbench.clients', $clients->version, $descriptor([
        $field('name', 10, 'string', true), $field('age', 20, 'integer'), $field('vip', 30, 'boolean'),
    ]), $actor);
    workbenchExpect($migrated->version === $clients->version + 1 && $migrated->schema->version === $clients->schema->version + 1, 'migration did not advance versions');
    workbenchExpect(array_column($migrated->fields, 'key') === ['name', 'age', 'vip'], 'migrated fields are wrong');
    $after = $records();
    workbenchExpect($after['Anna']->values === ['age' => 30, 'name' => 'Anna', 'vip' => true], 'Anna was not converted: ' . json_encode($after['Anna']->values));
    workbenchExpect($after['Boris']->values === ['age' => 41, 'name' => 'Boris', 'vip' => false], 'Boris was not converted');
    workbenchExpect($after['Anna']->revision === $anna->revision + 1 && $after['Anna']->operation === 'schema_migration', 'migration revision missing');
    $history = $workbench->recordHistory($scope, 'workbench.clients', $anna->recordId, $actor);
    workbenchExpect(($history[1]->values['note'] ?? null) === 'first', 'history lost the removed value');

    // A value that cannot be converted refuses the whole migration and changes nothing.
    $workbench->createRecord($scope, 'workbench.clients', ['name' => 'Vera', 'age' => 7], $actor);
    $withText = $workbench->migrateStructure($scope, 'workbench.clients', $migrated->version, $descriptor([
        $field('name', 10, 'string', true), $field('age', 20, 'string'), $field('vip', 30, 'boolean'),
    ]), $actor);
    workbenchExpect($records()['Vera']->values['age'] === '7', 'integer to string did not convert');
    $workbench->createRecord($scope, 'workbench.clients', ['name' => 'Gleb', 'age' => 'about forty'], $actor);
    $before = $connection->table('larena_storage_record_versions')->count();
    workbenchRejects(static fn () => $workbench->migrateStructure($scope, 'workbench.clients', $withText->version, $descriptor([
        $field('name', 10, 'string', true), $field('age', 20, 'integer'), $field('vip', 30, 'boolean'),
    ]), $actor), 'storage_schema_migration_record_unconvertible');
    workbenchExpect($connection->table('larena_storage_record_versions')->count() === $before, 'a refused migration wrote revisions');
    workbenchExpect($workbench->readStructure($scope, 'workbench.clients', $actor)->version === $withText->version, 'a refused migration changed the structure');

    // Making a field required is refused while a record has no value for it.
    workbenchRejects(static fn () => $workbench->migrateStructure($scope, 'workbench.clients', $withText->version, $descriptor([
        $field('name', 10, 'string', true), $field('age', 20, 'string'), $field('vip', 30, 'boolean', true),
    ]), $actor), 'storage_schema_migration_record_unconvertible');

    // Permission and archive boundaries.
    workbenchExpect(in_array('storage.workbench.structure.migrate', $runtime['authorizer']->operations, true), 'migrate permission not checked');
    $workbench->archiveStructure($scope, 'workbench.clients', $withText->version, $actor);
    workbenchRejects(static fn () => $workbench->migrateStructure($scope, 'workbench.clients', $withText->version, $descriptor([
        $field('name', 10, 'string', true), $field('age', 20, 'string'),
    ]), $actor), 'storage_workbench_structure_archived');
} finally {
    Facade::clearResolvedInstances();
    foreach ([$migrationPath, $migrationPath . '-wal', $migrationPath . '-shm', $migrationPath . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageWorkbenchMigrationTest passed.\n";
