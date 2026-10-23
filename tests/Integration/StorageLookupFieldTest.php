<?php

declare(strict_types=1);

use Larena\Property\Runtime\ListPropertyOptionSource;
use Larena\Property\Runtime\PropertyOptionSourceRegistry;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Runtime\StorageStructureOptionSource;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewLayoutPlanner;

require __DIR__ . '/StorageWorkbenchTest.php';

// «Выбор из справочника» in a storage (settings system stage 3): a lookup field keeps a value of a registered option
// source, checked by Property; the storages themselves are a source (storage.structures), listed per scope. A change
// that touches data is checked against the stored records before it is confirmed (checkFieldChange).

$lookupPath = tempnam(sys_get_temp_dir(), 'larena-workbench-lookup-');
if (!is_string($lookupPath)) {
    throw new RuntimeException('lookup_tempfile_failed');
}
$lookupOpened = workbenchOpen($lookupPath);
try {
    workbenchInstall();
    $connection = $lookupOpened['connection'];
    $sources = new PropertyOptionSourceRegistry();
    $sources->register('test.zones', new ListPropertyOptionSource('test.zones', static fn (?string $locale): array => [
        ['value' => 'UTC', 'label' => 'UTC'],
        ['value' => 'Europe/Moscow', 'label' => 'Moscow'],
        ['value' => 'Asia/Tokyo', 'label' => 'Tokyo'],
    ], static fn (?string $locale): string => 'Zones'));
    $properties = PropertyTypeRegistry::builtIns()->withOptionSources($sources);
    $runtime = workbenchRuntime($connection, null, properties: $properties);
    $workbench = $runtime['workbench'];
    $sources->register(StorageStructureOptionSource::KEY, new StorageStructureOptionSource($connection, $workbench));
    $actor = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';

    $field = static fn (string $key, int $position, string $type, array $constraints = [], bool $required = false): array => [
        'key' => $key, 'label' => ucfirst($key), 'position' => $position, 'type' => $type, 'type_version' => 1,
        'required' => $required, 'visibility' => 'admin', 'constraints' => $constraints,
    ];
    $descriptor = static fn (int $maxLength = 100, string $mask = ''): array => [
        'structure_id' => 'workbench.lookup_probe',
        'label' => 'Lookup probe',
        'fields' => [
            $field('title', 10, 'string', ['max_length' => $maxLength] + ($mask !== '' ? ['mask' => $mask] : []), true),
            $field('zone', 20, 'lookup', ['source' => 'test.zones']),
        ],
    ];

    // A lookup field must name a registered source.
    $unknown = $descriptor();
    $unknown['fields'][1]['constraints'] = ['source' => 'test.unknown'];
    workbenchRejects(static fn () => $workbench->createStructure($scope, $unknown, $actor), 'storage_schema_constraint_invalid');
    $missing = $descriptor();
    $missing['fields'][1]['constraints'] = [];
    workbenchRejects(static fn () => $workbench->createStructure($scope, $missing, $actor), 'storage_schema_constraint_invalid');

    $structure = $workbench->createStructure($scope, $descriptor(), $actor);
    $create = static fn (array $values) => $workbench->createRecord($scope, $structure->structureId, $values, $actor);
    $short = $create(['title' => 'Short', 'zone' => 'Europe/Moscow']);
    $create(['title' => 'Exactly ten', 'zone' => 'UTC']);
    $long = $create(['title' => str_repeat('L', 50), 'zone' => 'Asia/Tokyo']);
    workbenchExpect($short->values['zone'] === 'Europe/Moscow', 'lookup value did not round-trip');
    workbenchRejects(static fn () => $create(['title' => 'Mars', 'zone' => 'Mars/Olympus']), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Spaces', 'zone' => 'not a zone']), 'storage_record_field_invalid');

    // The list filters and sorts by a lookup value: its column is an indexed VARCHAR(128).
    $page = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, $structure->structureId,
        filters: ['zone' => ['operator' => 'in', 'values' => ['UTC', 'Asia/Tokyo']]], sort: [['field' => 'zone', 'direction' => 'asc']], limit: 10), $actor);
    workbenchExpect(array_map(static fn ($record): string => $record->values['zone'], $page->items) === ['Asia/Tokyo', 'UTC'], 'lookup filter or sort mismatch');
    workbenchExpect(StorageViewLayoutPlanner::sqlType('lookup', ['source' => 'test.zones']) === StorageViewColumn::varchar(128) && StorageViewLayoutPlanner::autoIndexed('lookup'), 'lookup column is not an indexed varchar');

    // The storages as a source: nothing listed without a scope; per scope, the person's active storages by name.
    $structures = $sources->get(StorageStructureOptionSource::KEY);
    if (!$structures instanceof StorageStructureOptionSource) {
        throw new RuntimeException('storage source missing');
    }
    workbenchExpect($structures->options(null, 10) === [], 'an unscoped storage source listed storages');
    workbenchExpect($structures->contains('workbench.lookup_probe') && !$structures->contains('workbench.missing') && !$structures->contains('Bad id'), 'storage source contains mismatch');
    workbenchExpect($structures->label('workbench.lookup_probe') === 'Lookup probe' && $structures->label('workbench.missing') === null, 'storage source label mismatch');
    $alpha = $structures->withinScope($scope, $actor);
    workbenchExpect($alpha->options('probe', 10) === [['value' => 'workbench.lookup_probe', 'label' => 'Lookup probe']], 'scoped storage list mismatch');
    workbenchExpect($alpha->options('nothing like it', 10) === [], 'scoped storage search mismatch');
    workbenchExpect($structures->withinScope('scope:tenant-beta', 'actor:admin:beta')->options(null, 10) === [], 'another scope saw the storage');
    workbenchExpect(!$structures->withinScope('scope:tenant-beta', 'actor:admin:beta')->contains('workbench.lookup_probe'), 'another scope contains the storage');

    // The check before a change that touches data: which fields every record must pass, and how many would not.
    $current = $workbench->readStructure($scope, $structure->structureId, $actor);
    $check = $workbench->checkFieldChange($scope, $structure->structureId, $descriptor(20), $actor);
    workbenchExpect($check['data_changing'] === true && $check['fields'] === ['title'] && $check['total'] === 3 && $check['checked'] === 3, 'field change check scope: ' . json_encode($check));
    workbenchExpect($check['invalid'] === 1 && $check['complete'] === true && $check['samples'] === [$long->recordId], 'field change check counts: ' . json_encode($check));
    $partial = $workbench->checkFieldChange($scope, $structure->structureId, $descriptor(5), $actor, 2);
    workbenchExpect($partial['checked'] === 2 && $partial['complete'] === false && $partial['total'] === 3, 'a limited check did not stop: ' . json_encode($partial));
    // A raised limit, or a mask (form only, free after creation), touches no data.
    workbenchExpect($workbench->checkFieldChange($scope, $structure->structureId, $descriptor(200), $actor)['invalid'] === 0, 'a longer limit refused records');
    $free = $workbench->checkFieldChange($scope, $structure->structureId, $descriptor(100, '000'), $actor);
    workbenchExpect($free['data_changing'] === false && $free['fields'] === [] && $free['checked'] === 0, 'a mask change was data changing: ' . json_encode($free));
    // The check writes nothing; the source of a lookup cannot change (locked) and needs every record checked.
    workbenchExpect($workbench->readStructure($scope, $structure->structureId, $actor)->version === $current->version, 'the check changed the structure');
    $resourced = $descriptor();
    $resourced['fields'][1]['constraints'] = ['source' => StorageStructureOptionSource::KEY];
    $moved = $workbench->checkFieldChange($scope, $structure->structureId, $resourced, $actor);
    workbenchExpect($moved['fields'] === ['zone'] && $moved['invalid'] === 3, 'a source change was not checked: ' . json_encode($moved));

    // The change itself: an update refuses a change that touches data; the migration applies one every record takes,
    // and refuses one some record does not.
    workbenchRejects(static fn () => $workbench->updateStructure($scope, $structure->structureId, $current->version, $descriptor(60), $actor), 'storage_workbench_structure_field_changed');
    workbenchRejects(static fn () => $workbench->migrateStructure($scope, $structure->structureId, $current->version, $descriptor(20), $actor), 'storage_schema_migration_record_unconvertible');
    $migrated = $workbench->migrateStructure($scope, $structure->structureId, $current->version, $descriptor(60), $actor);
    workbenchExpect($migrated->fields[0]['constraints']['max_length'] === 60, 'the migration did not switch the field');
    $masked = $workbench->updateStructure($scope, $structure->structureId, $migrated->version, $descriptor(60, '000'), $actor);
    workbenchExpect(($masked->fields[0]['constraints']['mask'] ?? null) === '000', 'a mask change was not applied at once');
} finally {
    @unlink($lookupPath);
}

echo "StorageLookupFieldTest passed.\n";
