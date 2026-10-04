<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;

// record@1: a field naming a record of another structure. Writes name an active record of the
// target structure in the same scope; a referenced record or structure is not purged.

require __DIR__ . '/StorageWorkbenchTest.php';

$referencePath = tempnam(sys_get_temp_dir(), 'larena-workbench-reference-');
if (!is_string($referencePath)) {
    throw new RuntimeException('reference_tempfile_failed');
}
$referenceOpened = workbenchOpen($referencePath);
try {
    workbenchInstall();
    (require __DIR__ . '/../../database/migrations/platform/2026_09_26_000001_create_larena_storage_structure_role_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/platform/2026_09_27_000001_create_larena_storage_record_relations_table.php')->up();
    $runtime = workbenchRuntime($referenceOpened['connection']);
    $workbench = $runtime['workbench'];
    $scope = 'scope:tenant-alpha';
    $actor = 'actor:admin:alpha';
    $string = static fn (string $key, int $position): array => [
        'key' => $key, 'label' => ucfirst($key), 'position' => $position, 'type' => 'string', 'type_version' => 1,
        'required' => false, 'visibility' => 'admin', 'constraints' => [],
    ];
    $section = static fn (string $target): array => [
        'key' => 'section', 'label' => 'Section', 'position' => 20, 'type' => 'record', 'type_version' => 1,
        'required' => false, 'visibility' => 'admin', 'constraints' => ['target_structure_id' => $target],
    ];

    $sections = $workbench->createStructure($scope, [
        'structure_id' => 'workbench.sections', 'label' => 'Sections', 'fields' => [$string('title', 10)],
    ], $actor);
    $workbench->createStructure($scope, [
        'structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => [$string('title', 10), $section('workbench.sections')],
    ], $actor);
    workbenchRejects(static fn () => $workbench->createStructure($scope, [
        'structure_id' => 'workbench.broken', 'label' => 'Broken',
        'fields' => [$string('title', 10), ['constraints' => []] + $section('workbench.sections')],
    ], $actor), 'storage_schema_constraint_invalid');

    $books = $workbench->createRecord($scope, 'workbench.sections', ['title' => 'Books'], $actor);
    $games = $workbench->createRecord($scope, 'workbench.sections', ['title' => 'Games'], $actor);

    // A write names an active record of the target structure in the same scope.
    $item = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Novel', 'section' => 'record-' . strtoupper(substr($books->recordId, 7))], $actor);
    workbenchExpect(($item->values['section'] ?? null) === $books->recordId, 'record reference was not normalized');
    workbenchRejects(static fn () => $workbench->createRecord($scope, 'workbench.items',
        ['title' => 'Lost', 'section' => 'record-' . str_repeat('0', 32)], $actor), 'storage_record_reference_target_unavailable');
    $other = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Other'], $actor);
    workbenchRejects(static fn () => $workbench->createRecord($scope, 'workbench.items',
        ['title' => 'Wrong structure', 'section' => $other->recordId], $actor), 'storage_record_reference_target_unavailable');
    $workbench->createStructure('scope:tenant-beta', [
        'structure_id' => 'workbench.sections', 'label' => 'Sections', 'fields' => [$string('title', 10)],
    ], 'actor:admin:beta');
    $foreign = $workbench->createRecord('scope:tenant-beta', 'workbench.sections', ['title' => 'Foreign'], 'actor:admin:beta');
    workbenchRejects(static fn () => $workbench->createRecord($scope, 'workbench.items',
        ['title' => 'Other scope', 'section' => $foreign->recordId], $actor), 'storage_record_reference_target_unavailable');

    // An archived target cannot be chosen, but a value an update leaves alone is kept.
    $gamesArchived = $workbench->archiveRecord($scope, 'workbench.sections', $games->recordId, $games->revision, $actor);
    workbenchRejects(static fn () => $workbench->updateRecord($scope, 'workbench.items', $item->recordId, $item->revision,
        ['title' => 'Novel', 'section' => $games->recordId], $actor), 'storage_record_reference_target_unavailable');
    $booksArchived = $workbench->archiveRecord($scope, 'workbench.sections', $books->recordId, $books->revision, $actor);
    $renamed = $workbench->updateRecord($scope, 'workbench.items', $item->recordId, $item->revision,
        ['title' => 'Novel, revised', 'section' => $books->recordId], $actor);
    workbenchExpect(($renamed->values['section'] ?? null) === $books->recordId, 'an unchanged reference to an archived target was refused');

    // Filters take eq and in on the reference.
    $page = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.items',
        filters: ['section' => ['operator' => 'eq', 'value' => $books->recordId]]), $actor);
    workbenchExpect(count($page->items) === 1 && $page->items[0]->recordId === $item->recordId, 'record reference filter failed');

    // Restrict: a referenced record is not purged; an unreferenced one is.
    workbenchRejects(static fn () => $workbench->purgeRecords($scope, 'workbench.sections',
        [$books->recordId => $booksArchived->revision], $actor), 'storage_record_purge_referenced');
    $receipt = $workbench->purgeRecords($scope, 'workbench.sections', [$games->recordId => $gamesArchived->revision], $actor);
    workbenchExpect($receipt->recordIds === [$games->recordId], 'an unreferenced record was not purged');

    // Restrict: a structure whose records are referenced from another structure is not purged.
    $sectionsNow = $workbench->readStructure($scope, 'workbench.sections', $actor);
    $sectionsArchived = $workbench->archiveStructure($scope, 'workbench.sections', $sectionsNow->version, $actor);
    workbenchRejects(static fn () => $workbench->purgeStructure($scope, 'workbench.sections', $sectionsArchived->version, $actor),
        'storage_record_purge_referenced');
    workbenchExpect($sections->structureId === 'workbench.sections', 'sections structure changed identity');
} finally {
    Facade::clearResolvedInstances();
    foreach ([$referencePath, $referencePath . '-wal', $referencePath . '-shm', $referencePath . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageWorkbenchRecordReferenceTest passed.\n";
