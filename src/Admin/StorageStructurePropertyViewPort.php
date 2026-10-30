<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Storage\Presets\StoragePresets;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Admin\Runtime\PropertyViewPort;

/**
 * A storage as an object of the Framework property view (collection storage.structures): made with its
 * name and id through record.create, starting with one required «Name» field; its fields are arranged in
 * the storage's settings afterwards (owner decision 2026-10-14: lists wait for a list type of the view).
 * Making one needs structure.create; reading it, the section's own access.
 */
final class StorageStructurePropertyViewPort extends PropertyViewPort
{
    public const COLLECTION = 'storage.structures';

    /** «Хранить изменений» is not a whole number from 1 to the limit (and not empty). */
    public const HISTORY_KEEP_INVALID = 'storage_workbench_history_keep_invalid';

    /** The storage the last read saw, so a refusal while its field change runs names that field. @var array<string,mixed>|null */
    private ?array $seen = null;

    public function __construct(private StorageWorkbenchGateway $gateway, AdminRecordLayoutStore $layouts)
    {
        parent::__construct($layouts);
    }

    protected function collectionPattern(): string
    {
        return '/^storage\.structures$/';
    }

    protected function recordPattern(): string
    {
        return '/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/';
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|array<string,mixed> */
    protected function readCollection(string $actor, string $scope, string $collection): array
    {
        $this->seen = null;
        $state = $this->gateway->snapshot($actor, $scope, ['surface' => 'storage_section']);
        if (($state['status'] ?? null) === 'denied') {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        }
        if (($state['status'] ?? null) !== 'ok') {
            return ['answer' => 'unavailable', 'reason' => 'collection_unavailable'];
        }

        return [$this->collection(), ['creatable' => ($state['permissions']['structure.create'] ?? false) === true]];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|array<string,mixed> */
    protected function readObject(string $actor, string $scope, string $collection, string $recordId): array
    {
        $state = $this->gateway->snapshot($actor, $scope, ['structure_id' => $recordId, 'surface' => 'storage_section', 'records' => 'skip']);
        if (($state['status'] ?? null) === 'denied') {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        }
        $selected = $state['selected_structure'] ?? null;
        if (($state['status'] ?? null) !== 'ok' || !is_array($selected) || ($selected['structure_id'] ?? null) !== $recordId) {
            return ['answer' => 'refused', 'reason' => 'record_unknown'];
        }

        // Its name and its group in the menu are changed here; its fields in the settings' list of fields.
        $configure = ($state['permissions']['structure.configure'] ?? false) === true && ($selected['archived'] ?? false) !== true;
        // While a field change runs in the background the storage is read, not changed, and says why.
        $changes = new StorageFieldChangeState(\Illuminate\Container\Container::getInstance()->make('translator'));
        $this->seen = $selected;
        $notice = $changes->notice($selected);
        $note = implode("\n", array_filter([$notice === null ? null : $notice['title'].'. '.$notice['text'], $changes->locked($selected)]));

        return [$this->collection(true, $note !== ''), ['record_id' => $recordId, 'revision' => (int) ($selected['version'] ?? 1),
            'editable' => $configure && !StorageFieldChangeState::changing($selected),
            'values' => ['label' => $selected['label'] ?? $recordId, 'structure_id' => $recordId, 'menu_group' => $this->menuGroups()?->storageGroups()[$recordId] ?? '',
                'history_keep' => is_int($selected['history_keep'] ?? null) ? $selected['history_keep'] : null] + ($note !== '' ? ['field_state' => $note] : [])]];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    protected function createObject(string $actor, string $scope, string $collection, array $values): array
    {
        // A preset makes the storage with its fields and role; the others start with a name.
        $preset = $values['preset'] ?? null;
        $receipt = is_string($preset) && $preset !== '' && $preset !== StoragePresets::PLAIN ? $this->gateway->dispatch($actor, $scope, 'structure.create_preset', [
            'label' => $values['label'] ?? null, 'structure_id' => $values['structure_id'] ?? null, 'preset' => $preset,
        ]) : $this->gateway->dispatch($actor, $scope, 'structure.create', [
            'label' => $values['label'] ?? null, 'structure_id' => $values['structure_id'] ?? null,
            'fields' => [['key' => 'title', 'label' => $this->section('label', 'Name'), 'type' => 'string', 'required' => true, 'position' => 10]],
        ]);
        // The storage is the object made: its id is the record id the port answers with.
        $structureId = $receipt['redirect']['structure_id'] ?? null;
        if (is_string($structureId)) {
            $receipt['redirect']['record_id'] = $structureId;
        }

        return $receipt;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    protected function writeObject(string $actor, string $scope, string $collection, string $recordId, int|string $revision, array $values): array
    {
        // «Хранить изменений» is checked before anything is written: a refused number must not leave the name saved
        // and the storage's version moved under the open panel, whose next save would then read as a conflict
        // (QA 2026-10-22: −3, then 5, answered «Запись изменили…»).
        $keep = $values['history_keep'] ?? null;
        $keep = is_string($keep) ? trim($keep) : $keep;
        if ($keep === '') {
            $keep = null;
        }
        if ($keep !== null) {
            $keep = filter_var($keep, FILTER_VALIDATE_INT);
            if (!is_int($keep) || $keep < 1 || $keep > \Larena\Storage\Contracts\VersionedStorage::HISTORY_KEEP_LIMIT) {
                return ['status' => 'rejected', 'reason_code' => self::HISTORY_KEEP_INVALID];
            }
        }
        $seen = is_array($this->seen) && ($this->seen['structure_id'] ?? null) === $recordId ? $this->seen : null;
        $receipt = ['status' => 'ok'];
        // The name through the storage's own change (its fields stay as they are), only when it changed: an unchanged
        // name moves no version.
        $label = $values['label'] ?? null;
        if ($seen === null || $label !== ($seen['label'] ?? null)) {
            $receipt = $this->gateway->dispatch($actor, $scope, 'structure.configure', [
                'structure_id' => $recordId, 'label' => $label, 'expected_version' => (int) $revision, 'fields' => [],
            ]);
        }
        // The values come merged over the current ones with emptied fields left out: a field that is
        // absent was cleared (an untouched one arrives with its current value).
        if (($receipt['status'] ?? null) === 'ok') {
            $group = is_string($values['menu_group'] ?? null) && trim($values['menu_group']) !== '' ? trim($values['menu_group']) : null;
            $this->menuGroups()?->setStorageGroup($recordId, $group);
        }
        // How many versions of each record are kept: empty keeps them all; a number trims the history now.
        if (($receipt['status'] ?? null) === 'ok' && ($seen === null || $keep !== (is_int($seen['history_keep'] ?? null) ? $seen['history_keep'] : null))) {
            $receipt = $this->gateway->dispatch($actor, $scope, 'structure.history_keep', ['structure_id' => $recordId, 'keep' => $keep]);
        }

        return $receipt;
    }

    /** The field a refusal is about, for its words to stand under it. */
    protected function reasonField(string $reason): ?string
    {
        return $reason === self::HISTORY_KEEP_INVALID ? 'history_keep' : null;
    }

    private function menuGroups(): ?\Larena\Admin\Navigation\AdminMenuProfiles
    {
        try {
            return \Illuminate\Container\Container::getInstance()->make(\Larena\Admin\Navigation\AdminMenuProfiles::class);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function conflictReason(): string
    {
        return 'storage_workbench_revision_conflict';
    }

    /** While the storage's field change runs, a write from a panel opened before it is refused in words that name the field. */
    protected function lockedMessage(): ?string
    {
        return $this->seen !== null && StorageFieldChangeState::changing($this->seen)
            ? (new StorageFieldChangeState(\Illuminate\Container\Container::getInstance()->make('translator')))->refusal(StorageSectionGateway::FIELD_CHANGE_RUNNING, $this->seen)
            : null;
    }

    /** Storage's refusal while a field change runs, naming the field; «Хранить изменений» out of range in its own words. */
    protected function reasonMessage(string $reason): ?string
    {
        if ($reason === self::HISTORY_KEEP_INVALID) {
            return str_replace(':max', (string) \Larena\Storage\Contracts\VersionedStorage::HISTORY_KEEP_LIMIT, $this->section('history_keep_invalid',
                'A whole number from 1 to :max, or empty to keep every version.'));
        }

        return (new StorageFieldChangeState(\Illuminate\Container\Container::getInstance()->make('translator')))->refusal($reason, $this->seen);
    }

    /** A new storage opens on its settings, where its fields are added. */
    protected function relatedHref(string $collection, string $recordId): ?string
    {
        return $collection === self::COLLECTION && preg_match($this->recordPattern(), $recordId) === 1
            ? '/admin/storage/'.rawurlencode($recordId).'/settings' : null;
    }

    protected function mainGroupLabel(): string
    {
        return $this->section('storage_properties', 'Storage properties');
    }

    /** @return array<string,mixed> */
    private function collection(bool $existing = false, bool $stated = false): array
    {
        return ['structure_id' => self::COLLECTION, 'label' => $this->section('storages', 'Storages'), 'fields' => [
            ['key' => 'label', 'label' => $this->section('label', 'Name'), 'type' => 'string', 'type_version' => 1, 'required' => true, 'constraints' => []],
            // The id is given once, when the storage is made; afterwards it is shown, not changed.
            ['key' => 'structure_id', 'label' => $this->section('structure_id', 'Storage ID'), 'type' => 'string', 'type_version' => 1,
                'constraints' => [], 'default' => 'workbench.', 'hint' => $this->section('structure_id_hint', '')] + ($existing ? ['readonly' => true] : ['required' => true]),
            // What it starts from is chosen once, when the storage is made.
            ...($existing ? [] : [['key' => 'preset', 'label' => $this->section('preset', 'Start from'), 'type' => 'choice', 'type_version' => 1,
                'constraints' => ['options' => $this->presets()], 'hint' => $this->section('preset_hint', '')]]),
            ['key' => 'menu_group', 'label' => $this->section('menu_group', 'Group in the menu'), 'type' => 'string', 'type_version' => 1,
                'constraints' => [], 'hint' => $this->section('menu_group_hint', '')],
            // Only an existing storage has records whose history it keeps.
            ...($existing ? [['key' => 'history_keep', 'label' => $this->section('history_keep', 'Versions kept'), 'type' => 'integer', 'type_version' => 1,
                'constraints' => ['min' => 1, 'max' => \Larena\Storage\Contracts\VersionedStorage::HISTORY_KEEP_LIMIT],
                'hint' => $this->section('history_keep_hint', '')]] : []),
            // What goes on with the storage's fields in the background, shown and never written.
            ...($stated ? [['key' => 'field_state', 'label' => $this->section('field_change.column', 'State'), 'type' => 'text', 'type_version' => 1,
                'constraints' => [], 'readonly' => true]] : []),
        ]];
    }

    /** @return list<array{value: string, label: string}> */
    private function presets(): array
    {
        $container = \Illuminate\Container\Container::getInstance();

        return $container->bound(StoragePresets::class) ? $container->make(StoragePresets::class)->options() : [];
    }

    private function section(string $key, string $fallback): string
    {
        $container = \Illuminate\Container\Container::getInstance();
        if ($container->bound('translator')) {
            $text = $container->make('translator')->get('larena-storage::admin.section.'.$key);
            if (is_string($text) && !str_starts_with($text, 'larena-storage::')) {
                return $text;
            }
        }

        return $fallback;
    }
}
