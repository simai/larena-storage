<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\StorageCsvExportRows;
use Larena\Storage\Admin\StorageCsvImport;
use Larena\Storage\Admin\StorageEncryptedFieldReveal;
use Larena\Storage\Admin\StorageEncryptedFields;
use Larena\Storage\Admin\StorageFieldPropertyViewPort;
use Larena\Storage\Admin\StoragePropertyViewPort;
use Larena\Storage\Admin\StorageSectionGateway;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Admin\Runtime\AdminDataviewCells;
use Larena\Admin\Runtime\AdminRecordFieldRenderer;
use Larena\Admin\Runtime\AdminRecordPanel;
use Larena\Admin\Runtime\AdminRecordPanelPresenter;
use Larena\Admin\Runtime\RecordFilterFields;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Filesystem\ValueObjects\PersistentLogicalFileInspection;
use Larena\Setting\Persistence\DatabaseSettingValueRepository;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchPurgeReceipt;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordPage;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Encryption\StorageFieldEnvelope;
use Larena\Storage\Exceptions\StorageRejected;

// The Storage section over encrypted fields («Шифровать», larena-specs
// docs/architecture/2026-10-22-storage-field-encryption.md, «Reading», slice admin): the field editor offers the
// option to a person who may manage encryption only and closes search and index for it; lists, the record panel and
// CSV never carry an envelope (a mask stands for it) and never filter, sort or search by the field; the record panel
// keeps an untouched value by sending its envelope back and replaces it when typed; «Показать» reveals one field to
// a person with decrypt only; the history says «изменено» / «без изменений»; every refusal has ru and en words.

/** The admin's own words of one language, read from the package's language file. */
final class EncryptionTestTranslator implements \Illuminate\Contracts\Translation\Translator
{
    /** @var array<string, mixed> */
    private array $admin;

    public function __construct(private string $locale = 'ru')
    {
        $this->admin = storage_admin_words($locale);
    }

    public function get($key, array $replace = [], $locale = null)
    {
        $asked = $key;
        $key = storage_admin_key((string) $key);
        if (!str_starts_with($key, 'larena-admin::admin.')) {
            return $asked;
        }
        $value = $this->admin;
        foreach (explode('.', substr($key, strlen('larena-admin::admin.'))) as $part) {
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
        return $this->locale;
    }

    public function setLocale($locale)
    {
    }
}

/** Denies the operations listed for an actor; allows the rest. */
final class EncryptionTestAuthorizer implements ActorOperationAuthorizer
{
    /** @param array<string, list<string>> $denied */
    public function __construct(private array $denied = [])
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        if (in_array($operation, $this->denied[$actor] ?? [], true)) {
            throw new AccessMutationRejected('access_actor_forbidden');
        }
    }
}

/** What Storage's reveal was asked, call by call. */
final class EncryptionTestRevealLog
{
    /** @var list<list<mixed>> */
    private array $calls = [];

    /** @param list<mixed> $call */
    public function record(array $call): void
    {
        $this->calls[] = $call;
    }

    /**
     * The calls since the last take; taking them clears them.
     *
     * @phpstan-impure
     * @return list<list<mixed>>
     */
    public function take(): array
    {
        [$calls, $this->calls] = [$this->calls, []];

        return $calls;
    }
}

final class EncryptionTestScope implements StorageSectionScope
{
    public function scopeForActor(string $actor): string
    {
        return str_starts_with($actor, 'user:admin_identity:') ? 'scope:alpha' : throw new RuntimeException(StorageSectionScope::SCOPE_DENIED);
    }

    public function fileBelongsToScope(string $logicalRef, string $scopeRef): bool
    {
        return false;
    }
}

final class EncryptionTestInspector implements PersistentLogicalFileInspector
{
    public function inspect(string $logicalRef): PersistentLogicalFileInspection
    {
        throw new RuntimeException('not_used');
    }
}

/**
 * One storage in memory, as Storage answers it: encrypted values are envelopes on every read. A write seals a typed
 * value into a new envelope and keeps an envelope it is given (Storage checks that it belongs to the record and the
 * field); every version stays in the history.
 */
final class EncryptionTestWorkbench implements StorageWorkbench
{
    /** @var array<string, StorageWorkbenchStructure> */
    public array $structures = [];
    /** @var array<string, list<StorageWorkbenchRecord>> versions by record, newest first */
    public array $versions = [];
    /** @var list<array<string, mixed>> the values each write was given */
    public array $written = [];
    /** @var list<StorageWorkbenchRecordQuery> */
    public array $queries = [];
    /** @var list<string> */
    private array $changes = [];
    public ?string $refuseChange = null;
    private int $sealed = 0;

    /**
     * How the structure changes since the last call went (update or migrate); taking them clears them.
     *
     * @phpstan-impure
     * @return list<string>
     */
    public function takeChanges(): array
    {
        [$changes, $this->changes] = [$this->changes, []];

        return $changes;
    }

    public function envelope(): array
    {
        $this->sealed++;

        return (new StorageFieldEnvelope('dk-' . str_repeat('a', 24), str_pad((string) $this->sealed, 24, 'n'),
            str_pad((string) $this->sealed, 48, 'c')))->toArray();
    }

    public function filterOperators(): array
    {
        return [];
    }

    /** @param list<array<string, mixed>> $fields */
    public function put(string $id, array $fields, int $version = 1): void
    {
        $this->structures[$id] = new StorageWorkbenchStructure($id, 'scope:alpha', $version, new StorageSchemaVersionRef($id, 1), 'People',
            $fields, hash('sha256', $id . $version), '2026-10-22T00:00:00Z');
    }

    public function createStructure(string $scopeRef, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function updateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->change('update', $structureId, $expectedVersion, $descriptor);
    }

    public function migrateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->change('migrate', $structureId, $expectedVersion, $descriptor);
    }

    /** @param array<string, mixed> $descriptor */
    private function change(string $how, string $structureId, int $expectedVersion, array $descriptor): StorageWorkbenchStructure
    {
        if ($this->refuseChange !== null) {
            [$reason, $this->refuseChange] = [$this->refuseChange, null];
            throw new StorageRejected($reason);
        }
        $this->changes[] = $how;
        $this->put($structureId, $descriptor['fields'], $expectedVersion + 1);

        return $this->structures[$structureId];
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

    public function cancelFieldChange(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
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

    /** Seals what is typed for an encrypted field; an envelope given stays as it is. @param array<string, mixed> $values */
    private function seal(string $structureId, array $values): array
    {
        foreach (StorageEncryptionPolicy::encryptedKeys($this->structures[$structureId]->fields) as $key) {
            if (array_key_exists($key, $values) && !StorageFieldEnvelope::isEnvelope($values[$key])) {
                $values[$key] = $this->envelope();
            }
        }

        return $values;
    }

    public function createRecord(string $scopeRef, string $structureId, array $values, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        $this->written[] = $values;
        $id = 'record-' . str_pad(dechex(count($this->versions) + 1), 32, '0', STR_PAD_LEFT);
        $record = new StorageWorkbenchRecord($structureId, $scopeRef, $id, 1, 1, 'active', $this->seal($structureId, $values), 'hash-1', 'create', '2026-10-22T00:00:00Z', $actor);
        $this->versions[$id] = [$record];

        return $record;
    }

    public function updateRecord(string $scopeRef, string $structureId, string $recordId, int $expectedRevision, array $values, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        $this->written[] = $values;
        $current = $this->readRecord($scopeRef, $structureId, $recordId, $actor);
        $record = new StorageWorkbenchRecord($structureId, $scopeRef, $recordId, $current->revision + 1, 1, 'active', $this->seal($structureId, $values),
            'hash-' . ($current->revision + 1), 'update', '2026-10-22T00:00:00Z', $actor);
        array_unshift($this->versions[$recordId], $record);

        return $record;
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
        return ($this->versions[$recordId] ?? [])[0] ?? throw new StorageRejected('storage_record_missing');
    }

    public function listRecords(StorageWorkbenchRecordQuery $query, string $actor): StorageWorkbenchRecordPage
    {
        $this->queries[] = $query;
        $items = array_map(static fn (array $versions): StorageWorkbenchRecord => $versions[0], array_values($this->versions));

        return new StorageWorkbenchRecordPage($items, null, count($items), true, [], false);
    }

    public function recordHistory(string $scopeRef, string $structureId, string $recordId, string $actor, int $limit = 50): array
    {
        return array_slice($this->versions[$recordId] ?? [], 0, $limit);
    }
}

$translator = new EncryptionTestTranslator('ru');
Container::getInstance()->instance('translator', $translator);
$ru = storage_admin_words('ru');
$en = storage_admin_words('en');
$admin = 'user:admin_identity:1';
$editor = 'user:admin_identity:2';
$structureId = 'workbench.people';
$field = static fn (string $key, string $type, int $position, array $more = []): array => ['key' => $key, 'label' => ucfirst($key), 'position' => $position,
    'type' => $type, 'type_version' => 1, 'required' => false, 'visibility' => 'public', 'constraints' => []] + $more;
$encrypted = ['visibility' => 'admin', 'encrypted' => true, 'searchable' => false];

$workbench = new EncryptionTestWorkbench();
$workbench->put($structureId, [
    $field('passport', 'string', 10, $encrypted),
    $field('name', 'string', 20),
    $field('born', 'date', 30, ['visibility' => 'admin', 'encrypted' => true]),
    $field('note', 'text', 40),
]);
$authorizer = new EncryptionTestAuthorizer([$editor => ['storage.encryption.manage', 'storage.workbench.record.decrypt']]);
$gateway = new StorageSectionGateway($authorizer, new EncryptionTestScope(), $workbench, new EncryptionTestInspector());
$record = $workbench->createRecord('scope:alpha', $structureId, ['passport' => 'PLAINTEXT-SENTINEL', 'name' => 'Ada', 'born' => '1815-12-10'], $admin);
$recordId = $record->recordId;
$stored = $record->values['passport'];
assert(StorageFieldEnvelope::isEnvelope($stored));

// 1. The section's snapshot never carries an envelope: a mask with a fingerprint stands for it, in the list and in
// the history alike.
$state = $gateway->snapshot($admin, null, ['surface' => 'storage_section', 'structure_id' => $structureId, 'record_id' => $recordId]);
assert($state['status'] === 'ok', json_encode($state));
$encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
assert(!str_contains($encoded, StorageFieldEnvelope::MARKER) && !str_contains($encoded, (string) $stored['c']) && !str_contains($encoded, 'PLAINTEXT-SENTINEL'));
$listed = $state['records']['items'][0]['values'];
assert(StorageEncryptedFields::isMasked($listed['passport']) && StorageEncryptedFields::isMasked($listed['born']) && $listed['name'] === 'Ada');
assert(StorageEncryptedFields::isMasked($state['records']['history'][0]['values']['passport']));
assert(StorageEncryptedFields::same($listed['passport'], $stored) && !StorageEncryptedFields::same($listed['passport'], $listed['born']));

// 2. A record.update that leaves an encrypted field out, empty or masked keeps the stored envelope; a typed value
// replaces it; an envelope sent back passes as it is.
$update = static function (array $values) use ($gateway, $workbench, $admin, $structureId, $recordId): array {
    $receipt = $gateway->dispatch($admin, null, 'record.update', ['structure_id' => $structureId, 'record_id' => $recordId,
        'expected_revision' => $workbench->readRecord('scope:alpha', $structureId, $recordId, $admin)->revision, 'values' => $values]);
    assert($receipt['status'] === 'ok', json_encode($receipt));

    return end($workbench->written);
};
$born = $workbench->readRecord('scope:alpha', $structureId, $recordId, $admin)->values['born'];
$sent = $update(['name' => 'Ada L.']);
assert($sent['passport'] === $stored && $sent['born'] === $born && $sent['name'] === 'Ada L.');
$sent = $update(['name' => 'Ada L.', 'passport' => '', 'born' => $listed['born']]);
assert($sent['passport'] === $stored && $sent['born'] === $born);
$sent = $update(['name' => 'Ada L.', 'passport' => $stored]);
assert($sent['passport'] === $stored);
$sent = $update(['name' => 'Ada L.', 'passport' => 'NEW-SECRET']);
assert($sent['passport'] === 'NEW-SECRET');
$replaced = $workbench->readRecord('scope:alpha', $structureId, $recordId, $admin)->values['passport'];
assert(StorageFieldEnvelope::isEnvelope($replaced) && $replaced !== $stored);

// 3. The history says whether each encrypted field changed, from the envelopes (newest first): the last write
// replaced the passport, the ones before kept it.
$history = $gateway->snapshot($admin, null, ['surface' => 'storage_section', 'structure_id' => $structureId, 'record_id' => $recordId, 'records' => 'skip'])['records']['history'];
assert(count($history) === 5);
assert(!StorageEncryptedFields::same($history[1]['values']['passport'], $history[0]['values']['passport']));
assert(StorageEncryptedFields::same($history[2]['values']['passport'], $history[1]['values']['passport']));
assert(StorageEncryptedFields::same(null, null) && !StorageEncryptedFields::same(null, $history[0]['values']['passport']));
$controller = (new ReflectionClass(\Larena\Storage\Admin\Http\Controllers\StorageSectionController::class))->newInstanceWithoutConstructor();
$sealedChanges = (new ReflectionMethod($controller, 'sealedChanges'))->invoke($controller, $history,
    [['label' => 'Паспорт'] + $field('passport', 'string', 10, $encrypted), ['label' => 'Родился'] + $field('born', 'date', 30, ['encrypted' => true])]);
assert($sealedChanges[0] === 'Паспорт: изменено, Родился: без изменений', json_encode($sealedChanges, JSON_UNESCAPED_UNICODE));
assert($sealedChanges[1] === 'Паспорт: без изменений, Родился: без изменений');
// The first version, against nothing: a value it holds is a change.
assert($sealedChanges[4] === 'Паспорт: изменено, Родился: изменено');
assert(!str_contains(json_encode($sealedChanges, JSON_UNESCAPED_UNICODE), 'SECRET'));

// 4. Lists: the cell says «•••• зашифровано» (an empty value stays empty), the column is text and marked encrypted
// for CSV; the field is no filter and no sort; the admin CSV export leaves its column out.
$passport = $workbench->structures[$structureId]->fields[0];
assert(AdminDataviewCells::value($passport, $listed['passport'], null) === '•••• зашифровано');
assert(AdminDataviewCells::value($passport, $stored, '{"larena_enc":1}') === '•••• зашифровано');
assert(AdminDataviewCells::value($passport, null, null) === null);
assert(AdminDataviewCells::column($passport) === ['propertyType' => 'string', 'encrypted' => true]);
assert(AdminDataviewCells::column($workbench->structures[$structureId]->fields[2]) === ['propertyType' => 'string', 'encrypted' => true]);
assert(!RecordFilterFields::sortable($passport) && RecordFilterFields::sortable($workbench->structures[$structureId]->fields[1]));
$byKey = [];
foreach ($workbench->structures[$structureId]->fields as $candidate) {
    $byKey[$candidate['key']] = $candidate;
}
$filters = array_column(RecordFilterFields::describe($byKey, []), 'key');
assert(!in_array('passport', $filters, true) && !in_array('born', $filters, true) && in_array('name', $filters, true), json_encode($filters));
$shown = StorageCsvExportRows::shownKeys($workbench->structures[$structureId]->fields);
assert($shown === ['name', 'note']);
$rows = new StorageCsvExportRows();
$csv = $rows->header($shown, $workbench->structures[$structureId]->fields)
    . $rows->row($recordId, $listed, $shown, $workbench->structures[$structureId]->fields, 'ru', $admin, 'scope:alpha');
assert($csv === "ID,Name,Note\n{$recordId},Ada,\n", $csv);
// A filter or sort by an encrypted field is refused before Storage is asked.
foreach ([['filters' => ['passport' => ['x']]], ['sort' => [['key' => 'passport', 'direction' => 'asc']]]] as $query) {
    try {
        StorageCsvExportRows::query($query, $shown);
        throw new RuntimeException('an export query by an encrypted field must be refused');
    } catch (InvalidArgumentException) {
    }
}
// The archive rows and the record panel's own view say the same, never the envelope.
$renderer = new AdminRecordFieldRenderer();
assert($renderer->displayText([], $passport, $listed['passport']) === '•••• зашифровано' && $renderer->displayText([], $passport, null) === '');
$display = new ReflectionMethod($controller, 'displayValue');
$controllerTranslator = new ReflectionProperty($controller, 'translator');
$controllerTranslator->setValue($controller, $translator);
assert($display->invoke($controller, $listed['passport']) === '•••• зашифровано');
// A record whose title field is encrypted is named by its ID.
$title = (new ReflectionMethod($controller, 'recordTitle'))->invoke($controller, $state['records']['items'][0], $state['selected_structure']);
assert($title === $recordId, $title);
// The list of fields marks an encrypted field in its «Состояние» (QA 2026-10-22: nothing told it apart).
$schemaRows = array_column((new ReflectionMethod($controller, 'schemaRecords'))->invoke($controller, $state['selected_structure'], true, true), 'state', 'key');
assert($schemaRows['passport'] === 'шифруется' && $schemaRows['name'] === '', json_encode($schemaRows, JSON_UNESCAPED_UNICODE));

// 5. The CSV import skips an encrypted column, its values never in the plan; the record keeps its value.
$database = tempnam(sys_get_temp_dir(), 'larena-field-encryption-');
assert(is_string($database));
try {
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
    $connection = $capsule->getConnection();
    $import = new StorageCsvImport($workbench, new EncryptionTestScope(), $connection);
    $plan = $import->plan($admin, $structureId, "ID,Name,Passport\n{$recordId},Ada,TYPED-IN-FILE\n", ['record.create' => true, 'record.update' => true]);
    $columns = array_column($plan['columns'], 'skipped', 'header');
    assert($columns['Passport'] === 'column_encrypted' && $columns['Name'] === null, json_encode($plan['columns']));
    assert(!str_contains(json_encode($plan, JSON_UNESCAPED_UNICODE), 'TYPED-IN-FILE'));
    assert($ru['larena_storage']['section']['import']['skipped']['column_encrypted'] === 'зашифрованное поле, не импортируется'
        && $en['larena_storage']['section']['import']['skipped']['column_encrypted'] === 'an encrypted field, not imported');

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
    $layouts = new AdminRecordLayoutStore(\Larena\Setting\Runtime\ViewSettings::on($connection));

    // 6. The record panel (sf-property-view): an encrypted field is a write-only field that is set or not, with the
    // section's words; the answer holds no envelope, no ciphertext and no plaintext.
    $port = new StoragePropertyViewPort($gateway, $workbench, $layouts);
    $drawn = static function (array $answer): array {
        assert(($answer['answer'] ?? null) === 'applied', json_encode($answer, JSON_UNESCAPED_UNICODE));
        $fields = [];
        foreach ($answer['record']['sections'] as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['fields'] as $item) {
                    $fields[$item['key']] = $item;
                }
            }
        }

        return $fields;
    };
    $loaded = $port->raise($admin, 'scope:alpha', 'record.load', ['collection' => $structureId, 'record_id' => $recordId, 'reason' => 'connected']);
    $loadedJson = json_encode($loaded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    assert(!str_contains($loadedJson, StorageFieldEnvelope::MARKER) && !str_contains($loadedJson, (string) $replaced['c'])
        && !str_contains($loadedJson, 'SECRET') && !str_contains($loadedJson, 'fingerprint'));
    $panelFields = $drawn($loaded);
    assert($panelFields['passport']['propertyType'] === 'string' && $panelFields['passport']['variant'] === 'password'
        && $panelFields['passport']['value'] === ['set' => true]);
    assert($panelFields['passport']['hint'] === 'Зашифровано. Чтобы заменить, введите новое значение; пустое поле оставляет прежнее.');
    assert($panelFields['born']['variant'] === 'password' && $panelFields['name']['value'] === 'Ada L.');

    // Keep unchanged: a save that changes another field writes the stored envelope back, so Storage keeps the value.
    $revision = $workbench->readRecord('scope:alpha', $structureId, $recordId, $admin)->revision;
    $saved = $port->raise($admin, 'scope:alpha', 'record.save', ['collection' => $structureId, 'record_id' => $recordId, 'revision' => $revision,
        'values' => ['name' => 'Ada Lovelace']]);
    assert(($saved['answer'] ?? null) === 'applied', json_encode($saved, JSON_UNESCAPED_UNICODE));
    $sent = end($workbench->written);
    assert($sent['passport'] === $replaced && $sent['name'] === 'Ada Lovelace');
    assert($workbench->readRecord('scope:alpha', $structureId, $recordId, $admin)->values['passport'] === $replaced);
    assert(!str_contains(json_encode($saved, JSON_UNESCAPED_UNICODE), StorageFieldEnvelope::MARKER));
    // «Заменить»: a value the editor typed goes to Storage as typed and is sealed there.
    $revision++;
    $port->raise($editor, 'scope:alpha', 'group.save', ['collection' => $structureId, 'record_id' => $recordId, 'revision' => $revision,
        'section' => 'main', 'group' => 'main', 'values' => ['passport' => 'TYPED-BY-EDITOR']]);
    assert(end($workbench->written)['passport'] === 'TYPED-BY-EDITOR');
    // A value a field cannot take is refused under that field in the section's words, never as the general sentence
    // (QA 2026-10-22), and Storage is not asked.
    $writes = count($workbench->written);
    $refused = $port->raise($admin, 'scope:alpha', 'record.save', ['collection' => $structureId, 'record_id' => $recordId,
        'revision' => $workbench->readRecord('scope:alpha', $structureId, $recordId, $admin)->revision, 'values' => ['born' => 'yesterday']]);
    assert($refused['answer'] === 'refused' && $refused['reason'] === StorageSectionGateway::FIELD_VALUE_INVALID
        && $refused['errors'] === ['born' => 'Нужна дата.'], json_encode($refused, JSON_UNESCAPED_UNICODE));
    assert(count($workbench->written) === $writes);
    $after = $workbench->readRecord('scope:alpha', $structureId, $recordId, $admin)->values['passport'];
    assert(StorageFieldEnvelope::isEnvelope($after) && $after !== $replaced);
    // Storage's refusals of an encrypted value stand under the field in the section's words.
    $refusal = (new ReflectionMethod($port, 'reasonMessage'))->invoke($port, StorageEncryptionPolicy::KEY_UNAVAILABLE);
    assert($refusal === $ru['larena_storage']['section']['encryption']['errors']['storage_field_encryption_key_unavailable']);

    // 7. «Показать»: only for a person with decrypt; one field of one record; the plaintext goes back as text and a
    // refusal says why, never with a value. The reveal is Storage's (it checks the right and audits).
    $revealed = new EncryptionTestRevealLog();
    $storageReveal = static function (string $scope, string $structure, string $record, array $fields, string $actor) use ($revealed, $authorizer): array {
        $authorizer->assertAllowed($actor, 'storage.workbench.record.decrypt');
        $revealed->record([$scope, $structure, $record, $fields, $actor]);

        return $fields === ['born'] ? ['born' => '1815-12-10'] : ['passport' => 'TYPED-BY-EDITOR'];
    };
    $reveal = new StorageEncryptedFieldReveal(new EncryptionTestScope(), $workbench, $storageReveal, $authorizer);
    // The panel offers «Показать» to a person with decrypt only, and never without Storage's reveal.
    assert($reveal->mayReveal($admin) && !$reveal->mayReveal($editor));
    assert(!(new StorageEncryptedFieldReveal(new EncryptionTestScope(), $workbench, null, $authorizer))->mayReveal($admin));
    assert($reveal->reveal($admin, $structureId, $recordId, 'passport') === ['status' => 'ok', 'text' => 'TYPED-BY-EDITOR']);
    assert($revealed->take() === [['scope:alpha', $structureId, $recordId, ['passport'], $admin]]);
    assert($reveal->reveal($admin, $structureId, $recordId, 'born') === ['status' => 'ok', 'text' => '1815-12-10']);
    assert($reveal->reveal($editor, $structureId, $recordId, 'passport') === ['status' => 'denied', 'reason_code' => 'storage_workbench_access_denied']);
    // A field that is not encrypted, a malformed address or another scope reveals nothing and asks Storage nothing.
    $revealed->take();
    assert($reveal->reveal($admin, $structureId, $recordId, 'name') === ['status' => 'rejected', 'reason_code' => 'storage_field_reveal_invalid']);
    assert($reveal->reveal($admin, $structureId, 'record-x', 'passport')['reason_code'] === 'storage_field_reveal_invalid');
    assert($reveal->reveal('user:other:1', $structureId, $recordId, 'passport') === ['status' => 'denied', 'reason_code' => 'storage_workbench_scope_denied']);
    assert($revealed->take() === []);
    // Storage's refusal (a key that does not unwrap) and a missing reveal are said, not thrown.
    $broken = new StorageEncryptedFieldReveal(new EncryptionTestScope(), $workbench,
        static fn (): array => throw new StorageRejected(StorageEncryptionPolicy::KEY_UNAVAILABLE));
    assert($broken->reveal($admin, $structureId, $recordId, 'passport') === ['status' => 'rejected', 'reason_code' => StorageEncryptionPolicy::KEY_UNAVAILABLE]);
    assert((new StorageEncryptedFieldReveal(new EncryptionTestScope(), $workbench))->reveal($admin, $structureId, $recordId, 'passport')['status'] === 'unavailable');
    // The panel block offers «Показать» per field, holding the mask only; the address and the record ride beside it.
    $panel = new AdminRecordPanel(mode: 'view', title: 'Ada', fields: $workbench->structures[$structureId]->fields, values: $listed,
        closeUrl: '/admin/storage/workbench.people', writeUrl: '/admin/storage/workbench.people', writeFields: [], recordId: $recordId, revision: 1,
        context: ['encrypted' => ['reveal_url' => '/admin/storage/workbench.people/reveal', 'record_id' => $recordId,
            'fields' => [['key' => 'passport', 'label' => 'Паспорт']], 'masked' => '•••• зашифровано', 'show' => 'Показать', 'hide' => 'Скрыть',
            'title' => 'Зашифрованные поля', 'failed' => 'Не удалось показать значение.', 'audited' => 'Каждый просмотр записывается в журнал аудита.']]);
    $block = (string) (new \Larena\Admin\Runtime\AdminElementDocumentRenderer())->tree((new ReflectionMethod(AdminRecordPanelPresenter::class, 'sealedBlock'))->invoke(null, $panel));
    assert(str_contains($block, 'data-reveal-url="/admin/storage/workbench.people/reveal"') && str_contains($block, 'data-larena-reveal="passport"')
        && str_contains($block, '>Показать</button>') && str_contains($block, '•••• зашифровано'));
    assert(!str_contains($block, 'SECRET') && !str_contains($block, 'fingerprint') && !str_contains($block, StorageFieldEnvelope::MARKER));
    assert((new ReflectionMethod(AdminRecordPanelPresenter::class, 'sealedBlock'))
        ->invoke(null, new AdminRecordPanel(mode: 'view', title: 'Ada', fields: [], values: [], closeUrl: '/admin/storage', writeUrl: '/admin/storage',
            writeFields: [], recordId: $recordId, revision: 1)) === null);

    // 8. The field editor: «Шифровать» for a person who may manage encryption, with its words; closed search and
    // index on an encrypted field; the switch goes to Storage with the field's visibility; refusals in words.
    $fields = new StorageFieldPropertyViewPort($gateway, $layouts, $authorizer);
    $collection = StorageFieldPropertyViewPort::COLLECTION_PREFIX . $structureId;
    $create = $drawn($fields->raise($admin, 'scope:alpha', 'record.load', ['collection' => $collection, 'mode' => 'create', 'reason' => 'connected']));
    assert($create['encrypted']['label'] === 'Шифровать' && $create['encrypted']['propertyType'] === 'boolean' && !isset($create['encrypted']['readonly']));
    assert(str_starts_with($create['encrypted']['hint'], 'Значение нельзя искать, сортировать и фильтровать; прочитать его может только администратор.')
        && str_contains($create['encrypted']['hint'], 'Поле видно только в админке'));
    // Without the right to manage encryption it is not offered, and an encrypted field shows it read-only.
    $editorCreate = $drawn($fields->raise($editor, 'scope:alpha', 'record.load', ['collection' => $collection, 'mode' => 'create', 'reason' => 'connected']));
    assert(!isset($editorCreate['encrypted']));
    $editorPassport = $drawn($fields->raise($editor, 'scope:alpha', 'record.load', ['collection' => $collection, 'record_id' => 'passport', 'reason' => 'connected']));
    assert($editorPassport['encrypted']['value'] === true && ($editorPassport['encrypted']['readonly'] ?? false) === true);
    assert(!isset($drawn($fields->raise($editor, 'scope:alpha', 'record.load', ['collection' => $collection, 'record_id' => 'name', 'reason' => 'connected']))['encrypted']));
    $adminPassport = $drawn($fields->raise($admin, 'scope:alpha', 'record.load', ['collection' => $collection, 'record_id' => 'passport', 'reason' => 'connected']));
    assert($adminPassport['encrypted']['value'] === true && !isset($adminPassport['encrypted']['readonly']));
    assert($adminPassport['searchable']['value'] === false && ($adminPassport['searchable']['readonly'] ?? false) === true
        && $adminPassport['searchable']['hint'] === 'Недоступно для зашифрованного поля');
    assert($adminPassport['indexed']['value'] === false && ($adminPassport['indexed']['readonly'] ?? false) === true);
    // A type that cannot be encrypted is not offered the option.
    $workbench->put($structureId, [...$workbench->structures[$structureId]->fields, $field('kind', 'choice', 50, ['constraints' => ['options' => [['value' => 'a', 'label' => 'A']]]])],
        $workbench->structures[$structureId]->version);
    assert(!isset($drawn($fields->raise($admin, 'scope:alpha', 'record.load', ['collection' => $collection, 'record_id' => 'kind', 'reason' => 'connected']))['encrypted']));

    // A new encrypted field: admin visibility, never searched; searched and indexed asked by a stale form are dropped.
    $version = $workbench->structures[$structureId]->version;
    $answer = $fields->raise($admin, 'scope:alpha', 'record.create', ['collection' => $collection,
        'values' => ['label' => 'Phone', 'key' => 'phone', 'type' => 'string', 'encrypted' => true, 'searchable' => true]]);
    assert(($answer['answer'] ?? null) === 'applied', json_encode($answer, JSON_UNESCAPED_UNICODE));
    $phone = null;
    foreach ($workbench->structures[$structureId]->fields as $candidate) {
        $phone = $candidate['key'] === 'phone' ? $candidate : $phone;
    }
    assert(is_array($phone) && $phone['encrypted'] === true && $phone['visibility'] === 'admin' && $phone['searchable'] === false && !isset($phone['indexed']));
    assert($workbench->takeChanges() === ['update'] && $workbench->structures[$structureId]->version === $version + 1);
    // Switching an existing field (once confirmed) takes the migration with its visibility; switching it off makes it
    // public again.
    $version = $workbench->structures[$structureId]->version;
    $answer = $fields->raise($admin, 'scope:alpha', 'record.save', ['collection' => $collection, 'record_id' => 'note', 'revision' => $version,
        'values' => ['encrypted' => true, 'encryption_confirmed' => true]]);
    assert(($answer['answer'] ?? null) === 'applied', json_encode($answer, JSON_UNESCAPED_UNICODE));
    $note = $workbench->structures[$structureId]->fields[3];
    assert($note['key'] === 'note' && $note['encrypted'] === true && $note['visibility'] === 'admin' && $note['searchable'] === false);
    assert($workbench->takeChanges() === ['migrate']);
    $answer = $fields->raise($admin, 'scope:alpha', 'record.save', ['collection' => $collection, 'record_id' => 'note',
        'revision' => $workbench->structures[$structureId]->version, 'values' => ['encrypted' => false, 'encryption_confirmed' => true]]);
    $note = $workbench->structures[$structureId]->fields[3];
    assert(($answer['answer'] ?? null) === 'applied' && !isset($note['encrypted']) && $note['visibility'] === 'public');
    // An editor's form cannot switch it, whatever it sends.
    $answer = $fields->raise($editor, 'scope:alpha', 'record.save', ['collection' => $collection, 'record_id' => 'note',
        'revision' => $workbench->structures[$structureId]->version, 'values' => ['encrypted' => true]]);
    assert(($answer['answer'] ?? null) === 'refused' && $answer['reason'] === 'values_invalid');
    // A field with values on a site whose database has no encryption runs yet: Storage refuses the switch and the
    // panel says the database is not updated (no «next update» any more).
    $workbench->refuseChange = StorageEncryptionPolicy::REWRITE_REQUIRED;
    $answer = $fields->raise($admin, 'scope:alpha', 'record.save', ['collection' => $collection, 'record_id' => 'note',
        'revision' => $workbench->structures[$structureId]->version, 'values' => ['encrypted' => true, 'encryption_confirmed' => true]]);
    assert(($answer['answer'] ?? null) === 'refused' && $answer['reason'] === StorageEncryptionPolicy::REWRITE_REQUIRED, json_encode($answer, JSON_UNESCAPED_UNICODE));
    assert(str_starts_with($answer['errors']['encrypted'], 'Включить или выключить шифрование у заполненного поля на этом сайте сейчас нельзя: база данных не обновлена.'));
    assert(!str_contains($ru['larena_storage']['section']['encryption']['errors'][StorageEncryptionPolicy::REWRITE_REQUIRED], 'следующем обновлении')
        && !str_contains($en['larena_storage']['section']['encryption']['errors'][StorageEncryptionPolicy::REWRITE_REQUIRED], 'next update'));

    // 9. Every reason Storage gives about encryption passes through the gateway and has its words in Russian and English.
    assert(array_diff(StorageEncryptionPolicy::REASON_CODES, StorageSectionGateway::ENCRYPTION_REASONS) === []);
    foreach ([...StorageSectionGateway::ENCRYPTION_REASONS, 'storage_workbench_access_denied'] as $reason) {
        foreach (['ru' => $ru, 'en' => $en] as $language => $words) {
            $text = $words['larena_storage']['section']['encryption']['errors'][$reason] ?? null;
            assert(is_string($text) && trim($text) !== '', $language . ' ' . $reason);
        }
        $workbench->refuseChange = $reason;
        $receipt = $gateway->dispatch($admin, null, 'structure.configure', ['structure_id' => $structureId, 'label' => 'People',
            'expected_version' => $workbench->structures[$structureId]->version, 'fields' => [['key' => 'name', 'label' => 'Name']]]);
        assert($reason === 'storage_workbench_access_denied' || $receipt['reason_code'] === $reason, $reason . ' ' . json_encode($receipt));
        $workbench->refuseChange = null;
    }
    // The section's words about encryption exist in both languages, key for key.
    $keys = static function (array $words, string $prefix = '') use (&$keys): array {
        $found = [];
        foreach ($words as $key => $value) {
            $found = [...$found, ...(is_array($value) ? $keys($value, $prefix . $key . '.') : [$prefix . $key])];
        }

        return $found;
    };
    assert($keys($ru['larena_storage']['section']['encryption']) === $keys($en['larena_storage']['section']['encryption']));
    assert($en['larena_storage']['section']['encryption']['masked'] === '•••• encrypted' && $ru['larena_storage']['section']['encryption']['masked'] === '•••• зашифровано');
    Container::getInstance()->instance('translator', new EncryptionTestTranslator('en'));
    assert(StorageEncryptedFields::maskText() === '•••• encrypted');
    Container::getInstance()->instance('translator', $translator);
} finally {
    @unlink($database);
}

echo "StorageFieldEncryptionAdminTest passed.\n";
