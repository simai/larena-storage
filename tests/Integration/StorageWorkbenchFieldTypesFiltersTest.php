<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;

require __DIR__ . '/StorageWorkbenchTest.php';

/** @return list<array<string, mixed>> */
function fieldTypesStatusOptions(bool $reorderedKeys = false): array
{
    $options = [
        ['value' => 'draft', 'label' => 'Draft', 'color' => 'neutral'],
        ['value' => 'review', 'label' => 'Review', 'color' => '#1A2B3C', 'icon' => 'rate_review'],
        ['value' => 'done', 'label' => 'Done', 'color' => 'success'],
    ];
    if ($reorderedKeys) {
        $options = array_map(static fn (array $option): array => array_reverse($option, true), $options);
    }

    return $options;
}

/** @return list<array<string, mixed>> */
function fieldTypesTagOptions(): array
{
    return [
        ['value' => 'red', 'label' => 'Red'],
        ['value' => 'green', 'label' => 'Green'],
        ['value' => 'blue', 'label' => 'Blue'],
        ['value' => 'black', 'label' => 'Black'],
    ];
}

/** @return array<string, mixed> */
function fieldTypesDescriptor(): array
{
    $field = static fn (string $key, int $position, string $type, bool $required, array $constraints = []): array => [
        'key' => $key,
        'label' => ucfirst($key),
        'position' => $position,
        'type' => $type,
        'type_version' => 1,
        'required' => $required,
        'visibility' => 'admin',
        'constraints' => $constraints,
    ];

    return [
        'structure_id' => 'workbench.field_types_probe',
        'label' => 'Field types probe',
        'fields' => [
            $field('title', 10, 'string', true, ['min_length' => 3, 'max_length' => 100]),
            $field('body', 20, 'text', false),
            $field('qty', 30, 'integer', false, ['min' => 0, 'max' => 1000]),
            $field('price', 40, 'number', false),
            $field('day', 50, 'date', false),
            $field('starts_at', 60, 'datetime', false, ['min' => '2026-01-01T00:00']),
            $field('active', 70, 'boolean', false),
            $field('status', 80, 'choice', false, ['options' => fieldTypesStatusOptions()]),
            $field('tags', 90, 'choices', true, ['options' => fieldTypesTagOptions(), 'max_items' => 3]),
            $field('owner', 100, 'user', false),
            $field('document', 110, 'file', false),
        ],
    ];
}

/** @param array<array-key, mixed> $filters
 * @return list<string>
 */
function fieldTypesTitles(object $workbench, array $filters): array
{
    $page = $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha',
        'workbench.field_types_probe',
        filters: $filters,
        sort: [['field' => 'title', 'direction' => 'asc']],
        limit: 100,
    ), 'actor:admin:alpha');

    return array_map(static fn ($record): string => $record->values['title'], $page->items);
}

function fieldTypesInsertRawRecords(Connection $connection, string $schemaId, int $from, int $count): void
{
    $normalizer = new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns());
    $createdAt = '2026-09-22 00:00:00';
    $connection->transaction(static function () use ($connection, $normalizer, $schemaId, $from, $count, $createdAt): void {
        $heads = [];
        $versions = [];
        for ($index = $from; $index < $from + $count; $index++) {
            $recordId = 'record-' . sprintf('%032x', $index + 1);
            $ownerRef = 'workbench.record:' . sprintf('%032x', $index + 1);
            $valuesJson = $normalizer->canonicalJson([
                'larena_record_state' => 'active',
                'larena_scope_ref' => 'scope:tenant-alpha',
                'name' => 'Scan ' . $index,
            ]);
            $hash = hash('sha256', $valuesJson);
            $heads[] = ['record_id' => $recordId, 'schema_id' => $schemaId, 'owner_ref' => $ownerRef, 'current_revision' => 1, 'current_schema_version' => 1, 'current_hash' => $hash, 'created_at' => $createdAt, 'updated_at' => $createdAt];
            $versions[] = ['schema_id' => $schemaId, 'record_id' => $recordId, 'revision' => 1, 'owner_ref' => $ownerRef, 'schema_version' => 1, 'values_json' => $valuesJson, 'content_hash' => $hash, 'operation' => 'create', 'created_by' => 'actor:admin:alpha', 'correlation_id' => null, 'created_at' => $createdAt];
            if (count($heads) === 50) {
                $connection->table('larena_storage_records')->insert($heads);
                $connection->table('larena_storage_record_versions')->insert($versions);
                $heads = [];
                $versions = [];
            }
        }
        if ($heads !== []) {
            $connection->table('larena_storage_records')->insert($heads);
            $connection->table('larena_storage_record_versions')->insert($versions);
        }
    });
}

$fieldTypesPath = tempnam(sys_get_temp_dir(), 'larena-workbench-field-types-');
if (!is_string($fieldTypesPath)) {
    throw new RuntimeException('field_types_tempfile_failed');
}
$fieldTypesOpened = workbenchOpen($fieldTypesPath);
try {
    workbenchInstall();
    $connection = $fieldTypesOpened['connection'];
    $workbench = workbenchRuntime($connection)['workbench'];

    // Options constraints: only choice/choices may carry the declared option list.
    $emptyState = workbenchState($connection);
    foreach ([
        ['status', []],
        ['status', ['options' => 'draft']],
        ['status', ['options' => [['value' => 'draft', 'label' => ['nested']]]]],
        ['status', ['options' => [['draft', 'Draft']]]],
        ['status', ['options' => [['value' => 'Draft', 'label' => 'Draft']]]],
        ['status', ['options' => [['value' => 'draft', 'label' => '<b>Draft</b>']]]],
        ['status', ['options' => fieldTypesStatusOptions(), 'max_items' => 2]],
        ['tags', ['options' => fieldTypesTagOptions(), 'max_items' => 0]],
        ['title', ['options' => fieldTypesStatusOptions()]],
        ['starts_at', ['min' => '2026-02-30T00:00']],
        ['owner', ['provider' => 'admin']],
    ] as [$fieldKey, $constraints]) {
        $descriptor = fieldTypesDescriptor();
        foreach ($descriptor['fields'] as &$candidate) {
            if ($candidate['key'] === $fieldKey) {
                $candidate['constraints'] = $constraints;
            }
        }
        unset($candidate);
        workbenchRejects(
            static fn () => $workbench->createStructure('scope:tenant-alpha', $descriptor, 'actor:admin:alpha'),
            'storage_schema_constraint_invalid',
        );
    }
    workbenchExpect(workbenchState($connection) === $emptyState, 'rejected option constraints mutated state');

    // Canonical schema digests do not depend on option key order, but keep the declared option order.
    $normalizer = new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns());
    $definition = static fn (array $options): array => [
        'schema_id' => 'probe.options',
        'owner_package' => 'larena/storage',
        'fields' => [['key' => 'status', 'type' => 'choice', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => ['options' => $options]]],
    ];
    $canonical = $normalizer->canonicalJson($normalizer->normalize($definition(fieldTypesStatusOptions())));
    workbenchExpect(
        $canonical === $normalizer->canonicalJson($normalizer->normalize($definition(fieldTypesStatusOptions(true)))),
        'option key order changed the canonical schema digest input',
    );
    workbenchExpect(
        str_contains($canonical, '{"color":"neutral","label":"Draft","value":"draft"},{"color":"#1A2B3C","icon":"rate_review","label":"Review","value":"review"}'),
        'canonical options lost declared order or key canonicalization',
    );
    workbenchExpect(
        $canonical !== $normalizer->canonicalJson($normalizer->normalize($definition(array_reverse(fieldTypesStatusOptions())))),
        'option order is semantic and must change the digest input',
    );

    $structure = $workbench->createStructure('scope:tenant-alpha', fieldTypesDescriptor(), 'actor:admin:alpha');
    $again = $workbench->readStructure('scope:tenant-alpha', $structure->structureId, 'actor:admin:alpha');
    workbenchExpect($again->descriptorHash === $structure->descriptorHash, 'option structure digest is not stable on readback');
    $statusField = array_values(array_filter($again->fields, static fn (array $field): bool => $field['key'] === 'status'))[0];
    workbenchExpect(
        array_column($statusField['constraints']['options'], 'value') === ['draft', 'review', 'done'],
        'stored options lost declared order',
    );
    $startsField = array_values(array_filter($again->fields, static fn (array $field): bool => $field['key'] === 'starts_at'))[0];
    workbenchExpect($startsField['constraints'] === ['min' => '2026-01-01T00:00:00'], 'datetime bound was not stored canonically');
    $datetimeDefinition = static fn (string $minimum): array => [
        'schema_id' => 'probe.datetime',
        'owner_package' => 'larena/storage',
        'fields' => [['key' => 'starts_at', 'type' => 'datetime', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => ['min' => $minimum, 'max' => '2026-12-31T18:00']]],
    ];
    workbenchExpect(
        $normalizer->canonicalJson($normalizer->normalize($datetimeDefinition('2026-01-01T00:00')))
            === $normalizer->canonicalJson($normalizer->normalize($datetimeDefinition('2026-01-01T00:00:00'))),
        'datetime bound input format changed the schema digest input',
    );

    // Record values: choices are JSON arrays in declared option order.
    $create = static fn (array $values) => $workbench->createRecord('scope:tenant-alpha', $structure->structureId, $values, 'actor:admin:alpha');
    $alpha = $create([
        'title' => 'Alpha', 'body' => "First line\r\nSecond", 'qty' => 5, 'price' => '10.50', 'day' => '2026-03-01',
        'starts_at' => '2026-03-01T09:30', 'active' => true, 'status' => 'draft', 'tags' => ['blue', 'red'],
        'owner' => 'user:admin:1', 'document' => '018f4f4c-706e-7b1f-9a3e-c93b5656a6f0',
    ]);
    $bravo = $create([
        'title' => 'Bravo', 'body' => 'Plain text', 'qty' => 50, 'price' => '9.999', 'day' => '2026-06-15',
        'starts_at' => '2026-06-15T18:00:00', 'active' => false, 'status' => 'review', 'tags' => ['green'],
        'owner' => 'user:admin:2',
    ]);
    $charlie = $create([
        'title' => 'Charlie beta', 'qty' => 500, 'price' => '100', 'day' => '2026-12-31',
        'starts_at' => '2026-12-31T23:59:59', 'active' => true, 'status' => 'done', 'tags' => ['black', 'green', 'red'],
    ]);
    $delta = $create(['title' => 'Delta', 'tags' => ['blue']]);
    workbenchExpect($alpha->values['tags'] === ['red', 'blue'], 'choices value was not stored in declared option order');
    workbenchExpect($charlie->values['tags'] === ['red', 'green', 'black'], 'choices canonical order mismatch');
    workbenchExpect($alpha->values['starts_at'] === '2026-03-01T09:30:00', 'datetime value was not canonicalized');
    workbenchExpect($alpha->values['owner'] === 'user:admin:1', 'user reference round-trip mismatch');
    $alphaRead = $workbench->readRecord('scope:tenant-alpha', $structure->structureId, $alpha->recordId, 'actor:admin:alpha');
    workbenchExpect(workbenchCanonicalJson($alphaRead->values) === workbenchCanonicalJson($alpha->values) && $alphaRead->contentHash === $alpha->contentHash, 'choices record did not round-trip durably');
    $storedJson = (string) $connection->table('larena_storage_record_versions')->where('record_id', $alpha->recordId)->value('values_json');
    workbenchExpect(str_contains($storedJson, '"tags":["red","blue"]'), 'choices value is not a JSON array in record JSON');

    $beforeInvalidRecords = workbenchState($connection);
    foreach ([
        [['title' => 'Empty tags', 'tags' => []], 'storage_record_required_field_missing'],
        [['title' => 'Missing tags'], 'storage_record_required_field_missing'],
        [['title' => 'Too many', 'tags' => ['red', 'green', 'blue', 'black']], 'storage_record_field_invalid'],
        [['title' => 'Duplicate', 'tags' => ['red', 'red']], 'storage_record_field_invalid'],
        [['title' => 'Unknown tag', 'tags' => ['pink']], 'storage_record_field_invalid'],
        [['title' => 'Scalar tags', 'tags' => 'red'], 'storage_record_field_invalid'],
        [['title' => 'Unknown status', 'tags' => ['red'], 'status' => 'archived'], 'storage_record_field_invalid'],
        [['title' => 'Bad owner', 'tags' => ['red'], 'owner' => 'user:admin:0'], 'storage_record_field_invalid'],
        [['title' => 'Too early', 'tags' => ['red'], 'starts_at' => '2025-12-31T23:59'], 'storage_record_field_invalid'],
        [['title' => 'Bad clock', 'tags' => ['red'], 'starts_at' => '2026-03-01T25:00'], 'storage_record_field_invalid'],
    ] as [$values, $reason]) {
        workbenchRejects(static fn () => $create($values), $reason);
    }
    workbenchExpect(workbenchState($connection) === $beforeInvalidRecords, 'rejected field-type records mutated state');

    // Operators.
    $expectTitles = static function (array $filters, array $expected, string $message) use ($workbench): void {
        $actual = fieldTypesTitles($workbench, $filters);
        workbenchExpect($actual === $expected, $message . ': got ' . json_encode($actual));
    };
    $expectTitles(['title' => ['operator' => 'eq', 'value' => 'Bravo']], ['Bravo'], 'string eq');
    $expectTitles(['title' => ['operator' => 'in', 'values' => ['Bravo', 'Delta', 'Bravo']]], ['Bravo', 'Delta'], 'string in');
    $expectTitles(['title' => ['operator' => 'contains', 'value' => 'ETA']], ['Charlie beta'], 'string contains is case-insensitive');
    $expectTitles(['title' => ['operator' => 'contains', 'value' => 'a']], ['Alpha', 'Bravo', 'Charlie beta', 'Delta'], 'short contains ignores min_length');
    $expectTitles(['title' => ['operator' => 'starts_with', 'value' => 'ch']], ['Charlie beta'], 'string starts_with');
    $expectTitles(['body' => ['operator' => 'contains', 'value' => "line\r\nsecond"]], ['Alpha'], 'text contains uses normalized line endings');
    $expectTitles(['body' => ['operator' => 'starts_with', 'value' => 'plain']], ['Bravo'], 'text starts_with');
    $expectTitles(['qty' => ['operator' => 'eq', 'value' => '50']], ['Bravo'], 'integer eq normalizes digits');
    $expectTitles(['qty' => ['operator' => 'gt', 'value' => 5]], ['Bravo', 'Charlie beta'], 'integer gt');
    $expectTitles(['qty' => ['operator' => 'gte', 'value' => 5]], ['Alpha', 'Bravo', 'Charlie beta'], 'integer gte');
    $expectTitles(['qty' => ['operator' => 'lt', 'value' => 50]], ['Alpha'], 'integer lt compares numerically');
    $expectTitles(['qty' => ['operator' => 'lte', 'value' => -1]], [], 'integer range bound outside constraints is allowed');
    $expectTitles(['qty' => ['operator' => 'between', 'values' => [5, 50]]], ['Alpha', 'Bravo'], 'integer between inclusive');
    $expectTitles(['qty' => ['operator' => 'in', 'values' => [500, '5']]], ['Alpha', 'Charlie beta'], 'integer in');
    $expectTitles(['price' => ['operator' => 'gt', 'value' => '10']], ['Alpha', 'Charlie beta'], 'number gt compares as decimals');
    $expectTitles(['price' => ['operator' => 'lt', 'value' => '10.5']], ['Bravo'], 'number lt');
    $expectTitles(['price' => ['operator' => 'eq', 'value' => '10.5000']], ['Alpha'], 'number eq canonicalizes');
    $expectTitles(['price' => ['operator' => 'between', 'values' => ['9.999', 100]]], ['Alpha', 'Bravo', 'Charlie beta'], 'number between');
    $expectTitles(['day' => ['operator' => 'gte', 'value' => '2026-06-15']], ['Bravo', 'Charlie beta'], 'date gte');
    $expectTitles(['day' => ['operator' => 'between', 'values' => ['2026-01-01', '2026-06-15']]], ['Alpha', 'Bravo'], 'date between');
    $expectTitles(['starts_at' => ['operator' => 'lt', 'value' => '2026-06-15T18:00']], ['Alpha'], 'datetime lt with short form');
    $expectTitles(['starts_at' => ['operator' => 'lte', 'value' => '2026-06-15T18:00']], ['Alpha', 'Bravo'], 'datetime lte canonical seconds');
    $expectTitles(['starts_at' => ['operator' => 'eq', 'value' => '2026-03-01T09:30']], ['Alpha'], 'datetime eq canonicalizes');
    $expectTitles(['starts_at' => ['operator' => 'between', 'values' => ['2026-06-15T18:00:00', '2026-12-31T23:59:59']]], ['Bravo', 'Charlie beta'], 'datetime between');
    $expectTitles(['active' => ['operator' => 'eq', 'value' => 'true']], ['Alpha', 'Charlie beta'], 'boolean eq');
    $expectTitles(['status' => ['operator' => 'eq', 'value' => 'review']], ['Bravo'], 'choice eq');
    $expectTitles(['status' => ['operator' => 'in', 'values' => ['done', 'draft']]], ['Alpha', 'Charlie beta'], 'choice in');
    $expectTitles(['tags' => ['operator' => 'eq', 'value' => 'green']], ['Bravo', 'Charlie beta'], 'choices eq means contains');
    $expectTitles(['tags' => ['operator' => 'in', 'values' => ['blue', 'black']]], ['Alpha', 'Charlie beta', 'Delta'], 'choices in means overlaps');
    $expectTitles(['tags' => ['operator' => 'in', 'values' => ['black', 'blue', 'green', 'red', 'black']]], ['Alpha', 'Bravo', 'Charlie beta', 'Delta'], 'choices in de-duplicates and ignores max_items');
    $expectTitles(['owner' => ['operator' => 'eq', 'value' => 'user:admin:2']], ['Bravo'], 'user eq');
    $expectTitles(['owner' => ['operator' => 'in', 'values' => ['user:admin:1', 'user:admin:2']]], ['Alpha', 'Bravo'], 'user in');
    $expectTitles(['document' => ['operator' => 'eq', 'value' => '018F4F4C-706E-7B1F-9A3E-C93B5656A6F0']], ['Alpha'], 'file eq stays supported');
    $expectTitles([
        'qty' => ['operator' => 'gte', 'value' => 5],
        'tags' => ['operator' => 'eq', 'value' => 'red'],
        'title' => ['operator' => 'contains', 'value' => 'alp'],
    ], ['Alpha'], 'filters combine with AND');

    $filterRejects = static function (array $filters, string $reason) use ($workbench): void {
        workbenchRejects(
            static fn () => fieldTypesTitles($workbench, $filters),
            $reason,
        );
    };
    foreach ([
        ['active' => ['operator' => 'in', 'values' => [true]]],
        ['title' => ['operator' => 'gt', 'value' => 'A']],
        ['qty' => ['operator' => 'contains', 'value' => '5']],
        ['status' => ['operator' => 'contains', 'value' => 'dr']],
        ['tags' => ['operator' => 'between', 'values' => ['red', 'blue']]],
        ['owner' => ['operator' => 'starts_with', 'value' => 'user:']],
        ['day' => ['operator' => 'starts_with', 'value' => '2026']],
        ['title' => ['operator' => 'ne', 'value' => 'Alpha']],
        ['title' => ['operator' => 'EQ', 'value' => 'Alpha']],
    ] as $filters) {
        $filterRejects($filters, 'storage_query_filter_operator_unsupported');
    }
    foreach ([
        ['title' => ['operator' => 'in', 'value' => 'Alpha']],
        ['title' => ['operator' => 'eq', 'values' => ['Alpha']]],
        ['title' => ['operator' => 'in', 'values' => []]],
        ['title' => ['operator' => 'in', 'values' => ['a' => 'Alpha']]],
        ['title' => ['operator' => 'in', 'values' => array_fill(0, 101, 'Alpha')]],
        ['title' => ['operator' => 'contains', 'value' => '']],
        ['title' => ['operator' => 'contains', 'value' => str_repeat('a', 1001)]],
        ['title' => ['operator' => 'eq', 'value' => 'Al']],
        ['qty' => ['operator' => 'between', 'values' => [50]]],
        ['qty' => ['operator' => 'between', 'values' => [50, 5]]],
        ['qty' => ['operator' => 'gt', 'value' => '5.5']],
        ['price' => ['operator' => 'gt', 'value' => '1e3']],
        ['starts_at' => ['operator' => 'gt', 'value' => '2026-03-01 09:30']],
        ['status' => ['operator' => 'eq', 'value' => 'archived']],
        ['tags' => ['operator' => 'eq', 'value' => ['red']]],
        ['tags' => ['operator' => 'in', 'values' => ['pink']]],
        ['tags' => ['operator' => 'in', 'values' => array_fill(0, 101, 'red')]],
        ['owner' => ['operator' => 'eq', 'value' => 'admin:1']],
        ['missing' => ['operator' => 'eq', 'value' => 'x']],
        ['title' => ['operator' => 'eq', 'value' => 'Alpha', 'extra' => true]],
    ] as $filters) {
        $filterRejects($filters, 'storage_workbench_record_filter_invalid');
    }
    $tooMany = [];
    foreach (['title', 'body', 'qty', 'price', 'day', 'starts_at', 'active', 'status', 'tags'] as $fieldKey) {
        $tooMany[$fieldKey] = ['operator' => 'eq', 'value' => 'x'];
    }
    $filterRejects($tooMany, 'storage_workbench_record_query_too_large');
    $expectTitles(['title' => ['operator' => 'eq', 'value' => 'Alpha']], ['Alpha'], 'search/filter state leaked between queries');

    // Search stays string/text only: choice, user and choices values are not searched.
    $searchPage = $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha',
        $structure->structureId,
        search: 'review',
        limit: 100,
    ), 'actor:admin:alpha');
    workbenchExpect($searchPage->items === [], 'search matched a non string/text field');

    // Continuations bind the normalized operator query.
    $firstPage = $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha',
        $structure->structureId,
        filters: ['qty' => ['operator' => 'gte', 'value' => 5]],
        sort: [['field' => 'qty', 'direction' => 'asc']],
        limit: 1,
    ), 'actor:admin:alpha');
    workbenchExpect($firstPage->matchedCount === 3 && $firstPage->continuation !== null, 'operator pagination mismatch');
    workbenchRejects(
        static fn () => $workbench->listRecords(new StorageWorkbenchRecordQuery(
            'scope:tenant-alpha',
            $structure->structureId,
            filters: ['qty' => ['operator' => 'gt', 'value' => 5]],
            sort: [['field' => 'qty', 'direction' => 'asc']],
            limit: 1,
            continuation: $firstPage->continuation,
        ), 'actor:admin:alpha'),
        'storage_workbench_record_continuation_invalid',
    );
    unset($bravo, $delta);

    // Scan limit is 5000 current records per structure; page numbers follow the scan limit.
    $scan = $workbench->createStructure('scope:tenant-alpha', [
        'structure_id' => 'workbench.scan_probe',
        'label' => 'Scan probe',
        'fields' => [
            ['key' => 'name', 'label' => 'Name', 'position' => 10, 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'admin', 'constraints' => []],
        ],
    ], 'actor:admin:alpha');
    fieldTypesInsertRawRecords($connection, $scan->schema->schemaId, 0, 5000);
    $scanQuery = static fn (?int $page = null, array $filters = []): StorageWorkbenchRecordQuery => new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha',
        $scan->structureId,
        filters: $filters,
        limit: 100,
        page: $page,
    );
    $memoryBefore = memory_get_peak_usage(true);
    $fullScan = $workbench->listRecords($scanQuery(), 'actor:admin:alpha');
    workbenchExpect($fullScan->matchedCount === 5000 && count($fullScan->items) === 100, 'scan of 5000 records did not complete');
    $lastPage = $workbench->listRecords($scanQuery(50), 'actor:admin:alpha');
    workbenchExpect(count($lastPage->items) === 100 && $lastPage->continuation === null, 'last scan page mismatch');
    workbenchRejects(static fn () => $workbench->listRecords($scanQuery(51), 'actor:admin:alpha'), 'storage_workbench_record_page_invalid');
    $filtered = $workbench->listRecords($scanQuery(null, ['name' => ['operator' => 'starts_with', 'value' => 'scan 499']]), 'actor:admin:alpha');
    workbenchExpect($filtered->matchedCount === 11, 'filtered full scan mismatch');
    workbenchExpect(memory_get_peak_usage(true) - $memoryBefore < 128 * 1024 * 1024, 'full scan memory exceeded budget');
    fieldTypesInsertRawRecords($connection, $scan->schema->schemaId, 5000, 1);
    workbenchRejects(static fn () => $workbench->listRecords($scanQuery(), 'actor:admin:alpha'), 'storage_workbench_record_scan_limit_exceeded');
} finally {
    Facade::clearResolvedInstances();
    foreach ([$fieldTypesPath, $fieldTypesPath . '-wal', $fieldTypesPath . '-shm', $fieldTypesPath . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageWorkbenchFieldTypesFiltersTest passed.\n";
