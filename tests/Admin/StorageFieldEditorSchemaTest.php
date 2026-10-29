<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\StorageFieldPropertyViewPort;
use Larena\Storage\Admin\StorageSectionGateway;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Filesystem\ValueObjects\PersistentLogicalFileInspection;
use Larena\Property\Enums\PropertySettingKind;
use Larena\Property\Runtime\ListPropertyOptionSource;
use Larena\Property\Runtime\PropertyOptionSourceRegistry;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Property\Runtime\PropertyTypeSettingsCatalog;
use Larena\Setting\Persistence\DatabaseSettingValueRepository;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchFieldChangeCheck;
use Larena\Storage\Contracts\StorageWorkbenchPurgeReceipt;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordPage;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\StorageStructureOptionSource;

// The storage field editor built from the type schema (settings system, stage 3): every setting a type declares is a
// field of the editor, drawn by its kind and labelled by Property; the form and the JSON path classify a change with
// one rule (Property's dataChangingKeys()); a change that touches data is checked over the stored records first, shown
// with its counts, and runs as the migration once confirmed; one that touches no data applies at once.

final class EditorSchemaTranslator implements \Illuminate\Contracts\Translation\Translator
{
    /** @var array<string, mixed> */
    private array $admin;
    /** @var array<string, string> */
    private array $settings;

    public function __construct()
    {
        $this->admin = storage_admin_words('ru');
        $this->settings = require dirname(__DIR__, 3) . '/property/resources/lang/ru/settings.php';
    }

    public function get($key, array $replace = [], $locale = null)
    {
        $asked = $key;
        $key = storage_admin_key((string) $key);
        if (str_starts_with($key, 'larena-property::settings.')) {
            return $this->settings[substr($key, strlen('larena-property::settings.'))] ?? $key;
        }
        $value = $this->admin;
        foreach (explode('.', str_starts_with($key, 'larena-admin::admin.') ? substr($key, strlen('larena-admin::admin.')) : '') as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $asked;
            }
            $value = $value[$part];
        }

        return is_string($value) ? $value : $asked;
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $key;
    }

    public function getLocale()
    {
        return 'ru';
    }

    public function setLocale($locale)
    {
    }
}

final class EditorSchemaAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
    }
}

final class EditorSchemaScope implements StorageSectionScope
{
    public function scopeForActor(string $actor): string
    {
        return 'scope:alpha';
    }

    public function fileBelongsToScope(string $logicalRef, string $scopeRef): bool
    {
        return false;
    }
}

final class EditorSchemaInspector implements PersistentLogicalFileInspector
{
    public function inspect(string $logicalRef): PersistentLogicalFileInspection
    {
        throw new RuntimeException('not_used');
    }
}

/**
 * One scope's storages in memory, its records checked by Property as Storage checks them: a change is an update or a
 * migration (the way it was asked), and the check of a change counts the records Property refuses under the new fields.
 */
final class EditorSchemaWorkbench implements StorageWorkbench, StorageWorkbenchFieldChangeCheck
{
    /** @var array<string, StorageWorkbenchStructure> */
    public array $structures = [];
    /** @var array<string, list<array<string, mixed>>> record values by storage */
    public array $values = [];
    /** @var list<string> */
    private array $changes = [];
    /** @var list<array<string, mixed>> the descriptors the changes were given */
    public array $descriptors = [];

    public function __construct(private PropertyTypeRegistry $types)
    {
    }

    /** @param list<array<string, mixed>> $fields */
    public function add(string $id, string $label, array $fields): void
    {
        $this->structures[$id] = new StorageWorkbenchStructure($id, 'scope:alpha', 1, new StorageSchemaVersionRef($id, 1), $label, $fields, hash('sha256', $id), '2026-10-23T00:00:00Z');
    }

    /**
     * The settings of one field of a storage as it is now.
     *
     * @phpstan-impure
     * @return array<array-key, mixed>
     */
    public function constraintsOf(string $id, int $index): array
    {
        $constraints = $this->structures[$id]->fields[$index]['constraints'] ?? [];

        return is_array($constraints) ? $constraints : [];
    }

    /**
     * @phpstan-impure
     * @return list<string>
     */
    public function takeChanges(): array
    {
        [$changes, $this->changes] = [$this->changes, []];

        return $changes;
    }

    public function filterOperators(): array
    {
        return [];
    }

    public function createStructure(string $scopeRef, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function updateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->change('update', $structureId, $descriptor);
    }

    public function migrateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->change('migrate', $structureId, $descriptor);
    }

    public function checkFieldChange(string $scopeRef, string $structureId, array $descriptor, string $actor, int $limit = self::DEFAULT_LIMIT): array
    {
        $records = $this->values[$structureId] ?? [];
        $invalid = 0;
        $samples = [];
        foreach (array_slice($records, 0, $limit) as $index => $values) {
            foreach ($descriptor['fields'] as $field) {
                $value = $values[$field['key']] ?? null;
                if ($value === null ? ($field['required'] ?? false) === true
                    : !$this->types->normalizeAndValidate($field['type'], $field['type_version'], $value, $field['constraints'])->canBePersistedByOwner()) {
                    $invalid++;
                    $samples[] = 'record-' . str_pad((string) ($index + 1), 32, '0', STR_PAD_LEFT);
                    break;
                }
            }
        }

        return ['data_changing' => true, 'fields' => array_column($descriptor['fields'], 'key'), 'total' => count($records),
            'checked' => min($limit, count($records)), 'invalid' => $invalid, 'complete' => count($records) <= $limit, 'samples' => array_slice($samples, 0, 5)];
    }

    /** @param array<string, mixed> $descriptor */
    private function change(string $how, string $structureId, array $descriptor): StorageWorkbenchStructure
    {
        $current = $this->structures[$structureId] ?? throw new StorageRejected('storage_structure_missing');
        $this->changes[] = $how;
        $this->descriptors[] = $descriptor;

        return $this->structures[$structureId] = new StorageWorkbenchStructure($structureId, $current->scopeRef, $current->version + 1,
            $current->schema, (string) $descriptor['label'], $descriptor['fields'], hash('sha256', $structureId . $current->version), '2026-10-23T00:00:00Z');
    }

    public function cancelFieldChange(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function readStructure(string $scopeRef, string $structureId, string $actor): StorageWorkbenchStructure
    {
        return $this->structures[$structureId] ?? throw new StorageRejected('storage_structure_missing');
    }

    public function listStructures(string $scopeRef, string $actor, bool $includeArchived = false): array
    {
        return array_values($this->structures);
    }

    public function setHistoryKeep(string $scopeRef, string $structureId, ?int $keep, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function archiveStructure(string $scopeRef, string $structureId, int $expectedVersion, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function restoreStructure(string $scopeRef, string $structureId, int $expectedVersion, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function purgeStructure(string $scopeRef, string $structureId, int $expectedVersion, string $actor, ?string $correlationId = null): StorageWorkbenchPurgeReceipt
    {
        throw new StorageRejected('not_used');
    }

    public function createRecord(string $scopeRef, string $structureId, array $values, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        throw new StorageRejected('not_used');
    }

    public function updateRecord(string $scopeRef, string $structureId, string $recordId, int $expectedRevision, array $values, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        throw new StorageRejected('not_used');
    }

    public function archiveRecord(string $scopeRef, string $structureId, string $recordId, int $expectedRevision, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        throw new StorageRejected('not_used');
    }

    public function restoreRecord(string $scopeRef, string $structureId, string $recordId, int $expectedRevision, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        throw new StorageRejected('not_used');
    }

    public function bulkArchive(string $scopeRef, string $structureId, array $expectedRevisions, string $actor, ?string $correlationId = null): array
    {
        throw new StorageRejected('not_used');
    }

    public function purgeRecords(string $scopeRef, string $structureId, array $expectedRevisions, string $actor, ?string $correlationId = null): StorageWorkbenchPurgeReceipt
    {
        throw new StorageRejected('not_used');
    }

    public function readRecord(string $scopeRef, string $structureId, string $recordId, string $actor): StorageWorkbenchRecord
    {
        throw new StorageRejected('not_used');
    }

    public function listRecords(StorageWorkbenchRecordQuery $query, string $actor): StorageWorkbenchRecordPage
    {
        return new StorageWorkbenchRecordPage([], null, 0, true);
    }

    public function recordHistory(string $scopeRef, string $structureId, string $recordId, string $actor, int $limit = 50): array
    {
        return [];
    }
}

$container = Container::getInstance();
$container->instance('translator', new EditorSchemaTranslator());
$sources = new PropertyOptionSourceRegistry();
$sources->register('test.zones', new ListPropertyOptionSource('test.zones',
    static fn (?string $locale): array => [['value' => 'UTC', 'label' => 'UTC'], ['value' => 'Europe/Moscow', 'label' => 'Москва']],
    static fn (?string $locale): string => 'Часовые пояса'));
$types = PropertyTypeRegistry::builtIns()->withOptionSources($sources);
$container->instance(PropertyTypeRegistry::class, $types);
$workbench = new EditorSchemaWorkbench($types);
$sources->register(StorageStructureOptionSource::KEY, new ListPropertyOptionSource(StorageStructureOptionSource::KEY,
    static fn (?string $locale): array => array_map(static fn (StorageWorkbenchStructure $structure): array => ['value' => $structure->structureId, 'label' => $structure->label], array_values($workbench->structures)),
    static fn (?string $locale): string => 'Хранилища'));

$field = static fn (string $key, int $position, string $type, array $constraints = [], bool $required = false): array => ['key' => $key, 'label' => ucfirst($key),
    'position' => $position, 'type' => $type, 'type_version' => PropertyTypeSettingsCatalog::builtIns()->latest($type)->version ?? 1, 'required' => $required,
    'visibility' => 'public', 'constraints' => $constraints];
$workbench->add('workbench.sections', 'Разделы', [$field('name', 10, 'string')]);
$workbench->add('workbench.catalog', 'Каталог', [
    $field('title', 10, 'string', ['max_length' => 100]),
    $field('price', 20, 'number', ['min' => '0']),
    $field('qty', 30, 'integer'),
    $field('status', 40, 'choice', ['options' => [['value' => 'new', 'label' => 'Новый', 'color' => 'success', 'icon' => 'star']]]),
    $field('section', 50, 'record', ['target_structure_id' => 'workbench.sections']),
    $field('zone', 60, 'lookup', ['source' => 'test.zones']),
]);
$workbench->values['workbench.catalog'] = [['title' => 'Short'], ['title' => 'Exactly ten'], ['title' => str_repeat('L', 50)]];
$gateway = new StorageSectionGateway(new EditorSchemaAuthorizer(), new EditorSchemaScope(), $workbench, new EditorSchemaInspector());

$database = tempnam(sys_get_temp_dir(), 'larena-field-editor-schema-');
assert(is_string($database));
try {
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
    $connection = $capsule->getConnection();
    $connection->getSchemaBuilder()->create('larena_setting_values', static function (Blueprint $table): void {
        $table->id();
        $table->string('setting_namespace', 120);
        $table->string('setting_key', 160);
        $table->string('scope', 32);
        $table->string('scope_id', 120);
        $table->json('value');
        $table->unsignedBigInteger('revision')->default(1);
        $table->timestamps();
        $table->unique(['setting_namespace', 'setting_key', 'scope', 'scope_id']);
    });
    \Larena\Setting\Persistence\DatabaseViewSettingsStore::createSchema($connection->getSchemaBuilder());
    $port = new StorageFieldPropertyViewPort($gateway, new AdminRecordLayoutStore(\Larena\Setting\Runtime\ViewSettings::on($connection)), new EditorSchemaAuthorizer());
    $actor = 'user:admin_identity:1';
    $scope = 'scope:alpha';
    $collection = StorageFieldPropertyViewPort::COLLECTION_PREFIX . 'workbench.catalog';
    /** @return array{0: array<string, array<string, mixed>>, 1: array<string, mixed>} */
    $drawn = static function (array $answer): array {
        assert(($answer['answer'] ?? null) === 'applied', json_encode($answer, JSON_UNESCAPED_UNICODE));
        $fields = [];
        foreach ($answer['record']['sections'] as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['fields'] as $one) {
                    $fields[$one['key']] = $one;
                }
            }
        }

        return [$fields, $answer['record']];
    };
    $load = static fn (string $key): array => $drawn($port->raise($actor, $scope, 'record.load', ['collection' => $collection, 'record_id' => $key, 'reason' => 'connected']));
    $save = static fn (string $key, array $values): array => $port->raise($actor, $scope, 'record.save',
        ['collection' => $collection, 'record_id' => $key, 'revision' => $workbench->structures['workbench.catalog']->version, 'values' => $values]);
    $create = static fn (array $values): array => $port->raise($actor, $scope, 'record.create', ['collection' => $collection, 'values' => $values]);
    // The same save raised again with the digest of a confirmation (port 1.14.0 `confirm`).
    $confirm = static fn (string $key, array $values, string $digest): array => $port->raise($actor, $scope, 'record.save',
        ['collection' => $collection, 'record_id' => $key, 'revision' => $workbench->structures['workbench.catalog']->version, 'values' => $values, 'confirm' => $digest]);

    // Every setting every type declares is a field of the editor, drawn by its kind, labelled by Property, and shown
    // while the type chosen is one that takes it (port 1.14.0 `when`, evaluated by the component as the type switches).
    [$created, $createdRecord] = $drawn($port->raise($actor, $scope, 'record.load', ['collection' => $collection, 'mode' => 'create', 'reason' => 'connected']));
    $expected = [
        PropertySettingKind::Integer->value => 'integer', PropertySettingKind::Decimal->value => 'number', PropertySettingKind::Date->value => 'date',
        PropertySettingKind::DateTime->value => 'datetime', PropertySettingKind::String->value => 'string', PropertySettingKind::StringList->value => 'text',
        PropertySettingKind::Options->value => 'text', PropertySettingKind::StructureReference->value => 'lookup', PropertySettingKind::OptionSource->value => 'choice',
        PropertySettingKind::Boolean->value => 'boolean',
    ];
    $settingLabels = require dirname(__DIR__, 3) . '/property/resources/lang/ru/settings.php';
    $catalog = PropertyTypeSettingsCatalog::builtIns();
    $map = StorageFieldPropertyViewPort::fieldTypes();
    $shownFor = static function (array $field): ?array {
        $visible = $field['when']['visible'] ?? null;
        $leaf = isset($visible['all']) ? $visible['all'][0] : $visible;

        return is_array($leaf) && $leaf['field'] === 'type' && $leaf['op'] === 'in' ? $leaf['value'] : null;
    };
    foreach ($catalog->typeKeys() as $type) {
        foreach ($catalog->latest($type)->settings ?? [] as $setting) {
            $key = StorageFieldPropertyViewPort::editorKey($setting);
            assert(isset($created[$key]), $type . '.' . $setting->key);
            assert($created[$key]['propertyType'] === $expected[$setting->kind->value], $key);
            assert($created[$key]['label'] === $settingLabels[$setting->key], $key);
            assert(in_array($type, $map[$key], true), $key);
            assert($shownFor($created[$key]) === $map[$key], $key);
        }
    }
    // The page reads no map of its own any more: the conditions travel with the fields.
    assert(!isset($createdRecord['larena_field_types']) && !isset($createdRecord['larena_field_type']));
    // The fields every type has carry no condition; search and the index go while «Шифровать» is on.
    assert(!isset($created['label']['when']) && !isset($created['type']['when']) && !isset($created['required']['when']));
    assert($shownFor($created['searchable']) === ['string', 'text', 'phone'] && $created['searchable']['when']['visible']['all'][1]
        === ['not' => ['field' => 'encrypted', 'op' => 'eq', 'value' => true]]);
    assert($shownFor($created['localized']) === ['string', 'text'] && $shownFor($created['encrypted']) === $map['encrypted']);
    // One key with different kinds is one editor field a kind (min: a whole number, a decimal, a date, a date-time).
    assert($map['min__integer'] === ['integer'] && $map['min__decimal'] === ['number'] && $map['min__date'] === ['date'] && $map['min__datetime'] === ['datetime']);
    assert($map['max_length'] === ['string', 'text', 'html'] && $map['options_text'] === ['choice', 'choices'] && $map['source'] === ['lookup'] && $map['target_structure_id'] === ['record']);
    assert($map['searchable'] === ['string', 'text', 'phone'] && $map['localized'] === ['string', 'text'] && $map['indexed'] === ['string']);
    // A lookup chooses among the registered sources; a «Storage record» field picks a storage with search.
    assert(array_column($created['source']['options'], 'label', 'value') === ['storage.structures' => 'Хранилища', 'test.zones' => 'Часовые пояса']);
    assert($created['target_structure_id']['source'] === 'storage.structures');
    $picked = $port->raise($actor, $scope, 'field.options', ['collection' => $collection, 'record_id' => null, 'field' => 'target_structure_id', 'search' => 'разд',
        'cursor' => null, 'source' => 'storage.structures', 'limit' => 20]);
    assert(array_column($picked['options_page']['items'], 'value') === ['workbench.sections'] && $picked['options_page']['next_cursor'] === null);
    // Hints say what changing a setting does.
    assert($created['max_length']['hint'] === 'Изменение сначала проверяет сохранённые записи.' && !isset($created['mask']['hint']));
    assert($created['source']['hint'] === 'Задаётся при создании поля и потом не меняется.' && str_contains($created['options_text']['hint'], 'значок'));

    // An existing field: its type's settings carry its values; one locked after creation is read only.
    [$title, $titleRecord] = $load('title');
    assert($title['max_length']['value'] === 100 && !isset($title['max_length']['readonly']) && $title['type']['value'] === 'string');
    assert(!isset($title['data_change_confirmed']), 'a change of the data is confirmed in the save bar, not by a field');
    [$section] = $load('section');
    assert(($section['target_structure_id']['readonly'] ?? false) === true && $section['target_structure_id']['value']['value'] === 'workbench.sections');
    assert($section['target_structure_id']['value']['label'] === 'Разделы');
    [$zone] = $load('zone');
    assert(($zone['source']['readonly'] ?? false) === true && $zone['source']['value'] === 'test.zones');
    [$price] = $load('price');
    assert($price['min__decimal']['value'] === '0' && !isset($price['min__decimal']['readonly']));
    [$status] = $load('status');
    assert($status['options_text']['value'] === 'new | Новый | success | star');

    // A change that touches no data applies at once: a mask is form only and free after creation.
    $workbench->takeChanges();
    $applied = $save('title', ['mask' => '000']);
    assert($applied['answer'] === 'applied' && $workbench->takeChanges() === ['update']);
    assert($workbench->constraintsOf('workbench.catalog', 0) === ['max_length' => 100, 'mask' => '000']);
    // Emptied, a setting is taken off.
    $save('title', ['mask' => '']);
    assert($workbench->constraintsOf('workbench.catalog', 0) === ['max_length' => 100] && $workbench->takeChanges() === ['update']);

    // A change that touches data is checked over the records first: when some would not fit it is refused outright,
    // under the field it changes, and nothing is asked.
    $refused = $save('title', ['max_length' => 20]);
    assert($refused['answer'] === 'refused' && $refused['reason'] === StorageFieldPropertyViewPort::RECORDS_INVALID && $workbench->takeChanges() === []);
    assert(!isset($refused['confirm']) && str_contains($refused['errors']['max_length'], 'не подходят 1 из 3'), json_encode($refused, JSON_UNESCAPED_UNICODE));
    // A change every record fits asks for the confirmation in the save bar (port 1.14.0 confirm_required), with the
    // counts and the digest of the storage revision, the field and the new settings; nothing is written yet.
    $asked = $save('title', ['max_length' => 60]);
    assert($asked['answer'] === 'refused' && $asked['reason'] === 'confirm_required' && $workbench->takeChanges() === [] && !isset($asked['errors']));
    assert($asked['confirm']['summary'] === 'Новые настройки затронут данные: проверено 3 из 3 записей, не подходят 0. Записи перестроятся в фоне.', $asked['confirm']['summary']);
    assert($asked['message'] === $asked['confirm']['summary'] && preg_match('/\A[0-9a-f]{64}\z/', $asked['confirm']['digest']) === 1);
    assert($save('title', ['max_length' => 60])['confirm']['digest'] === $asked['confirm']['digest'], 'the same save is asked with the same digest');
    // A digest of another change, or of another one of the same field, is asked again with its own words; nothing runs.
    $other = $confirm('title', ['max_length' => 70], $asked['confirm']['digest']);
    assert($other['reason'] === 'confirm_required' && $other['confirm']['digest'] !== $asked['confirm']['digest'] && $workbench->takeChanges() === []);
    $forged = $confirm('title', ['max_length' => 60], str_repeat('0', 64));
    assert($forged['reason'] === 'confirm_required' && $forged['confirm']['digest'] === $asked['confirm']['digest'] && $workbench->takeChanges() === []);
    // The same save raised with the digest runs as the migration (which checks every record again).
    $confirmed = $confirm('title', ['max_length' => 60], $asked['confirm']['digest']);
    assert($confirmed['answer'] === 'applied' && $workbench->takeChanges() === ['migrate']);
    assert($workbench->constraintsOf('workbench.catalog', 0) === ['max_length' => 60]);
    // The storage moved on (a new revision): the digest given before is stale, and the change is asked again.
    $again = $confirm('title', ['max_length' => 70], $other['confirm']['digest']);
    assert($again['reason'] === 'confirm_required' && $again['confirm']['digest'] !== $other['confirm']['digest'] && $workbench->takeChanges() === []);
    // A retype and «required» touch data too.
    assert($save('qty', ['type' => 'number'])['reason'] === 'confirm_required');
    // «Required» on a field no record fills: every record would not fit.
    $required = $save('qty', ['required' => true]);
    assert($required['reason'] === StorageFieldPropertyViewPort::RECORDS_INVALID && $workbench->takeChanges() === []);
    assert(str_contains($required['errors']['required'], '3 из 3'));

    // A storage without records has nothing a change could touch: it applies at once, through the migration.
    $empty = $port->raise($actor, $scope, 'record.save', ['collection' => StorageFieldPropertyViewPort::COLLECTION_PREFIX . 'workbench.sections',
        'record_id' => 'name', 'revision' => $workbench->structures['workbench.sections']->version, 'values' => ['max_length' => 30]]);
    assert($empty['answer'] === 'applied' && $workbench->takeChanges() === ['migrate'] && $workbench->constraintsOf('workbench.sections', 0) === ['max_length' => 30]);

    // A setting that is not of its kind is refused under it; settings that do not agree are refused by Property.
    $wrong = $save('price', ['min__decimal' => 'много']);
    assert($wrong['answer'] === 'refused' && $wrong['reason'] === StorageFieldPropertyViewPort::SETTING_INVALID && isset($wrong['errors']['min__decimal']));
    $crossed = $save('qty', ['min__integer' => 10, 'max__integer' => 5]);
    assert($crossed['answer'] === 'refused' && $crossed['reason'] === 'storage_workbench_field_settings_invalid' && $workbench->takeChanges() === []);
    // A decimal typed with a comma is Property's decimal.
    $decimal = $save('price', ['min__decimal' => '1,5']);
    assert($decimal['reason'] === 'confirm_required', json_encode($decimal, JSON_UNESCAPED_UNICODE));
    assert($confirm('price', ['min__decimal' => '1,5'], $decimal['confirm']['digest'])['answer'] === 'applied');
    assert($workbench->constraintsOf('workbench.catalog', 1) === ['min' => '1.5'] && $workbench->takeChanges() === ['migrate']);

    // Options keep their colour and icon; a new field of every kind is made from the editor.
    assert($create(['label' => 'Stage', 'key' => 'stage', 'type' => 'choice', 'options_text' => "draft | Черновик |  | edit\nlive | В работе | success"])['answer'] === 'applied');
    $stage = $workbench->descriptors[count($workbench->descriptors) - 1]['fields'][6];
    assert($stage['constraints'] === ['options' => [['value' => 'draft', 'label' => 'Черновик', 'icon' => 'edit'], ['value' => 'live', 'label' => 'В работе', 'color' => 'success']]]);
    assert($create(['label' => 'Office zone', 'key' => 'office_zone', 'type' => 'lookup', 'source' => 'test.zones'])['answer'] === 'applied');
    $officeZone = $workbench->descriptors[count($workbench->descriptors) - 1]['fields'][7];
    assert($officeZone['type'] === 'lookup' && $officeZone['constraints'] === ['source' => 'test.zones']);
    $unknownSource = $create(['label' => 'Bad', 'key' => 'bad_source', 'type' => 'lookup', 'source' => 'test.unknown']);
    assert($unknownSource['answer'] === 'refused');
    assert($create(['label' => 'Formats', 'key' => 'scan', 'type' => 'file', 'accept' => "pdf\nimage/*", 'max_size' => '1048576'])['answer'] === 'applied');
    $scan = $workbench->descriptors[count($workbench->descriptors) - 1]['fields'][8];
    assert($scan['constraints'] === ['accept' => ['pdf', 'image/*'], 'max_size' => 1048576]);
    $workbench->takeChanges();

    // One rule for the form and the JSON path (structure.update with the whole descriptor): touchesData().
    assert(!StorageSectionGateway::touchesData($field('a', 1, 'string', ['max_length' => 5]), $field('a', 1, 'string', ['max_length' => 5, 'placeholder' => 'Имя'])));
    assert(StorageSectionGateway::touchesData($field('a', 1, 'string', ['max_length' => 5]), $field('a', 1, 'string', ['max_length' => 6])));
    assert(StorageSectionGateway::touchesData($field('a', 1, 'string'), $field('a', 1, 'text')));
    assert(StorageSectionGateway::touchesData($field('a', 1, 'string'), $field('a', 1, 'string', [], true)));
    $json = static function (array $title, bool $confirm) use ($gateway, $actor, $workbench): array {
        $fields = array_map(static fn (array $one): array => $one['key'] === 'title' ? $title : $one, $workbench->structures['workbench.catalog']->fields);

        return $gateway->dispatch($actor, null, 'structure.update', ['structure_id' => 'workbench.catalog', 'label' => 'Каталог',
            'expected_version' => $workbench->structures['workbench.catalog']->version, 'fields' => $fields] + ($confirm ? ['confirm_migration' => '1'] : []));
    };
    assert($json($field('title', 10, 'string', ['max_length' => 60, 'placeholder' => 'Название']), false)['status'] === 'ok' && $workbench->takeChanges() === ['update']);
    assert($json($field('title', 10, 'string', ['max_length' => 70, 'placeholder' => 'Название']), false)['status'] === 'rejected' && $workbench->takeChanges() === []);
    assert($json($field('title', 10, 'string', ['max_length' => 70, 'placeholder' => 'Название']), true)['status'] === 'ok' && $workbench->takeChanges() === ['migrate']);
    // The form's check answers the same descriptor's counts; a change that touches nothing is not checked at all.
    $check = $gateway->dispatch($actor, null, 'structure.configure_check', ['structure_id' => 'workbench.catalog', 'label' => 'Каталог',
        'expected_version' => $workbench->structures['workbench.catalog']->version, 'fields' => [['key' => 'title', 'label' => 'Title', 'position' => 10, 'type' => 'string', 'settings' => ['max_length' => 8]]]]);
    assert($check['status'] === 'ok' && $check['redirect']['check']['invalid'] === 2 && $check['redirect']['check']['total'] === 3);
    $quiet = $gateway->dispatch($actor, null, 'structure.configure_check', ['structure_id' => 'workbench.catalog', 'label' => 'Каталог',
        'expected_version' => $workbench->structures['workbench.catalog']->version, 'fields' => [['key' => 'title', 'label' => 'Title', 'position' => 10, 'type' => 'string', 'settings' => ['placeholder' => 'Имя']]]]);
    assert($quiet['redirect']['check']['data_changing'] === false && $workbench->takeChanges() === []);
    // A locked setting never changes through the section.
    $locked = $gateway->dispatch($actor, null, 'structure.configure', ['structure_id' => 'workbench.catalog', 'label' => 'Каталог', 'confirm_migration' => '1',
        'expected_version' => $workbench->structures['workbench.catalog']->version, 'fields' => [['key' => 'zone', 'label' => 'Zone', 'position' => 60, 'type' => 'lookup', 'settings' => ['source' => 'storage.structures']]]]);
    assert($locked['status'] === 'rejected' && $locked['reason_code'] === 'storage_field_setting_locked' && $workbench->takeChanges() === []);
} finally {
    @unlink($database);
}

echo "StorageFieldEditorSchemaTest passed.\n";
