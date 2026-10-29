<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\StorageFieldChangeState;
use Larena\Storage\Admin\StorageFieldPropertyViewPort;
use Larena\Storage\Admin\StorageSectionGateway;
use Larena\Storage\Admin\StorageStructurePropertyViewPort;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Admin\Runtime\PropertyViewPort;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Filesystem\ValueObjects\PersistentLogicalFileInspection;
use Larena\Setting\Persistence\DatabaseSettingValueRepository;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchFieldChange;
use Larena\Storage\Contracts\StorageWorkbenchPurgeReceipt;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordPage;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Exceptions\StorageRejected;

// A field change of a large storage runs in the background (Storage's `storage.schema.migrate` job). The
// section shows it: «Меняем поле «X»: проверено n из N» with its progress on the storage's pages, the
// structure editor closed meanwhile with the reason, the last failed change's reason in plain words,
// «индексируется…» beside a field whose list column is still filled, and Storage's refusal while a change
// runs as «Сейчас меняется поле «X». Дождитесь окончания». A save that started a background change says so.

/** The admin's own words, read from the package's language file. */
final class FieldChangeTranslator implements \Illuminate\Contracts\Translation\Translator
{
    /** @var array<string, mixed> */
    private array $admin;

    public function __construct(string $locale = 'ru')
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
        return 'ru';
    }

    public function setLocale($locale)
    {
    }
}

final class FieldChangeAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
    }
}

final class FieldChangeScope implements StorageSectionScope
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

final class FieldChangeInspector implements PersistentLogicalFileInspector
{
    public function inspect(string $logicalRef): PersistentLogicalFileInspection
    {
        throw new RuntimeException('not_used');
    }
}

/**
 * One storage of 12 000 records, as Storage answers it: a small change switches at once, a retype is
 * accepted and runs in the background (the structure comes back unchanged with the running change),
 * and while it runs every other structure change and archiving is refused.
 */
final class FieldChangeWorkbench implements StorageWorkbench
{
    public StorageWorkbenchStructure $structure;
    /** Refuse the next structure change as Storage does once a change began after the page read the storage. */
    public bool $refuseNext = false;

    public function __construct()
    {
        $this->structure = new StorageWorkbenchStructure('workbench.catalog', 'scope:alpha', 4, new StorageSchemaVersionRef('workbench.catalog', 4), 'Catalog', [
            ['key' => 'title', 'label' => 'Название', 'type' => 'string', 'position' => 10, 'required' => true],
            ['key' => 'price', 'label' => 'Цена', 'type' => 'string', 'position' => 20],
            ['key' => 'sku', 'label' => 'Артикул', 'type' => 'string', 'position' => 30],
        ], hash('sha256', 'catalog-4'), '2026-10-21T00:00:00Z');
    }

    /** The storage as Storage states it: its витрина and its field change. @param list<string> $pending */
    public function state(?string $view, bool $backfill, array $pending, ?StorageWorkbenchFieldChange $change): void
    {
        $this->structure = $this->structure->withState($view, $backfill, $pending, $change);
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
        $this->refuseWhileChanging();
        $s = $this->structure;

        return $this->structure = new StorageWorkbenchStructure($s->structureId, $s->scopeRef, $s->version + 1, $s->schema, (string) $descriptor['label'],
            $descriptor['fields'], $s->descriptorHash, $s->updatedAt, viewState: $s->viewState);
    }

    public function migrateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $this->refuseWhileChanging();
        $this->state('ready', false, [], new StorageWorkbenchFieldChange('storage-field-change-' . str_repeat('a', 32), StorageWorkbenchFieldChange::STATE_RUNNING,
            0, 12000, ['price'], null, '2026-10-21T10:00:00Z'));

        return $this->structure;
    }

    public function readStructure(string $scopeRef, string $structureId, string $actor): StorageWorkbenchStructure
    {
        return $structureId === $this->structure->structureId ? $this->structure : throw new StorageRejected('storage_structure_missing');
    }

    public function listStructures(string $scopeRef, string $actor, bool $includeArchived = false): array
    {
        return [$this->structure];
    }

    public function setHistoryKeep(string $scopeRef, string $structureId, ?int $keep, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->structure;
    }

    /** Whether a cancel stopped the running change; a second one finds it ended. */
    private bool $cancelled = false;
    /** How many cancels stopped a change since the last call. */
    private int $stops = 0;

    /**
     * How many cancels stopped a change since the last call; taking them clears them.
     *
     * @phpstan-impure
     */
    public function takeStops(): int
    {
        [$stops, $this->stops] = [$this->stops, 0];

        return $stops;
    }

    public function cancelFieldChange(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        if ($this->cancelled || !$this->structure->changing()) {
            throw new StorageRejected('storage_workbench_field_change_not_running');
        }
        $this->cancelled = true;
        $this->stops++;

        return $this->structure;
    }

    public function archiveStructure(string $scopeRef, string $structureId, int $expectedVersion, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $this->refuseWhileChanging();

        return $this->structure;
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
        throw new StorageRejected('storage_record_missing');
    }

    public function listRecords(StorageWorkbenchRecordQuery $query, string $actor): StorageWorkbenchRecordPage
    {
        return new StorageWorkbenchRecordPage([], null, 12000, true);
    }

    public function recordHistory(string $scopeRef, string $structureId, string $recordId, string $actor, int $limit = 50): array
    {
        return [];
    }

    private function refuseWhileChanging(): void
    {
        if ($this->structure->changing() || $this->refuseNext) {
            $this->refuseNext = false;
            throw new StorageRejected('storage_workbench_field_change_running');
        }
    }
}

$translator = new FieldChangeTranslator();
Container::getInstance()->instance('translator', $translator);
$workbench = new FieldChangeWorkbench();
$gateway = new StorageSectionGateway(new FieldChangeAuthorizer(), new FieldChangeScope(), $workbench, new FieldChangeInspector());
$admin = 'user:admin_identity:1';
$section = ['surface' => StorageSectionGateway::SURFACE, 'structure_id' => 'workbench.catalog'];
$selected = static fn (): array => $gateway->snapshot($admin, null, $section)['selected_structure'];
$state = new StorageFieldChangeState($translator);
$nbsp = "\u{00A0}";

// A storage at rest: a ready витрина, no change; the section has nothing to say.
$workbench->state('ready', false, [], null);
$rest = $selected();
assert($rest['view_state'] === 'ready' && $rest['view_backfill_pending'] === false && $rest['view_pending_fields'] === []);
assert($rest['field_change'] === null && $rest['changing'] === false);
assert($state->notice($rest) === null && $state->locked($rest) === null && $state->backfill($rest) === null && $state->fieldMarks($rest) === []);
assert(!StorageFieldChangeState::changing($rest));

// A small change switches at once: the receipt is the usual one.
$configure = static fn (array $fields, bool $migration): array => $gateway->dispatch($admin, null, 'structure.configure', [
    'structure_id' => 'workbench.catalog', 'label' => 'Catalog', 'expected_version' => $workbench->structure->version,
    'fields' => $fields, 'confirm_migration' => $migration ? '1' : '0']);
$renamed = $configure([['key' => 'sku', 'label' => 'Код']], false);
assert($renamed['status'] === 'ok' && $renamed['reason_code'] === 'storage_workbench_action_applied');
assert($renamed['redirect'] === ['scope_ref' => 'scope:alpha', 'structure_id' => 'workbench.catalog']);

// A retype of a large storage goes on in the background: the save is accepted and says so.
$retyped = $configure([['key' => 'price', 'label' => 'Цена', 'type' => 'number']], true);
assert($retyped['status'] === 'ok' && $retyped['reason_code'] === StorageSectionGateway::FIELD_CHANGE_STARTED, json_encode($retyped));
assert($retyped['redirect'] === ['scope_ref' => 'scope:alpha', 'structure_id' => 'workbench.catalog'], 'the change state is not an address');
assert($state->started() === 'Изменение поля идёт в фоне — список работает как обычно.');

// While it runs: the storage carries the change with its progress, and refuses any other structure change.
$workbench->state('ready', false, ['price'], new StorageWorkbenchFieldChange('storage-field-change-' . str_repeat('a', 32), 'running', 1200, 12000, ['price'], null, '2026-10-21T10:00:00Z'));
$running = $selected();
assert($running['changing'] === true && $running['field_change'] === ['change_ref' => 'storage-field-change-' . str_repeat('a', 32), 'state' => 'running',
    'checked' => 1200, 'total' => 12000, 'fields' => ['price'], 'reason' => null, 'started_at' => '2026-10-21T10:00:00Z', 'finished_at' => null]);
$notice = $state->notice($running);
assert($notice !== null && $notice['kind'] === 'running' && $notice['tone'] === 'info');
assert($notice['title'] === "Меняем поле «Цена»: проверено 1{$nbsp}200 из 12{$nbsp}000", $notice['title']);
assert($notice['text'] === 'Изменение поля идёт в фоне — список работает как обычно.');
assert($notice['progress'] === ['value' => 1200, 'max' => 12000, 'label' => 'Проверено записей']);
assert($state->locked($running) === 'Пока поле меняется, структуру хранилища менять и архивировать хранилище временно нельзя.');
assert($state->fieldMarks($running) === ['price' => 'меняется…'], 'the changing field is marked as changing, not as indexing');
foreach ([
    $configure([['key' => 'sku', 'label' => 'Артикул']], false),
    $gateway->dispatch($admin, null, 'structure.archive', ['structure_id' => 'workbench.catalog', 'expected_version' => 5, 'confirmed' => '1']),
] as $refused) {
    assert($refused['status'] === 'rejected' && $refused['reason_code'] === StorageSectionGateway::FIELD_CHANGE_RUNNING, json_encode($refused));
}
assert($state->refusal(StorageSectionGateway::FIELD_CHANGE_RUNNING, $running) === 'Сейчас меняется поле «Цена». Дождитесь окончания.');
assert($state->refusal(StorageSectionGateway::FIELD_CHANGE_RUNNING, null) === 'Сейчас меняется поле хранилища. Дождитесь окончания.');
assert($state->refusal('storage_workbench_action_rejected', $running) === null, 'other refusals keep their own words');
// «Отменить изменение» asks first, naming the field; after it the field stayed as it was, and a cancel
// that came after the change ended says so.
assert($state->cancelOffer($running) === ['label' => 'Отменить изменение', 'question' => 'Отменить изменение поля «Цена»? Записи останутся прежними.']);
assert($state->cancelDone() === 'Изменение поля отменено. Поле осталось прежним.' && $state->cancelEnded() === 'Изменение уже закончилось');
assert($state->cancelOffer($rest) === null, 'nothing to cancel at rest');
$cancelReceipt = $gateway->dispatch($admin, null, 'structure.field_change_cancel', ['structure_id' => 'workbench.catalog', 'confirmed' => '1']);
assert($cancelReceipt['status'] === 'ok' && $cancelReceipt['reason_code'] === StorageSectionGateway::FIELD_CHANGE_CANCELLED && $workbench->takeStops() === 1);
$endedReceipt = $gateway->dispatch($admin, null, 'structure.field_change_cancel', ['structure_id' => 'workbench.catalog', 'confirmed' => '1']);
assert($endedReceipt['status'] === 'rejected' && $endedReceipt['reason_code'] === StorageSectionGateway::FIELD_CHANGE_NOT_RUNNING && $workbench->takeStops() === 0);
// Several fields, and a change that has not counted its records yet.
$workbench->state('ready', false, [], new StorageWorkbenchFieldChange('storage-field-change-' . str_repeat('b', 32), 'running', 0, 0, ['price', 'sku'], null, '2026-10-21T10:00:00Z'));
$starting = $selected();
assert($state->notice($starting)['title'] === 'Меняем поля «Цена», «Код»: начинаем проверку записей' && $state->notice($starting)['progress'] === null);
assert($state->refusal(StorageSectionGateway::FIELD_CHANGE_RUNNING, $starting) === 'Сейчас меняются поля «Цена», «Код». Дождитесь окончания.');
assert($state->cancelOffer($starting)['question'] === 'Отменить изменение полей «Цена», «Код»? Записи останутся прежними.');

// A failed change: its reason in plain words, known reasons in their own, any other in general ones.
$failed = static function (?string $reason) use ($workbench, $selected): array {
    $workbench->state('ready', false, [], new StorageWorkbenchFieldChange('storage-field-change-' . str_repeat('c', 32), 'failed', 3000, 12000, ['price'], $reason,
        '2026-10-21T10:00:00Z', '2026-10-21T10:05:00Z'));

    return $selected();
};
$unconvertible = $state->notice($failed('storage_schema_migration_record_unconvertible'));
assert($unconvertible['kind'] === 'failed' && $unconvertible['tone'] === 'danger' && $unconvertible['title'] === 'Поле «Цена» не изменилось');
assert($unconvertible['text'] === 'Некоторые записи не подходят под новые настройки поля, поэтому ничего не изменилось. Исправьте эти записи или выберите другие настройки.');
assert($state->notice($failed('storage_field_change_schema_moved'))['text'] === 'Хранилище изменили, пока шла проверка записей, поэтому ничего не изменилось. Повторите изменение.');
foreach (['storage_field_change_failed', 'storage_schema_migration_conflict', null] as $reason) {
    assert($state->notice($failed($reason))['text'] === 'Проверка записей не завершилась, поэтому ничего не изменилось. Повторите изменение позже.', (string) $reason);
}
assert($failed(null)['changing'] === false && $state->locked($failed(null)) === null, 'a failed change leaves the structure open to change');
// A cancelled one says the field stayed as it was; one that ended well says nothing.
$workbench->state('ready', false, [], new StorageWorkbenchFieldChange('storage-field-change-' . str_repeat('d', 32), 'cancelled', 10, 12000, ['price'], null, '2026-10-21T10:00:00Z', '2026-10-21T10:01:00Z'));
$cancelled = $state->notice($selected());
assert($cancelled['kind'] === 'cancelled' && $cancelled['tone'] === 'warning' && $cancelled['title'] === 'Изменение поля «Цена» отменено');
$workbench->state('ready', false, [], new StorageWorkbenchFieldChange('storage-field-change-' . str_repeat('e', 32), 'done', 12000, 12000, ['price'], null, '2026-10-21T10:00:00Z', '2026-10-21T10:09:00Z'));
assert($state->notice($selected()) === null);

// A new column still being filled: «индексируется…» beside the field, and a note over the list.
$workbench->state('ready', true, ['sku'], null);
$filling = $selected();
assert($filling['view_backfill_pending'] === true && $filling['view_pending_fields'] === ['sku']);
assert($state->fieldMarks($filling) === ['sku' => 'индексируется…'] && $state->notice($filling) === null && $state->locked($filling) === null);
assert(str_starts_with((string) $state->backfill($filling), 'Список хранилища дозаполняется после изменения полей'));
assert($state->columnLabel() === 'Состояние');

// Every word exists in English too, under the same keys.
$russian = storage_admin_words('ru');
$english = storage_admin_words('en');
$keys = static function (array $words, string $prefix = '') use (&$keys): array {
    $found = [];
    foreach ($words as $key => $value) {
        $found = [...$found, ...(is_array($value) ? $keys($value, $prefix . $key . '.') : [$prefix . $key])];
    }

    return $found;
};
assert($keys($russian['larena_storage']['section']['field_change']) === $keys($english['larena_storage']['section']['field_change']));
$englishState = new StorageFieldChangeState(new FieldChangeTranslator('en'));
assert($englishState->refusal(StorageSectionGateway::FIELD_CHANGE_RUNNING, $running) === 'The field «Цена» is being changed now. Wait until it ends.');
assert($englishState->cancelOffer($running) === ['label' => 'Cancel the change', 'question' => 'Cancel the change of the field «Цена»? The records stay as they are.']);

// The field and storage panels (sf-property-view over their ports) say the same and refuse to change meanwhile.
$database = tempnam(sys_get_temp_dir(), 'larena-field-change-');
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
    $layouts = new AdminRecordLayoutStore(\Larena\Setting\Runtime\ViewSettings::on($connection));
    $fieldPort = new StorageFieldPropertyViewPort($gateway, $layouts);
    $storagePort = new StorageStructurePropertyViewPort($gateway, $layouts);
    $scope = 'scope:alpha';
    $collection = StorageFieldPropertyViewPort::COLLECTION_PREFIX . 'workbench.catalog';
    /** @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>} */
    $load = static function (PropertyViewPort $port, string $collection, string $key) use ($admin, $scope): array {
        $answer = $port->raise($admin, $scope, 'record.load', ['collection' => $collection, 'record_id' => $key, 'reason' => 'connected']);
        assert(($answer['answer'] ?? null) === 'applied', json_encode($answer, JSON_UNESCAPED_UNICODE));
        $fields = [];
        foreach ($answer['record']['sections'] as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    $fields[$field['key']] = $field;
                }
            }
        }

        return [$answer['record'], $fields];
    };

    // At rest the field opens for editing and says nothing more.
    $workbench->state('ready', false, [], null);
    [$record, $fields] = $load($fieldPort, $collection, 'price');
    assert(in_array('record.edit', $record['actions'], true) && !isset($fields['field_state']));

    // A field still indexed says so, and stays open for editing.
    $workbench->state('ready', true, ['sku'], null);
    [$record, $fields] = $load($fieldPort, $collection, 'sku');
    assert(in_array('record.edit', $record['actions'], true));
    assert($fields['field_state']['value'] === 'индексируется…' && ($fields['field_state']['readonly'] ?? false) === true && $fields['field_state']['label'] === 'Состояние');

    // While a change runs the panel shows its progress and why it is closed; a save and a new field are refused in words.
    $workbench->state('ready', false, ['price'], new StorageWorkbenchFieldChange('storage-field-change-' . str_repeat('a', 32), 'running', 1200, 12000, ['price'], null, '2026-10-21T10:00:00Z'));
    [$record, $fields] = $load($fieldPort, $collection, 'title');
    assert($record['actions'] === [], 'neither edit nor delete while the change runs');
    assert($fields['field_state']['value'] === "Меняем поле «Цена»: проверено 1{$nbsp}200 из 12{$nbsp}000. Изменение поля идёт в фоне — список работает как обычно.\n"
        . 'Пока поле меняется, структуру хранилища менять и архивировать хранилище временно нельзя.', $fields['field_state']['value']);
    [, $fields] = $load($fieldPort, $collection, 'price');
    assert(str_ends_with($fields['field_state']['value'], "\nиндексируется…"), 'the changing field is also marked while its new column fills');
    $saved = $fieldPort->raise($admin, $scope, 'record.save', ['collection' => $collection, 'record_id' => 'title', 'revision' => $record['revision'], 'values' => ['label' => 'Имя']]);
    assert($saved['answer'] === 'refused' && $saved['reason'] === 'edit_denied' && $saved['message'] === 'Сейчас меняется поле «Цена». Дождитесь окончания.', json_encode($saved, JSON_UNESCAPED_UNICODE));
    $deleted = $fieldPort->raise($admin, $scope, 'record.delete', ['collection' => $collection, 'record_id' => 'sku', 'revision' => $record['revision']]);
    assert($deleted['answer'] === 'refused' && $deleted['message'] === 'Сейчас меняется поле «Цена». Дождитесь окончания.');
    $created = $fieldPort->raise($admin, $scope, 'record.create', ['collection' => $collection, 'values' => ['label' => 'Вес', 'key' => 'weight', 'type' => 'number']]);
    assert($created['answer'] === 'refused' && $created['reason'] === 'create_denied' && $created['message'] === 'Сейчас меняется поле «Цена». Дождитесь окончания.');
    assert($workbench->structure->version === 5 && count($workbench->structure->fields) === 3, 'nothing reached the storage');

    // The storage's own panel (its name, menu group, versions kept) is closed the same way.
    [$record, $fields] = $load($storagePort, StorageStructurePropertyViewPort::COLLECTION, 'workbench.catalog');
    assert($record['actions'] === [] && str_starts_with($fields['field_state']['value'], 'Меняем поле «Цена»'));
    $renamed = $storagePort->raise($admin, $scope, 'record.save', ['collection' => StorageStructurePropertyViewPort::COLLECTION, 'record_id' => 'workbench.catalog',
        'revision' => $record['revision'], 'values' => ['label' => 'Каталог']]);
    assert($renamed['answer'] === 'refused' && $renamed['message'] === 'Сейчас меняется поле «Цена». Дождитесь окончания.');

    // A failed change is shown in the panel, which opens for editing again.
    $failed('storage_schema_migration_record_unconvertible');
    [$record, $fields] = $load($fieldPort, $collection, 'price');
    assert(in_array('record.edit', $record['actions'], true) && str_starts_with($fields['field_state']['value'], 'Поле «Цена» не изменилось. Некоторые записи не подходят'));
    // Storage's own refusal (a change began between the read and the write) is said in words too.
    $workbench->state('ready', false, [], null);
    [$record] = $load($fieldPort, $collection, 'price');
    $workbench->refuseNext = true;
    $raced = $fieldPort->raise($admin, $scope, 'record.save', ['collection' => $collection, 'record_id' => 'price', 'revision' => $record['revision'], 'values' => ['label' => 'Стоимость']]);
    assert($raced['answer'] === 'refused' && $raced['reason'] === StorageSectionGateway::FIELD_CHANGE_RUNNING, json_encode($raced, JSON_UNESCAPED_UNICODE));
    assert(($raced['errors']['label'] ?? null) === 'Сейчас меняется поле хранилища. Дождитесь окончания.', json_encode($raced, JSON_UNESCAPED_UNICODE));

    // «Свойства хранилища» (QA 2026-10-22): «Хранить изменений» −3 is refused under its field before anything is written,
    // so the panel's revision still holds and the corrected save is no conflict; an unchanged name moves no version.
    [$record] = $load($storagePort, StorageStructurePropertyViewPort::COLLECTION, 'workbench.catalog');
    $version = $workbench->structure->version;
    $negative = $storagePort->raise($admin, $scope, 'record.save', ['collection' => StorageStructurePropertyViewPort::COLLECTION, 'record_id' => 'workbench.catalog',
        'revision' => $record['revision'], 'values' => ['label' => 'Каталог 2', 'history_keep' => -3]]);
    assert($negative['answer'] === 'refused' && $negative['reason'] === StorageStructurePropertyViewPort::HISTORY_KEEP_INVALID
        && $negative['errors'] === ['history_keep' => 'Целое число от 1 до 1000 или пусто, чтобы хранить все изменения.'], json_encode($negative, JSON_UNESCAPED_UNICODE));
    assert($workbench->structure->version === $version && $workbench->structure->label !== 'Каталог 2', 'nothing was written');
    $kept = $storagePort->raise($admin, $scope, 'record.save', ['collection' => StorageStructurePropertyViewPort::COLLECTION, 'record_id' => 'workbench.catalog',
        'revision' => $record['revision'], 'values' => ['history_keep' => 5]]);
    assert($kept['answer'] === 'applied', json_encode($kept, JSON_UNESCAPED_UNICODE));
    assert($workbench->structure->version === $version, 'the name did not change, so no version moved');
} finally {
    @unlink($database);
}

echo "StorageFieldChangeStateTest passed.\n";
