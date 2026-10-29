<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Runtime\AdminElement;
use Larena\Admin\Runtime\AdminFrameworkForm;
use Larena\Admin\Runtime\AdminRenderedPart;
use Larena\Admin\Runtime\AdminText;

/**
 * One field row of a storage's field editor (the «Поля» of «Создать хранилище»), as described parts (developer remark 3',
 * stage 5). A new field names its key and type; an existing field keeps them and changes only label, order and visibility.
 * The controls are Framework controls drawn by the caller's AdminFrameworkForm, whose name list the caller places in the
 * form; AdminElementDocumentRenderer::tree() draws the rows.
 */
final readonly class StorageFieldEditor
{
    public const array TYPES = ['string', 'text', 'integer', 'number', 'boolean', 'date', 'datetime', 'choice', 'choices', 'user', 'file', 'record', 'relation'];
    private const string COPY = 'larena-storage::admin.section.';

    public function __construct(private Translator $translator)
    {
    }

    /**
     * @param ?array<string, mixed> $field an existing field, or null for a new one
     * @param array<string, string> $recordTargets the storages a new «Storage record» field may name, id => name
     * @param bool $firstRequired whether a new field's key and label are required (the first row)
     * @param bool $allowRequired whether a new field may be marked required
     * @param bool $allowMigrate whether an existing field may change its type, required mark and options, or be removed
     */
    public function describe(AdminFrameworkForm $form, int $index, ?array $field, array $recordTargets = [], bool $firstRequired = false,
        bool $allowRequired = false, bool $allowMigrate = false): AdminElement
    {
        $types = [];
        foreach (self::TYPES as $type) {
            $types[$type] = $this->copy('types.'.$type);
        }
        $prefix = 'payload[fields]['.$index.']';
        // A new «Storage record» field names the storage its records come from; the target stays fixed afterwards.
        $targets = ['none' => '—'] + $recordTargets;
        $target = is_array($field) && is_string($field['constraints']['target_structure_id'] ?? null) ? $field['constraints']['target_structure_id'] : '';
        $control = static fn (string $html): AdminRenderedPart => AdminRenderedPart::control($html);

        $content = [new AdminElement('legend', [], is_array($field) ? (string) ($field['label'] ?? $field['key']) : $this->copy('field').' '.($index + 1))];
        if (is_array($field)) {
            $type = (string) ($field['type'] ?? 'string');
            $content[] = new AdminElement('input', ['type' => 'hidden', 'name' => $prefix.'[key]', 'value' => (string) $field['key']]);
            $content[] = new AdminElement('p', ['class' => 'larena-muted'], [
                new AdminElement('code', [], (string) $field['key']),
                new AdminText(' · '.$this->copy('types.'.$type)
                    .($target !== '' ? ' · '.($recordTargets[$target] ?? $target) : '')
                    .(($field['required'] ?? false) === true ? ' · '.$this->copy('required') : '')),
            ]);
            if ($allowMigrate) {
                // A «Storage record» target is chosen only when a field is added, so an existing field is not retyped to it.
                $content[] = new AdminElement('div', ['data-larena-storage-field-type' => null], [$control($form->select($prefix.'[type]', $this->copy('type'), $type,
                    ($field['type'] ?? null) === 'record' ? $types : array_diff_key($types, ['record' => true])))]);
                $content[] = new AdminElement('input', ['type' => 'hidden', 'name' => $prefix.'[required]', 'value' => '0']);
                $content[] = $control($form->checkbox($prefix.'[required]', $this->copy('required'), '1', ($field['required'] ?? false) === true));
                $content[] = new AdminElement('div', ['data-larena-storage-field-remove' => null], [$control($form->checkbox($prefix.'[remove]', $this->copy('remove_field'), '1', false))]);
                if (in_array($field['type'] ?? null, ['choice', 'choices'], true)) {
                    $content[] = new AdminElement('div', ['data-larena-storage-field-options' => null], [$control($form->textarea($prefix.'[options_text]', $this->copy('options'),
                        implode("\n", self::optionLines($field)), false, 5, $this->copy('options_hint')))]);
                }
            }
        } else {
            $content[] = $control($form->input($prefix.'[key]', $this->copy('field_key'), '', $firstRequired));
            $content[] = $control($form->select($prefix.'[type]', $this->copy('type'), 'string', $types));
            if (count($targets) > 1) {
                $content[] = $control($form->select($prefix.'[target_structure_id]', $this->copy('record_target'), 'none', $targets));
            }
            $content[] = new AdminElement('div', ['data-larena-storage-field-options' => null], [$control($form->textarea($prefix.'[options_text]', $this->copy('options_new'),
                '', false, 4, $this->copy('options_hint')))]);
            if ($allowRequired) {
                $content[] = $control($form->checkbox($prefix.'[required]', $this->copy('required'), '1', false));
            }
        }
        $content[] = $control($form->input($prefix.'[label]', $this->copy('label'), is_array($field) ? (string) ($field['label'] ?? '') : '', is_array($field) || $firstRequired));
        $content[] = $control($form->input($prefix.'[position]', $this->copy('position'), (string) (is_array($field) ? ($field['position'] ?? 0) : ($index + 1) * 10), false, 'number'));
        if (is_array($field)) {
            $content[] = $control($form->checkbox($prefix.'[hidden]', $this->copy('hidden'), '1', ($field['hidden'] ?? false) === true));
        }

        return new AdminElement('fieldset', ['class' => 'larena-workbench-fieldset larena-choice-grid', 'data-larena-storage-field' => null]
            + (is_array($field) && is_string($field['key'] ?? null) ? ['id' => 'storage-field-'.$field['key']] : []), $content);
    }

    /**
     * The options of a choice field, one per line as they are typed: «value | Label | colour».
     *
     * @param array<string, mixed> $field
     * @return list<string>
     */
    private static function optionLines(array $field): array
    {
        $lines = [];
        foreach (is_array($field['constraints']['options'] ?? null) ? $field['constraints']['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                $lines[] = $option['value'].' | '.$option['label'].(is_string($option['color'] ?? null) ? ' | '.$option['color'] : '');
            }
        }

        return $lines;
    }

    private function copy(string $key): string
    {
        return (string) $this->translator->get(self::COPY.$key);
    }
}
