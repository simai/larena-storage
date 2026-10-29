<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\StorageFieldPropertyViewPort;
use Larena\Storage\Admin\StorageSectionGateway;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Setting\Persistence\DatabaseSettingValueRepository;

// The field editor of a storage (sf-property-view over storage.fields.<structure>) offers a field's search and
// index options as it offers «Hidden»: «Участвует в поиске» on a string or text (on unless the field says
// otherwise), «Быстрая сортировка и фильтр» on a string, open to change only while its maximum length is at
// most 255. They go to the storage's change only for a field that can take them, and Storage's refusals of
// them come back in the section's words.

/** The admin's own Russian words, read from the package's language file. */
final class FieldOptionsTranslator implements \Illuminate\Contracts\Translation\Translator
{
    /** @var array<string, mixed> */
    private array $admin;

    public function __construct()
    {
        $this->admin = storage_admin_words('ru');
    }

    public function get($key, array $replace = [], $locale = null)
    {
        $asked = $key;
        $key = storage_admin_key((string) $key);
        if (str_starts_with($key, 'larena-property::settings.')) {
            $settings = require dirname(__DIR__, 3) . '/property/resources/lang/ru/settings.php';

            return $settings[substr($key, strlen('larena-property::settings.'))] ?? $key;
        }
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

/** One storage with a field of each kind the options speak of; it remembers each change it was given. */
final class FieldOptionsGateway implements StorageWorkbenchGateway
{
    /** @var list<array<string, mixed>> */
    public array $fields = [
        ['key' => 'title', 'label' => 'Title', 'type' => 'string', 'position' => 10, 'constraints' => ['max_length' => 120]],
        ['key' => 'body', 'label' => 'Body', 'type' => 'text', 'position' => 20, 'constraints' => [], 'searchable' => false],
        ['key' => 'code', 'label' => 'Code', 'type' => 'string', 'position' => 30, 'constraints' => []],
        ['key' => 'summary', 'label' => 'Summary', 'type' => 'string', 'position' => 40, 'constraints' => ['max_length' => 500]],
        ['key' => 'sku', 'label' => 'SKU', 'type' => 'string', 'position' => 50, 'constraints' => ['max_length' => 64], 'indexed' => true],
        ['key' => 'views', 'label' => 'Views', 'type' => 'integer', 'position' => 60, 'constraints' => []],
        ['key' => 'section', 'label' => 'Section', 'type' => 'record', 'position' => 70, 'constraints' => ['target_structure_id' => 'workbench.sections']],
    ];
    /** @var list<array<string, mixed>> */
    private array $dispatched = [];
    public ?string $refuse = null;

    public function forget(): void
    {
        $this->dispatched = [];
    }

    public function heard(): bool
    {
        return $this->dispatched !== [];
    }

    /** The field the last change named, as the storage was given it. @return array<string, mixed>|null */
    public function change(): ?array
    {
        $last = end($this->dispatched);
        $field = is_array($last) && is_array($last['fields'] ?? null) ? ($last['fields'][0] ?? null) : null;

        return is_array($field) ? $field : null;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $last = end($this->dispatched);

        return is_array($last) ? $last : [];
    }

    public function snapshot(string $actor, ?string $requestedScope, array $query = []): array
    {
        return ['status' => 'ok', 'permissions' => ['structure.configure' => true, 'structure.migrate' => true],
            'selected_structure' => ['structure_id' => 'workbench.catalog', 'label' => 'Catalog', 'version' => 3, 'archived' => false, 'fields' => $this->fields],
            'structures' => [
                ['structure_id' => 'workbench.catalog', 'label' => 'Catalog', 'archived' => false],
                ['structure_id' => 'workbench.sections', 'label' => 'Sections', 'archived' => false],
                ['structure_id' => 'workbench.old', 'label' => 'Old', 'archived' => true],
            ]];
    }

    public function dispatch(string $actor, ?string $requestedScope, string $action, array $payload): array
    {
        $this->dispatched[] = ['action' => $action] + $payload;
        if ($this->refuse !== null) {
            return ['status' => 'rejected', 'reason_code' => $this->refuse];
        }

        return ['status' => 'ok', 'reason_code' => 'storage_workbench_action_applied'];
    }
}

Container::getInstance()->instance('translator', new FieldOptionsTranslator());
$database = tempnam(sys_get_temp_dir(), 'larena-field-options-');
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
    $storage = new FieldOptionsGateway();
    $port = new StorageFieldPropertyViewPort($storage, new AdminRecordLayoutStore(\Larena\Setting\Runtime\ViewSettings::on($connection)));
    $actor = 'user:admin_identity:1';
    $scope = 'scope:alpha';
    $collection = StorageFieldPropertyViewPort::COLLECTION_PREFIX . 'workbench.catalog';

    /** The fields the panel draws, by key. @return array<string, array<string, mixed>> */
    $drawn = static function (array $answer): array {
        assert(($answer['answer'] ?? null) === 'applied', json_encode($answer, JSON_UNESCAPED_UNICODE));
        $fields = [];
        foreach ($answer['record']['sections'] as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['fields'] as $field) {
                    $fields[$field['key']] = $field;
                }
            }
        }

        return $fields;
    };
    $load = static fn (string $key): array => $drawn($port->raise($actor, $scope, 'record.load', ['collection' => $collection, 'record_id' => $key, 'reason' => 'connected']));
    $save = static function (string $key, array $values) use ($port, $storage, $actor, $scope, $collection): array {
        $storage->forget();
        $answer = $port->raise($actor, $scope, 'record.save', ['collection' => $collection, 'record_id' => $key, 'revision' => 3, 'values' => $values]);

        return [$answer, $storage->change() ?? []];
    };

    // Defaults: a string says nothing, so it is searched and not indexed; both are offered under their own words.
    $title = $load('title');
    assert($title['searchable']['label'] === 'Участвует в поиске' && $title['searchable']['propertyType'] === 'boolean' && $title['searchable']['value'] === true);
    assert($title['indexed']['label'] === 'Быстрая сортировка и фильтр' && $title['indexed']['value'] === false);
    assert(!isset($title['indexed']['readonly']) && !isset($title['indexed']['hint']));
    // A field's stored options read back as they are.
    assert($load('body')['searchable']['value'] === false && $load('sku')['indexed']['value'] === true);
    // A text is searched but never indexed; a number or another type is offered neither.
    assert(!isset($load('body')['indexed']));
    $views = $load('views');
    assert(!isset($views['searchable']) && !isset($views['indexed']) && isset($views['hidden']));
    // A string without a maximum length, or a longer one, shows the index switch closed, with the reason.
    foreach (['code', 'summary'] as $key) {
        $closed = $load($key)['indexed'];
        assert(($closed['readonly'] ?? false) === true && $closed['hint'] === 'Нужна максимальная длина не больше 255', $key);
    }

    // Saving each option sends it with the field, beside what it already had.
    [$answer, $change] = $save('title', ['indexed' => true]);
    // A change that touches no data goes unconfirmed; the section decides from Property whether it does.
    assert($answer['answer'] === 'applied' && $storage->payload()['action'] === 'structure.configure' && $storage->payload()['confirm_migration'] === '0');
    assert($change['key'] === 'title' && $change['indexed'] === true && $change['searchable'] === true && $change['hidden'] === false && $change['type'] === 'string');
    [, $change] = $save('title', ['searchable' => false]);
    assert($change['searchable'] === false && $change['indexed'] === false);
    [, $change] = $save('body', ['searchable' => '1']);
    assert($change['searchable'] === true && !array_key_exists('indexed', $change));
    [, $change] = $save('sku', ['indexed' => false]);
    assert($change['indexed'] === false);
    // A string whose length an index cannot take never sends «indexed», whatever a stale page says.
    [, $change] = $save('code', ['indexed' => true, 'label' => 'Code']);
    assert(!array_key_exists('indexed', $change) && $change['searchable'] === true);
    // An unsuitable type is not offered, so a value for it is refused before the storage hears of it.
    [$answer] = $save('views', ['searchable' => false]);
    assert($answer['answer'] === 'refused' && $answer['reason'] === 'values_invalid' && !$storage->heard());
    // Retyped away from string and text, the field sends neither option; to text it stays searched, never indexed.
    [, $change] = $save('title', ['type' => 'integer']);
    assert($change['type'] === 'integer' && !array_key_exists('searchable', $change) && !array_key_exists('indexed', $change));
    [, $change] = $save('title', ['type' => 'text']);
    assert($change['type'] === 'text' && $change['searchable'] === true && !array_key_exists('indexed', $change));

    // A new field: its type is chosen in the same form, so «searched» is offered (on), «indexed» is not
    // (a new string has no maximum length yet), and the option goes only with a string or text.
    $created = $drawn($port->raise($actor, $scope, 'record.load', ['collection' => $collection, 'mode' => 'create', 'reason' => 'connected']));
    assert($created['searchable']['value'] === true && $created['searchable']['hint'] === 'Для полей типа «Строка» и «Текст».' && !isset($created['indexed']));
    $create = static function (array $values) use ($port, $storage, $actor, $scope, $collection): array {
        $storage->forget();
        $port->raise($actor, $scope, 'record.create', ['collection' => $collection, 'values' => $values]);

        return $storage->change() ?? [];
    };
    $change = $create(['label' => 'Rating', 'key' => 'rating', 'type' => 'integer']);
    assert($change['key'] === 'rating' && !array_key_exists('searchable', $change) && !array_key_exists('indexed', $change));
    $change = $create(['label' => 'Note', 'key' => 'note', 'type' => 'text', 'searchable' => false]);
    assert($change['searchable'] === false && !array_key_exists('indexed', $change));
    $change = $create(['label' => 'Name', 'key' => 'name', 'type' => 'string']);
    assert($change['searchable'] === true);

    // Storage's refusals of the options stand under the field in the section's words.
    $messages = [
        'storage_field_option_invalid' => 'Настройки поиска и сортировки заданы неверно. Откройте поле заново и сохраните ещё раз.',
        'storage_field_searchable_type_unsupported' => 'Участвовать в поиске могут только поля типа «Строка» и «Текст».',
        'storage_field_indexed_type_unsupported' => 'Быструю сортировку и фильтр можно включить только для поля типа «Строка».',
        'storage_field_indexed_max_length_invalid' => 'Для быстрой сортировки и фильтра нужна максимальная длина строки не больше 255.',
        'storage_field_index_limit_exceeded' => 'В хранилище уже 40 полей с быстрой сортировкой и фильтром — больше нельзя. Выключите её у другого поля.',
    ];
    $english = storage_admin_words('en');
    assert(is_array($english['larena_storage']['section']['field_option_errors'] ?? null));
    // Each reason the gateway passes through has its words, in Russian and in English.
    assert(array_diff(StorageSectionGateway::FIELD_OPTION_REASONS, array_keys($messages), array_keys($english['larena_storage']['section']['field_option_errors'])) === []);
    foreach ($messages as $reason => $message) {
        $storage->refuse = $reason;
        [$answer] = $save('title', ['indexed' => true]);
        assert($answer['answer'] === 'refused' && $answer['reason'] === $reason && in_array($message, $answer['errors'], true), $reason);
        assert(($answer['errors']['indexed'] ?? null) === $message, $reason);
    }
    $storage->refuse = null;
    // A «Storage record» field (QA 2026-10-22: it could not be made). A new field picks the storage it points to among
    // the active storages of the scope, searched (a lookup over storage.structures); an existing one shows it read only.
    assert($created['target_structure_id']['propertyType'] === 'lookup' && $created['target_structure_id']['source'] === 'storage.structures'
        && !isset($created['target_structure_id']['readonly']));
    $targets = $port->raise($actor, $scope, 'field.options', ['collection' => $collection, 'record_id' => null, 'field' => 'target_structure_id', 'search' => '']);
    assert($targets['answer'] === 'applied' && array_column($targets['options_page']['items'], 'label', 'value') === ['workbench.catalog' => 'Catalog', 'workbench.sections' => 'Sections']);
    $searched = $port->raise($actor, $scope, 'field.options', ['collection' => $collection, 'record_id' => null, 'field' => 'target_structure_id', 'search' => 'sec']);
    assert(array_column($searched['options_page']['items'], 'value') === ['workbench.sections']);
    $section = $load('section');
    assert(($section['target_structure_id']['readonly'] ?? false) === true && $section['target_structure_id']['value']['value'] === 'workbench.sections');
    $change = $create(['label' => 'Section 2', 'key' => 'section_two', 'type' => 'record', 'target_structure_id' => ['value' => 'workbench.sections', 'label' => 'Sections']]);
    assert($change['type'] === 'record' && $change['settings']['target_structure_id'] === 'workbench.sections');
    // The target goes with a record field only, and an existing field keeps its own (locked after creation).
    $change = $create(['label' => 'Plain', 'key' => 'plain', 'type' => 'string', 'target_structure_id' => 'workbench.sections']);
    assert(!isset($change['settings']['target_structure_id']));
    [, $change] = $save('section', ['target_structure_id' => 'workbench.catalog']);
    assert(!isset($change['settings']['target_structure_id']));

    // «Максимальная длина» (QA 2026-10-22: the index hint asked for it and the form had none): Property's setting of a
    // string, sent with a string only; with it at most 255 the same save may switch the index on.
    assert($created['max_length']['propertyType'] === 'integer' && $created['max_length']['label'] === 'Максимальная длина');
    assert($load('title')['max_length']['value'] === 120 && $load('code')['max_length']['value'] === null);
    $change = $create(['label' => 'Article', 'key' => 'article', 'type' => 'string', 'max_length' => 80]);
    assert($change['settings']['max_length'] === 80);
    $change = $create(['label' => 'Count', 'key' => 'count_of', 'type' => 'integer', 'max_length' => 80]);
    assert(!isset($change['settings']['max_length']));
    // A string without a limit gets one (its index switch opens once it is saved); a limit an index cannot take never
    // sends «indexed» in the same save.
    [, $change] = $save('code', ['max_length' => 100]);
    assert($change['settings']['max_length'] === 100 && ($change['indexed'] ?? false) === false);
    [, $change] = $save('title', ['max_length' => 300, 'indexed' => true]);
    assert($change['settings']['max_length'] === 300 && !array_key_exists('indexed', $change));
    [, $change] = $save('title', ['max_length' => 200, 'indexed' => true]);
    assert($change['settings']['max_length'] === 200 && $change['indexed'] === true);

    // A key another field has is refused before the storage hears of it, under the key.
    $storage->forget();
    $taken = $port->raise($actor, $scope, 'record.create', ['collection' => $collection, 'values' => ['label' => 'Again', 'key' => 'title', 'type' => 'string']]);
    assert($taken['answer'] === 'refused' && $taken['errors'] === ['key' => 'Поле с таким ключом уже есть в хранилище.'] && !$storage->heard());
    // The section's refusals of the editor's input stand under their fields in its words (QA 2026-10-22: «secret»
    // was refused as «Запись не создана.» alone).
    $inputErrors = [
        'storage_workbench_key_unsafe' => 'key',
        'storage_workbench_field_target_invalid' => 'target_structure_id',
        'storage_workbench_field_max_length_invalid' => 'max_length',
        'storage_workbench_field_options_invalid' => 'options_text',
    ];
    foreach ($inputErrors as $reason => $key) {
        $storage->refuse = $reason;
        $refused = $port->raise($actor, $scope, 'record.create', ['collection' => $collection, 'values' => ['label' => 'Secret', 'key' => 'secret', 'type' => 'string']]);
        assert($refused['answer'] === 'refused' && $refused['message'] === 'Запись не создана.', $reason);
        assert(is_string($refused['errors'][$key] ?? null) && $refused['errors'][$key] !== 'Значение не принято. Проверьте его и сохраните ещё раз.', $reason);
    }
    assert(str_contains($refused['errors']['options_text'], 'по одному на строку'));
    $storage->refuse = 'storage_workbench_key_unsafe';
    $refused = $port->raise($actor, $scope, 'record.create', ['collection' => $collection, 'values' => ['label' => 'Secret', 'key' => 'secret', 'type' => 'string']]);
    assert(str_contains($refused['errors']['key'], 'зарезервирован') && str_contains($refused['errors']['key'], 'secret'));
    foreach (array_merge(StorageSectionGateway::FIELD_INPUT_REASONS, ['key']) as $reason) {
        assert(is_string($english['larena_storage']['section']['field_errors'][$reason] ?? null), $reason);
    }

    // Another refusal keeps the general words.
    $storage->refuse = 'storage_workbench_action_rejected';
    [$answer] = $save('title', ['indexed' => true]);
    assert($answer['errors']['indexed'] === 'Значение не принято. Проверьте его и сохраните ещё раз.');
    $storage->refuse = null;
} finally {
    @unlink($database);
}

echo "StorageFieldOptionsPropertyViewTest passed.\n";
