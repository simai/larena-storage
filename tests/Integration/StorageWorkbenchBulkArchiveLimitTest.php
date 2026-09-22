<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;

require __DIR__ . '/StorageWorkbenchTest.php';

$bulkPath = tempnam(sys_get_temp_dir(), 'larena-workbench-bulk-');
if (!is_string($bulkPath)) {
    throw new RuntimeException('bulk_tempfile_failed');
}
$bulkOpened = workbenchOpen($bulkPath);
try {
    workbenchInstall();
    $workbench = workbenchRuntime($bulkOpened['connection'])['workbench'];
    $structure = $workbench->createStructure('scope:tenant-alpha', [
        'structure_id' => 'workbench.bulk_probe',
        'label' => 'Bulk probe',
        'fields' => [
            ['key' => 'name', 'label' => 'Name', 'position' => 10, 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'admin', 'constraints' => []],
        ],
    ], 'actor:admin:alpha');

    // More than the former limit of 100 records archive together in one transaction.
    $revisions = [];
    for ($index = 0; $index < 150; $index++) {
        $record = $workbench->createRecord('scope:tenant-alpha', $structure->structureId, ['name' => 'Bulk ' . $index], 'actor:admin:alpha');
        $revisions[$record->recordId] = $record->revision;
    }
    $archived = $workbench->bulkArchive('scope:tenant-alpha', $structure->structureId, $revisions, 'actor:admin:alpha');
    workbenchExpect(count($archived) === 150, 'bulk archive of 150 records did not archive all of them');
    $remaining = $workbench->listRecords(new StorageWorkbenchRecordQuery('scope:tenant-alpha', $structure->structureId, limit: 100), 'actor:admin:alpha');
    workbenchExpect($remaining->matchedCount === 0, 'archived records are still listed');

    // One bulk archive stays bounded at 1000 records.
    $tooMany = [];
    for ($index = 1; $index <= 1001; $index++) {
        $tooMany['record-' . sprintf('%032x', $index)] = 1;
    }
    workbenchRejects(
        static fn () => $workbench->bulkArchive('scope:tenant-alpha', $structure->structureId, $tooMany, 'actor:admin:alpha'),
        'storage_workbench_bulk_invalid',
    );
} finally {
    Facade::clearResolvedInstances();
    foreach ([$bulkPath, $bulkPath . '-wal', $bulkPath . '-shm', $bulkPath . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageWorkbenchBulkArchiveLimitTest passed.\n";
