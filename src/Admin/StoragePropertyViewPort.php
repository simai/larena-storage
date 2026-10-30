<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Admin\Contracts\RecordFilterOptionsGateway;
use Larena\Admin\Runtime\PropertyViewPort;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Property\Contracts\PropertyReferenceLabelResolver;
use Larena\Property\Runtime\PropertyDisplayProjector;
use Larena\Storage\Contracts\StorageWorkbench;
use Throwable;

/**
 * The property view port of a record in the Storage section: Storage reads it within the scope,
 * the section gateway writes it, and a record is editable only while active and with record.update.
 *
 * An encrypted field («Шифровать») is sealed: the panel learns only whether it holds a value, and an editor replaces
 * it by typing a new one. Its envelope stays here: a save that leaves the field untouched writes it back, and
 * Storage keeps the value unchanged. «Показать» reads the plaintext through its own route (StorageFieldRevealController).
 */
final class StoragePropertyViewPort extends PropertyViewPort
{
    public function __construct(
        private StorageWorkbenchGateway $gateway,
        private StorageWorkbench $storage,
        AdminRecordLayoutStore $layouts,
        ?PropertyDisplayProjector $projector = null,
        ?PropertyReferenceLabelResolver $labels = null,
        ?RecordFilterOptionsGateway $options = null,
        private ?StoragePropertyViewFileStore $fileStore = null,
        private ?StorageSectionPublicationActions $publication = null,
    ) {
        parent::__construct($layouts, $projector, $labels, $options);
    }

    protected function collectionPattern(): string
    {
        return '/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/';
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|array<string,mixed> */
    protected function readObject(string $actor, string $scope, string $collection, string $recordId): array
    {
        $base = $this->gateway->snapshot($actor, $scope, ['structure_id' => $collection, 'surface' => 'storage_section', 'records' => 'skip']);
        if (($base['status'] ?? null) === 'denied') {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        }
        if (($base['status'] ?? null) !== 'ok' || ($base['selected_structure']['structure_id'] ?? null) !== $collection) {
            return ['answer' => 'unavailable', 'reason' => 'collection_unavailable'];
        }
        try {
            $structure = $this->storage->readStructure($scope, $collection, $actor);
            $record = $this->storage->readRecord($scope, $collection, $recordId, $actor);
        } catch (Throwable) {
            return ['answer' => 'refused', 'reason' => 'record_unknown'];
        }
        $permissions = is_array($base['permissions'] ?? null) ? $base['permissions'] : [];
        // A record of a publishable storage says where it is out; «Опубликовать» stands in the panel's foot.
        $binding = $this->publication?->binding($actor, $scope, $collection);
        $publication = $this->publication?->publication();

        return [
            ['structure_id' => $collection, 'label' => $base['selected_structure']['label'] ?? $collection, 'fields' => $binding === null || $publication === null ? $structure->fields
                : [...$structure->fields, (new \Larena\Property\Contracts\PropertyField(StorageSectionPublication::COLUMN, 'string', 1,
                    $publication->word('larena-admin::structure_editors.publication'), readonly: true))->toArray()]],
            ['record_id' => $record->recordId, 'revision' => $record->revision,
                'values' => $binding === null || $publication === null ? $record->values
                    : $record->values + [StorageSectionPublication::COLUMN => $publication->line($binding['schema'], $record->recordId, $binding['site'])],
                'editable' => $record->state === 'active' && ($base['selected_structure']['archived'] ?? false) !== true
                    && ($permissions['record.update'] ?? false) === true,
                // Deleting here moves the record to the archive, as the section's own lifecycle does.
                'deletable' => $record->state === 'active' && ($base['selected_structure']['archived'] ?? false) !== true
                    && ($permissions['record.archive'] ?? false) === true],
        ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|array<string,mixed> */
    protected function readCollection(string $actor, string $scope, string $collection): array
    {
        $base = $this->gateway->snapshot($actor, $scope, ['structure_id' => $collection, 'surface' => 'storage_section', 'records' => 'skip']);
        if (($base['status'] ?? null) === 'denied') {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        }
        if (($base['status'] ?? null) !== 'ok' || ($base['selected_structure']['structure_id'] ?? null) !== $collection) {
            return ['answer' => 'unavailable', 'reason' => 'collection_unavailable'];
        }
        try {
            $structure = $this->storage->readStructure($scope, $collection, $actor);
        } catch (Throwable) {
            return ['answer' => 'refused', 'reason' => 'collection_unknown'];
        }

        return [
            ['structure_id' => $collection, 'label' => $base['selected_structure']['label'] ?? $collection, 'fields' => $structure->fields],
            ['creatable' => ($base['selected_structure']['archived'] ?? false) !== true && ($base['permissions']['record.create'] ?? false) === true],
        ];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    protected function createObject(string $actor, string $scope, string $collection, array $values): array
    {
        return $this->gateway->dispatch($actor, $scope, 'record.create', ['structure_id' => $collection, 'values' => $values]);
    }

    /** Deleting moves the record to the storage's archive, where it can be restored or purged. */
    protected function deleteObject(string $actor, string $scope, string $collection, string $recordId, int|string $revision): array
    {
        // The page asked the person before it raised the delete (deleteQuestion), so it comes confirmed.
        return $this->gateway->dispatch($actor, $scope, 'record.archive', ['structure_id' => $collection, 'record_id' => $recordId,
            'expected_revision' => $revision, 'confirmed' => true]);
    }

    protected function deleteQuestion(): string
    {
        return $this->word('archive_confirm', 'Move this record to the archive? It can be restored from there.');
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    protected function writeObject(string $actor, string $scope, string $collection, string $recordId, int|string $revision, array $values): array
    {
        // The publication line is the panel's own column, read with the record: it is not a value of the record.
        unset($values[StorageSectionPublication::COLUMN]);

        return $this->gateway->dispatch($actor, $scope, 'record.update', ['structure_id' => $collection, 'record_id' => $recordId,
            'expected_revision' => $revision, 'values' => $values]);
    }

    /**
     * The records of other storages of the scope whose «Storage record» field points at this
     * record's storage: one related group per pointing field (at most six), listed through the
     * Storage section's data-view port with that field filtered to this record. The storages come
     * from the section with the person's own access; archived storages and hidden fields (which the
     * list may not filter by) are left out.
     *
     * @return list<array{key:string,label:string,structure_id:string,field:string,port:string}>
     */
    protected function relatedGroups(string $actor, string $scope, string $collection, string $recordId): array
    {
        $state = $this->gateway->snapshot($actor, $scope, ['surface' => 'storage_section']);
        $groups = [];
        foreach (($state['status'] ?? null) === 'ok' && is_array($state['structures'] ?? null) ? $state['structures'] : [] as $structure) {
            if (!is_array($structure) || !is_string($structure['structure_id'] ?? null) || ($structure['archived'] ?? false) === true) {
                continue;
            }
            $pointing = array_values(array_filter(is_array($structure['fields'] ?? null) ? $structure['fields'] : [], static fn (mixed $field): bool => is_array($field)
                && ($field['type'] ?? null) === 'record' && is_string($field['key'] ?? null) && ($field['hidden'] ?? false) !== true
                && ($field['constraints']['target_structure_id'] ?? null) === $collection));
            foreach ($pointing as $field) {
                $label = (string) ($structure['label'] ?? $structure['structure_id']);
                // Two fields of one storage, or a storage pointing at itself, need the field to tell them apart.
                if (count($pointing) > 1 || $structure['structure_id'] === $collection) {
                    $label .= ' · '.(is_string($field['label'] ?? null) ? $field['label'] : $field['key']);
                }
                $groups[] = ['key' => 'related-'.substr(hash('sha256', $structure['structure_id']."\0".$field['key']), 0, 12), 'label' => $label,
                    'structure_id' => $structure['structure_id'], 'field' => (string) $field['key'], 'port' => '/admin/storage/port'];
                if (count($groups) >= 6) {
                    return $groups;
                }
            }
        }

        return $groups;
    }

    /** «Show all» of a related group: the storage's own list, the pointing field filtered to the record. */
    protected function relatedListHref(string $collection, string $field, string $recordId): ?string
    {
        return preg_match('/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/', $collection) === 1 && preg_match('/^record-[0-9a-f]{32}$/', $recordId) === 1
            ? '/admin/storage/'.rawurlencode($collection).'?'.http_build_query(['filters' => self::relatedListFilters($field, $recordId)], '', '&', PHP_QUERY_RFC3986)
            : null;
    }

    protected function relatedHref(string $collection, string $recordId): ?string
    {
        return preg_match('/^[a-z][a-z0-9_.:-]{0,119}$/', $collection) === 1 && preg_match('/^record-[0-9a-f]{32}$/', $recordId) === 1
            ? '/admin/storage/'.rawurlencode($collection).'?'.http_build_query(['record_id' => $recordId, 'record_mode' => 'view'], '', '&', PHP_QUERY_RFC3986).'#minimal-cms-record-view'
            : null;
    }

    /** @return array<string,string>|null */
    protected function storeFile(string $actor, string $scope, string $collection, \Illuminate\Http\UploadedFile $file): ?array
    {
        return $this->fileStore?->store($actor, $scope, $file);
    }

    protected function conflictReason(): string
    {
        return 'storage_workbench_revision_conflict';
    }

    protected function sealedField(array $field): bool
    {
        return StorageEncryptedFields::isEncrypted($field);
    }

    protected function sealedHint(array $field): string
    {
        return StorageEncryptedFields::word('record_hint', 'Encrypted. Type a new value to replace it; an empty field keeps the one stored.');
    }

    /** Storage's refusals of an encrypted value, and a value one field cannot take, in the section's words. */
    protected function reasonMessage(string $reason): ?string
    {
        if ($reason === StorageSectionGateway::FIELD_VALUE_INVALID) {
            return $this->fieldReasonMessage('');
        }
        if (str_starts_with($reason, 'site_page_') || str_starts_with($reason, 'site_record_')) {
            $container = \Illuminate\Container\Container::getInstance();
            $text = $container->bound('translator') ? $container->make('translator')->get('larena-storage::admin.site_pages.refusals.'.$reason) : null;

            return is_string($text) && !str_starts_with($text, 'larena-storage::') ? $text : null;
        }
        if (!in_array($reason, StorageSectionGateway::ENCRYPTION_REASONS, true)) {
            return null;
        }
        $text = StorageEncryptedFields::word('errors.'.$reason, '');

        return $text !== '' ? $text : null;
    }

    /** Why one field's value was refused («Нужно число, например 100,5 или 100.5.»), said under that field. */
    protected function fieldReasonMessage(string $reason): ?string
    {
        $container = \Illuminate\Container\Container::getInstance();
        if (!$container->bound('translator')) {
            return null;
        }
        $key = 'larena-storage::admin.section.value_errors.'.StorageSectionGateway::valueErrorKey($reason);
        $text = $container->make('translator')->get($key);

        return is_string($text) && $text !== $key ? $text : null;
    }
}
