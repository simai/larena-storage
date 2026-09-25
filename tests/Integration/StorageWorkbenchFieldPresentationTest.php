<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use Larena\Property\Contracts\PropertyFieldDefinition;
use Larena\Property\Contracts\PropertyReferenceLabelResolver;
use Larena\Property\Contracts\PropertyRenderContext;
use Larena\Property\Runtime\PropertyDisplayProjector;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;

require __DIR__.'/StorageWorkbenchTest.php';

$path = tempnam(sys_get_temp_dir(), 'larena-field-presentation-');
if (!is_string($path)) throw new RuntimeException('field_presentation_tempfile_failed');

try {
    $opened = workbenchOpen($path);
    workbenchInstall();
    $workbench = workbenchRuntime($opened['connection'])['workbench'];
    $operators = $workbench->filterOperators();
    workbenchExpect($operators['string'] === ['eq', 'in', 'contains', 'starts_with'], 'string operators differ from executable rules');
    workbenchExpect($operators['number'] === ['eq', 'in', 'gt', 'gte', 'lt', 'lte', 'between'], 'number operators differ from executable rules');
    workbenchExpect($operators['relation'] === ['eq', 'in'], 'relation operators differ from executable rules');
    workbenchExpect(!in_array('today', $operators['date'], true), 'host-only relative period leaked into Storage operators');

    $options = ['options' => [
        ['value' => 'draft', 'label' => 'Черновик'],
        ['value' => 'done', 'label' => 'Готово'],
    ]];
    $field = static fn (string $key, string $type, int $position, array $constraints = [], int $version = 1): array => [
        'key' => $key, 'label' => ucfirst($key), 'position' => $position,
        'type' => $type, 'type_version' => $version, 'required' => $key === 'title',
        'visibility' => 'admin', 'constraints' => $constraints,
    ];
    $descriptor = [
        'structure_id' => 'workbench.property_presentation', 'label' => 'Property presentation',
        'fields' => [
            $field('title', 'string', 10, [], 2),
            $field('body', 'text', 20),
            $field('quantity', 'integer', 30),
            $field('price', 'number', 40),
            $field('active', 'boolean', 50),
            $field('day', 'date', 60),
            $field('starts_at', 'datetime', 70),
            $field('state', 'choice', 80, $options),
            $field('tags', 'choices', 90, $options),
            $field('owner', 'user', 100),
            $field('document', 'file', 110),
            $field('related', 'relation', 120),
        ],
    ];
    $structure = $workbench->createStructure('scope:tenant-alpha', $descriptor, 'actor:admin:alpha');
    $uuid = '018f4f4c-706e-7b1f-9a3e-c93b5656a6f1';
    $precise = '9007199254740993.1234567890123456789';
    $record = $workbench->createRecord('scope:tenant-alpha', $structure->structureId, [
        'title' => 'Товар', 'body' => "Первая\nВторая", 'quantity' => 1234567,
        'price' => $precise, 'active' => true, 'day' => '2024-02-29',
        'starts_at' => '2026-09-25T14:03:09', 'state' => 'done', 'tags' => ['done', 'draft'],
        'owner' => 'user:admin:42', 'document' => $uuid, 'related' => $uuid,
    ], 'actor:admin:alpha');
    workbenchExpect($record->values['price'] === $precise, 'Storage rounded the Property decimal');

    $listed = $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha', $structure->structureId,
        filters: ['title' => ['operator' => 'contains', 'value' => 'Тов']],
    ), 'actor:admin:alpha');
    workbenchExpect(count($listed->items) === 1 && $listed->items[0]->recordId === $record->recordId, 'listed structure lost the typed record');
    $numeric = $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha', $structure->structureId,
        filters: ['price' => ['operator' => 'between', 'values' => [$precise, $precise]]],
    ), 'actor:admin:alpha');
    workbenchExpect(count($numeric->items) === 1, 'advertised decimal between operator did not execute');
    workbenchRejects(static fn () => $workbench->listRecords(new StorageWorkbenchRecordQuery(
        'scope:tenant-alpha', $structure->structureId,
        filters: ['day' => ['operator' => 'today', 'value' => '2026-09-25']],
    ), 'actor:admin:alpha'), 'storage_query_filter_operator_unsupported');

    $editedPrecise = '9007199254740993.1234567890123456791';
    $updated = $workbench->updateRecord('scope:tenant-alpha', $structure->structureId, $record->recordId, $record->revision, [
        'title' => 'Товар обновлён', 'body' => "Третья\nЧетвёртая", 'quantity' => 2345678,
        'price' => $editedPrecise, 'active' => false, 'day' => '2024-03-01',
        'starts_at' => '2026-09-26T10:11:12', 'state' => 'draft', 'tags' => ['done'],
        'owner' => 'user:admin:42', 'document' => $uuid, 'related' => $uuid,
    ], 'actor:admin:alpha');
    workbenchExpect($updated->revision === $record->revision + 1 && $updated->values['price'] === $editedPrecise, 'typed edit lost revision or decimal precision');
    workbenchRejects(static fn () => $workbench->updateRecord('scope:tenant-alpha', $structure->structureId, $record->recordId, $record->revision, [
        'title' => 'Устаревшая правка',
    ], 'actor:admin:alpha'), 'storage_workbench_record_revision_conflict');

    // Rebuild the service from the file-backed database, not from the returned write DTO.
    unset($workbench, $opened);
    $restarted = workbenchOpen($path);
    $workbench = workbenchRuntime($restarted['connection'])['workbench'];
    $read = $workbench->readRecord('scope:tenant-alpha', $structure->structureId, $record->recordId, 'actor:admin:alpha');
    workbenchExpect($read->revision === $updated->revision && $read->values['price'] === $editedPrecise && $read->values['active'] === false, 'edited typed values did not survive restart');
    $projector = new PropertyDisplayProjector(PropertyTypeRegistry::builtIns());
    $context = PropertyRenderContext::admin('ru', true, 'actor:admin:alpha', 'scope:tenant-alpha');
    $resolver = new class($uuid) implements PropertyReferenceLabelResolver {
        public function __construct(private string $uuid) {}
        public function resolveLabel(PropertyFieldDefinition $field, int $version, string $reference, PropertyRenderContext $context): ?string
        {
            if ($version !== 1 || !str_starts_with($field->fieldKey, 'stored.')) return null;
            if ($context->actorRef !== 'actor:admin:alpha' || $context->scopeRef !== 'scope:tenant-alpha') return null;
            return match (true) {
                $reference === $this->uuid && $field->typeKey === 'file' => 'Документ.pdf',
                $reference === $this->uuid && $field->typeKey === 'relation' => 'Связанный материал',
                $reference === 'user:admin:42' && $field->typeKey === 'user' => 'Редактор',
                default => null,
            };
        }
    };
    $projected = [];
    foreach ($descriptor['fields'] as $definition) {
        $key = $definition['key'];
        $property = PropertyFieldDefinition::make('stored.'.$key, $definition['type'], 'lang:stored.'.$key, $definition['constraints'], $definition['required']);
        $projected[$key] = $projector->project($property, $definition['type_version'], $read->values[$key], $context, $resolver);
        workbenchExpect($projected[$key]->status === 'present', 'Property projection failed for '.$key);
    }
    workbenchExpect($projected['price']->text === "9\u{00A0}007\u{00A0}199\u{00A0}254\u{00A0}740\u{00A0}993,1234567890123456791", 'projection rounded edited decimal after restart');
    workbenchExpect($projected['state']->text === 'Черновик' && $projected['tags']->text === 'Готово', 'edited choice labels missing');
    workbenchExpect($projected['owner']->text === 'Редактор' && $projected['document']->text === 'Документ.pdf' && $projected['related']->text === 'Связанный материал', 'reference labels missing');
    workbenchExpect($projector->project(PropertyFieldDefinition::make('stored.related', 'relation', 'lang:stored.related'), 1, $read->values['related'], $context)->text === null, 'raw relation leaked without owner resolver');
} finally {
    Facade::clearResolvedInstances();
    foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal'] as $file) @unlink($file);
}

echo "StorageWorkbenchFieldPresentationTest passed.\n";
