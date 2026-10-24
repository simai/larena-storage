<?php

declare(strict_types=1);

use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewLayoutPlanner;

require __DIR__ . '/StorageWorkbenchTest.php';

// The common property types standard in a storage (owner decision 2026-10-24): colour, phone, URL and link fields keep
// Property's canonical value, whatever form a person typed, and a list filters by colour, phone and URL.

$commonPath = tempnam(sys_get_temp_dir(), 'larena-workbench-common-');
if (!is_string($commonPath)) {
    throw new RuntimeException('common_types_tempfile_failed');
}
$commonOpened = workbenchOpen($commonPath);
try {
    workbenchInstall();
    $runtime = workbenchRuntime($commonOpened['connection']);
    $workbench = $runtime['workbench'];
    $actor = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';

    $field = static fn (string $key, int $position, string $type, array $constraints = []): array => [
        'key' => $key, 'label' => ucfirst($key), 'position' => $position, 'type' => $type, 'type_version' => 1,
        'required' => false, 'visibility' => 'admin', 'constraints' => $constraints,
    ];
    $structure = $workbench->createStructure($scope, [
        'structure_id' => 'workbench.common_probe',
        'label' => 'Common types probe',
        'fields' => [
            $field('title', 10, 'string', ['max_length' => 100]),
            $field('accent', 20, 'color'),
            $field('phone', 30, 'phone', ['default_country' => 'RU']),
            $field('site', 40, 'url'),
            $field('more', 50, 'link'),
        ],
    ], $actor);
    workbenchRejects(static fn () => $workbench->createStructure($scope, [
        'structure_id' => 'workbench.common_bad', 'label' => 'Bad', 'fields' => [$field('phone', 10, 'phone', ['default_country' => 'ru'])],
    ], $actor), 'storage_schema_constraint_invalid');

    $create = static fn (array $values) => $workbench->createRecord($scope, $structure->structureId, $values, $actor);
    $office = $create(['title' => 'Office', 'accent' => '#f9433a', 'phone' => '8 (347) 246-85-00', 'site' => 'https://simai.ru/',
        'more' => ['href' => '/about/', 'text' => 'О нас']]);
    $create(['title' => 'Desk', 'accent' => '#00FF00', 'phone' => '+7 495 000-00-01#12', 'site' => 'https://example.test/']);
    workbenchExpect($office->values['accent'] === '#F9433A' && $office->values['phone'] === '+73472468500', 'typed colour or phone not stored canonical');
    workbenchExpect($office->values['more'] === ['href' => '/about/', 'text' => 'О нас'], 'link did not round-trip');
    $read = $workbench->readRecord($scope, $structure->structureId, $office->recordId, $actor);
    workbenchExpect($read->values['site'] === 'https://simai.ru/' && $read->values['more']['href'] === '/about/', 'read back mismatch');
    workbenchRejects(static fn () => $create(['title' => 'Bad colour', 'accent' => 'red']), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Bad phone', 'phone' => '12']), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Bad site', 'site' => 'javascript:alert(1)']), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Bad link', 'more' => ['href' => '//evil.example/']]), 'storage_record_field_invalid');

    // A filter value is normalized by Property like a stored one: the phone as typed finds the record.
    $find = static fn (array $filters): array => array_map(static fn ($record): string => $record->values['title'],
        $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, $structure->structureId, filters: $filters, limit: 10), $actor)->items);
    workbenchExpect($find(['phone' => ['operator' => 'eq', 'value' => '+7 (347) 246-85-00']]) === ['Office'], 'phone filter mismatch');
    workbenchExpect($find(['accent' => ['operator' => 'in', 'values' => ['#00ff00']]]) === ['Desk'], 'colour filter mismatch');
    workbenchExpect($find(['site' => ['operator' => 'eq', 'value' => 'https://simai.ru/']]) === ['Office'], 'url filter mismatch');
    workbenchRejects(static fn () => $find(['more' => ['operator' => 'eq', 'value' => '/about/']]), 'storage_query_filter_operator_unsupported');

    // Columns: a colour and a phone fit a short VARCHAR; a URL and a link are long text.
    workbenchExpect(StorageViewLayoutPlanner::sqlType('color', []) === StorageViewColumn::varchar(9)
        && StorageViewLayoutPlanner::sqlType('phone', []) === StorageViewColumn::varchar(31)
        && StorageViewLayoutPlanner::sqlType('url', []) === StorageViewColumn::SQL_TEXT
        && StorageViewLayoutPlanner::sqlType('link', []) === StorageViewColumn::SQL_TEXT, 'common type columns mismatch');
} finally {
    @unlink($commonPath);
}

echo "StorageCommonTypesFieldTest passed.\n";
