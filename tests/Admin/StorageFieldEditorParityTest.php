<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Identity\AdminRolePropertyViewPort;
use Larena\Admin\Identity\AdminUserPropertyViewPort;
use Larena\Storage\Admin\StorageFieldPropertyViewPort;
use Larena\Storage\Admin\StorageSectionGateway;
use Larena\Admin\Runtime\AdminDataviewCells;
use Larena\Admin\Runtime\PropertyViewPort;
use Larena\Admin\Settings\AdminSettingDefinitions;
use Larena\Admin\Settings\AdminSiteSettings;
use Larena\Admin\Settings\PropertySettingConstraints;
use Larena\Property\Contracts\PropertyField;
use Larena\Property\Contracts\PropertyFieldDefinition;
use Larena\Property\Contracts\PropertyRenderContext;
use Larena\Property\Runtime\PropertyDisplayProjector;
use Larena\Property\Runtime\PropertyPresentationRegistry;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Property\Runtime\PropertyTypeSettingsCatalog;
use Larena\Setting\Contracts\SettingDefinition;
use Larena\Setting\Runtime\SettingDefinitionRegistry;

// The Storage field editor in stage 2 of the settings system (moved from larena/admin's PropertyViewFieldParityTest with the
// Storage section): every property panel answers its fields through Property's one builder
// (PropertyViewFieldBuilder) from one field description (PropertyField). This test keeps the hand-built field of
// the port before the move and the field arrays each port built by hand, and checks that the panel answers the
// same JSON from both, field by field.

final class ParityTranslator implements Translator
{
    /** @param array<string, string> $lines */
    public function __construct(private array $lines = [])
    {
    }

    public function get($key, array $replace = [], $locale = null)
    {
        return $this->lines[$key] ?? $key;
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $this->get($key);
    }

    public function getLocale()
    {
        return 'en';
    }

    public function setLocale($locale)
    {
    }
}

final class ParityPort extends PropertyViewPort
{
    public bool $seal = false;

    protected function collectionPattern(): string
    {
        return '/^x$/';
    }

    protected function readObject(string $actor, string $scope, string $collection, string $recordId): array
    {
        return [];
    }

    protected function writeObject(string $actor, string $scope, string $collection, string $recordId, int|string $revision, array $values): array
    {
        return [];
    }

    protected function conflictReason(): string
    {
        return 'x';
    }

    protected function relatedHref(string $collection, string $recordId): string
    {
        return '/admin/records/'.$collection.'/'.$recordId;
    }

    protected function sealedField(array $field): bool
    {
        return $this->seal;
    }

    protected function sealedHint(array $field): string
    {
        return 'Encrypted.';
    }
}

$translator = new ParityTranslator(['larena-admin::admin.roles.name' => 'Name', 'larena-admin::admin.site_settings.timezone' => 'Time zone']);
$container = new Container();
$container->instance('translator', $translator);
Container::setInstance($container);

$projector = new PropertyDisplayProjector(PropertyTypeRegistry::builtIns());
$context = PropertyRenderContext::admin('en', false, 'actor:admin:1', 'scope:site');
$relatedHref = static fn (string $collection, string $recordId): string => '/admin/records/'.$collection.'/'.$recordId;
$word = static fn (string $key, string $fallback): string => $fallback;

/**
 * The port's field before the move, as it was written (PropertyViewPort::field() and sealed()).
 *
 * @param array<string,mixed> $field @param array<string,mixed> $place
 * @return array<string,mixed>
 */
$legacy = static function (array $field, array $place, mixed $raw, bool $sealed = false, ?string $sealedHint = null) use ($projector, $context, $relatedHref, $word): array {
    if ($sealed) {
        $hint = $sealedHint;
        if (is_string($place['hint'] ?? null) && $place['hint'] !== '') {
            $hint = $place['hint'].($hint !== null ? ' '.$hint : '');
        }

        return ['key' => $field['key'], 'label' => is_string($field['label'] ?? null) ? $field['label'] : $field['key'],
            'propertyType' => 'string', 'variant' => 'password', 'value' => ['set' => $raw !== null && $raw !== ''],
            'width' => ($place['width'] ?? 'half') === 'full' ? 'full' : 'half',
            'required' => ($field['required'] ?? false) === true,
            'rare' => ($field['hidden'] ?? false) === true, 'hidden' => ($place['hidden'] ?? false) === true]
            + (($field['readonly'] ?? false) === true ? ['readonly' => true] : [])
            + ($hint !== null && $hint !== '' ? ['hint' => $hint] : []);
    }
    $type = (string) $field['type'];
    $version = is_int($field['type_version'] ?? null) ? $field['type_version'] : 1;
    $constraints = is_array($field['constraints'] ?? null) ? $field['constraints'] : [];
    $text = null;
    try {
        $projection = $projector->project(PropertyFieldDefinition::make($field['key'], $type, 'lang:'.$field['key'],
            $constraints, ($field['required'] ?? false) === true), $version, $raw, $context, null);
        $text = $projection->status === 'present' ? $projection->text : null;
    } catch (Throwable) {
    }
    $urls = AdminDataviewCells::referenceUrls();
    $link = ($href = AdminDataviewCells::referenceHref($field, $raw, null, $urls['user'], $urls['file'])) !== null ? ['href' => $href] : [];
    $value = match ($type) {
        'user' => is_string($raw) && $text !== null ? ['value' => $raw, 'label' => $text, 'avatarText' => AdminDataviewCells::initials($text)] + $link : null,
        'record' => is_string($raw) && $text !== null ? ['value' => $raw, 'label' => $text]
            + (is_string($constraints['target_structure_id'] ?? null) ? ['href' => $relatedHref($constraints['target_structure_id'], $raw)] : []) : null,
        'file' => is_string($raw) && $text !== null ? ['value' => $raw, 'label' => $text] + $link : null,
        'relation' => is_string($raw) && $text !== null ? ['value' => $raw, 'label' => $text] : null,
        'date', 'datetime', 'number', 'integer' => $raw !== null && $text !== null && $text !== (string) $raw ? ['value' => $raw, 'text' => $text] : $raw,
        default => $raw,
    };
    $column = AdminDataviewCells::column($field);
    $variant = $place['variant'] ?? ($column['variant'] ?? ($field['variant'] ?? null));
    try {
        (new PropertyPresentationRegistry())->template($type, $version, 'view', $constraints);
    } catch (InvalidArgumentException) {
        $type = 'string';
    }

    return ['key' => $field['key'], 'label' => is_string($field['label'] ?? null) ? $field['label'] : $field['key'],
        'propertyType' => $type, 'variant' => is_string($variant) ? $variant : null, 'value' => $value,
        'width' => ($place['width'] ?? 'half') === 'full' ? 'full' : 'half',
        'required' => ($field['required'] ?? false) === true,
        'rare' => ($field['hidden'] ?? false) === true, 'hidden' => ($place['hidden'] ?? false) === true]
        + (($field['readonly'] ?? false) === true ? ['readonly' => true] : [])
        + (is_string($place['hint'] ?? null) && $place['hint'] !== '' ? ['hint' => $place['hint']] : [])
        + (isset($column['options']) ? ['options' => $column['options']] : [])
        + (isset($column['trueText']) ? ['trueText' => $column['trueText'], 'falseText' => $column['falseText']] : [])
        + ($type === 'file' ? ['uploadText' => $word('upload_text', 'Choose a file'), 'formats' => $word('upload_formats', 'Any file')] : []);
};

// The port as it is now: its field() through the builder, with the same relatedHref as above.
$port = (new ReflectionClass(ParityPort::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(PropertyViewPort::class, 'projector'))->setValue($port, $projector);
(new ReflectionProperty(PropertyViewPort::class, 'labels'))->setValue($port, null);
$current = static function (array $field, array $place, mixed $raw) use ($port, $context): array {
    return (new ReflectionMethod(PropertyViewPort::class, 'field'))->invoke($port, $field, $place, $raw, $context);
};

$compare = static function (string $case, array $old, array $new, array $values, array $places = [], array $kitOnly = []) use ($legacy, $current): void {
    assert(count($old) === count($new), $case.': field count');
    foreach ($old as $index => $field) {
        $place = $places[$field['key']] ?? [];
        $raw = $values[$field['key']] ?? null;
        $expected = json_encode($legacy($field, $place, $raw), JSON_THROW_ON_ERROR);
        // The arrays the port builds now give the same answer through the old code and through the builder; a field
        // that moved to a type the Framework draws with a control of its own (`$kitOnly`, a lookup since port 1.14.0)
        // is checked by the case itself.
        if (in_array($field['key'], $kitOnly, true)) {
            continue;
        }
        assert(json_encode($legacy($new[$index], $place, $raw), JSON_THROW_ON_ERROR) === $expected, $case.': '.$field['key'].' (arrays)');
        // A relation is drawn read only since the acceptance of 2026-10-23 (the port picks no relation): the old answer
        // with that one flag.
        $now = ($field['type'] ?? null) === 'relation' ? json_encode($legacy($field + ['readonly' => true], $place, $raw), JSON_THROW_ON_ERROR) : $expected;
        assert(json_encode($current($new[$index], $place, $raw), JSON_THROW_ON_ERROR) === $now,
            $case.': '.$field['key'].' '.json_encode($current($new[$index], $place, $raw)).' vs '.$now);
    }
};

// 4. The Storage field editor (StorageFieldPropertyViewPort::collectionOf): a new field, an existing string, an
// encrypted one, a «Storage record» field, a choice.
$editor = (new ReflectionClass(StorageFieldPropertyViewPort::class))->newInstanceWithoutConstructor();
$structure = ['structure_id' => 'workbench.items', 'label' => 'Items', 'migrate' => true, 'targets' => [
    'workbench.sections' => ['label' => 'Sections', 'active' => true], 'workbench.old' => ['label' => 'Old', 'active' => false]]];
$cases = [
    'new' => [true, null],
    'string' => [false, ['key' => 'title', 'type' => 'string', 'type_version' => 2, 'constraints' => ['max_length' => 100]]],
    'encrypted' => [false, ['key' => 'passport', 'type' => 'string', 'type_version' => 2, 'constraints' => [], 'encrypted' => true]],
    'record' => [false, ['key' => 'section', 'type' => 'record', 'type_version' => 1, 'constraints' => ['target_structure_id' => 'workbench.sections']]],
    'choice' => [false, ['key' => 'stage', 'type' => 'choice', 'type_version' => 1, 'constraints' => ['options' => [['value' => 'a', 'label' => 'A']]]]],
];
// Stage 3 builds the editor from the type schema (StorageFieldEditorSchemaTest): its fields are the common ones and one
// a declared setting, each answered through the builder like any field.
foreach ([false, true] as $manage) {
    (new ReflectionProperty(StorageFieldPropertyViewPort::class, 'manage'))->setValue($editor, $manage);
    foreach ($cases as $name => [$create, $currentField]) {
        $new = (new ReflectionMethod(StorageFieldPropertyViewPort::class, 'collectionOf'))->invoke($editor, $structure, $create, $currentField, false)['fields'];
        $keys = array_column($new, 'key');
        assert(array_diff(array_keys(StorageFieldPropertyViewPort::editorSettings()), $keys) === [], 'field editor '.$name);
        assert(array_slice($keys, 0, 3) === ['label', 'key', 'type'] && in_array('required', $keys, true) && in_array('hidden', $keys, true), 'field editor '.$name);
        foreach ($new as $one) {
            assert($current($one, [], null)['key'] === $one['key'], 'field editor '.$name.' '.$one['key']);
        }
    }
}

echo "StorageFieldEditorParityTest passed.\n";
