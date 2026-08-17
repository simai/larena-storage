<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Tests\Support\MinimalCmsHierarchyFixtures;

require __DIR__ . '/StorageWorkbenchTest.php';

/** @param list<array<string, mixed>> $records */
function hierarchyCreateRecords(object $workbench, string $structureId, array $records): void
{
    foreach ($records as $index => $record) {
        $workbench->createRecord(
            'scope:minimal-cms',
            $structureId,
            $record,
            'actor:admin:minimal-cms',
            $structureId . '-record-' . $index,
        );
    }
}

/** @return list<array<string, mixed>> */
function hierarchyRoundTrip(object $workbench, string $structureId): array
{
    $page = $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:minimal-cms',
        $structureId,
        sort: [['field' => 'code', 'direction' => 'asc']],
        limit: 100,
    ), 'actor:admin:minimal-cms');

    return array_map(static fn ($record): array => $record->values, $page->items);
}

/** @param list<array<string, mixed>> $records */
function hierarchyCanonical(array $records): array
{
    usort($records, static fn (array $left, array $right): int => $left['code'] <=> $right['code']);

    return $records;
}

$path = tempnam(sys_get_temp_dir(), 'larena-hierarchy-');
if (!is_string($path)) {
    throw new RuntimeException('hierarchy_tempfile_failed');
}

$opened = workbenchOpen($path);
try {
    workbenchInstall();
    $runtime = workbenchRuntime($opened['connection'], new WorkbenchScopeProvider([
        'actor:admin:minimal-cms' => 'scope:minimal-cms',
    ]));
    $workbench = $runtime['workbench'];

    $site = $workbench->createStructure('scope:minimal-cms', MinimalCmsHierarchyFixtures::siteStructure(), 'actor:admin:minimal-cms');
    $organization = $workbench->createStructure('scope:minimal-cms', MinimalCmsHierarchyFixtures::organizationStructure(), 'actor:admin:minimal-cms');
    workbenchExpect($site->schema->schemaId !== $organization->schema->schemaId, 'named hierarchies share a schema');

    hierarchyCreateRecords($workbench, $site->structureId, MinimalCmsHierarchyFixtures::siteRecords());
    hierarchyCreateRecords($workbench, $organization->structureId, MinimalCmsHierarchyFixtures::organizationRecords());

    workbenchExpect(
        workbenchCanonicalJson(hierarchyRoundTrip($workbench, $site->structureId)) === workbenchCanonicalJson(hierarchyCanonical(MinimalCmsHierarchyFixtures::siteRecords())),
        'site hierarchy round-trip mismatch',
    );
    workbenchExpect(
        workbenchCanonicalJson(hierarchyRoundTrip($workbench, $organization->structureId)) === workbenchCanonicalJson(hierarchyCanonical(MinimalCmsHierarchyFixtures::organizationRecords())),
        'organization hierarchy round-trip mismatch',
    );

    $tables = array_map(
        static fn ($row): string => (string) $row->name,
        $opened['connection']->select("select name from sqlite_master where type = 'table' order by name"),
    );
    foreach ($tables as $table) {
        workbenchExpect(!str_contains($table, 'content'), 'parallel Content table entered the hierarchy fixture: ' . $table);
    }
} finally {
    Facade::clearResolvedInstances();
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "MinimalCmsHierarchyFixtureTest passed.\n";
