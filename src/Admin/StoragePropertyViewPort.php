<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Admin\Contracts\RecordFilterOptionsGateway;
use Larena\Admin\Runtime\PropertyViewPort;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Property\Contracts\PropertyReferenceLabelResolver;
use Larena\Property\Runtime\PropertyDisplayProjector;
use Larena\Admin\Contracts\AdminIdentityLabelResolver;
use Larena\Property\Contracts\PropertyField;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecordCard;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * The property view port of a record in the Storage section: Storage reads it within the scope,
 * the section gateway writes it, and a record is editable only while active and with record.update.
 *
 * An encrypted field («Шифровать») is sealed: the panel learns only whether it holds a value, and an editor replaces
 * it by typing a new one. Its envelope stays here: a save that leaves the field untouched writes it back, and
 * Storage keeps the value unchanged. «Показать» reads the plaintext through its own route (StorageFieldRevealController).
 *
 * The record card (C305) reads two more intents here. `record.history {record_id, collection, cursor, kinds}` answers
 * the record's changes newest first, a page at a time: each version against the one before it, by field, with the
 * field's label as that schema version named it; an encrypted field, or one the person does not read, says only
 * that it changed. `record.neighbours {record_id, collection, query}` answers the records before and after this one
 * in the list the card was opened from, by that list's own query. A short field is saved alone with group.save.
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
        private ?AdminIdentityLabelResolver $names = null,
    ) {
        parent::__construct($layouts, $projector, $labels, $options);
    }

    /** The kinds of change the history names, and the operations of Storage's versions each one covers. */
    public const HISTORY_KINDS = [
        'create' => ['create'],
        'update' => ['update', 'revert', 'schema_migration'],
        'delete' => ['delete', 'archive'],
        'restore' => ['restore'],
        // Publication is kept by the publication lifecycle, not in the record's versions.
        'publish' => [],
    ];

    /** The versions one history page reads unless the card asks for fewer. */
    private const HISTORY_PAGE = 20;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function raise(string $actor, string $scope, string $intent, array $payload): array
    {
        return match ($intent) {
            'record.history' => $this->history($actor, $scope, $payload),
            'record.neighbours' => $this->neighbours($actor, $scope, $payload),
            default => parent::raise($actor, $scope, $intent, $payload),
        };
    }

    /**
     * record.history: one page of the record's changes, newest first. The cursor is a revision: the next page is the
     * versions below it. Each event is one version {id, time, actor, kind, changes, revision}; changes are its fields
     * against the version before it {field, label, before, after}, or {field, label, sealed: true} for a value the
     * person may not see (encrypted, or a field the record's read does not give them).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function history(string $actor, string $scope, array $payload): array
    {
        if (!$this->storage instanceof StorageWorkbenchRecordCard) {
            return ['answer' => 'unavailable', 'reason' => 'history_unavailable'];
        }
        $cursor = $payload['cursor'] ?? null;
        $cursor = is_string($cursor) && preg_match('/\A[1-9][0-9]{0,17}\z/', $cursor) === 1 ? (int) $cursor : $cursor;
        $kinds = $payload['kinds'] ?? [];
        $limit = $payload['limit'] ?? self::HISTORY_PAGE;
        if (($cursor !== null && (!is_int($cursor) || $cursor < 1)) || !is_array($kinds) || !array_is_list($kinds)
            || array_diff($kinds, array_keys(self::HISTORY_KINDS)) !== [] || !is_int($limit) || $limit < 1 || $limit > StorageWorkbenchRecordCard::MAX_PAGE) {
            return ['answer' => 'refused', 'reason' => 'history_invalid'];
        }
        $collection = $payload['collection'] ?? null;
        $recordId = $payload['record_id'] ?? null;
        if (!is_string($collection) || preg_match($this->collectionPattern(), $collection) !== 1
            || !is_string($recordId) || preg_match($this->recordPattern(), $recordId) !== 1) {
            return ['answer' => 'refused', 'reason' => 'record_invalid'];
        }
        $read = $this->readObject($actor, $scope, $collection, $recordId);
        if (isset($read['answer'])) {
            return $read;
        }
        $operations = [];
        foreach ($kinds as $kind) {
            $operations = [...$operations, ...self::HISTORY_KINDS[$kind]];
        }
        if ($kinds !== [] && $operations === []) {
            return ['answer' => 'applied', 'events' => [], 'has_more' => false, 'cursor' => null];
        }
        try {
            $page = $this->storage->recordChanges($scope, $collection, $recordId, $actor, $cursor, $operations, $limit);
            $current = $this->storage->readStructure($scope, $collection, $actor)->fields;
        } catch (StorageRejected $exception) {
            return ['answer' => 'refused', 'reason' => str_contains($exception->reasonCode, 'scope') || str_contains($exception->reasonCode, 'denied')
                ? 'access_denied' : $exception->reasonCode];
        } catch (Throwable) {
            return ['answer' => 'unavailable', 'reason' => 'history_unavailable'];
        }
        // What the person reads of the record now: a value of any other field is not shown in its history either.
        $readable = [];
        foreach (is_array($read[0]['fields'] ?? null) ? $read[0]['fields'] : [] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null) && !$this->sealedField($field)) {
                $readable[$field['key']] = true;
            }
        }
        $sealedNow = [];
        foreach ($current as $field) {
            if (is_string($field['key'] ?? null) && $this->sealedField($field)) {
                $sealedNow[$field['key']] = true;
            }
        }
        $events = [];
        foreach ($page['versions'] as $version) {
            $fields = $page['fields'][$version['schema_version']] ?? [];
            $events[] = [
                'id' => $recordId.':'.$version['revision'],
                'revision' => $version['revision'],
                'time' => self::isoTime($version['created_at']),
                'actor' => $this->actorName($version['actor']),
                'kind' => self::kindOf($version['operation']),
                'changes' => $this->changes($fields, $version['previous'], $version['values'], $readable, $sealedNow),
            ];
        }
        $last = $events === [] ? null : $events[count($events) - 1]['revision'];

        return ['answer' => 'applied', 'events' => $events, 'has_more' => $page['more'],
            'cursor' => $page['more'] && $last !== null ? (string) $last : null];
    }

    /**
     * The fields one version changed against the one before it, in the order its schema version lists them.
     *
     * @param list<array<string,mixed>> $fields the fields of the version's schema version
     * @param array<string,mixed>|null $before @param array<string,mixed> $after
     * @param array<string,true> $readable @param array<string,true> $sealedNow
     * @return list<array<string,mixed>>
     */
    private function changes(array $fields, ?array $before, array $after, array $readable, array $sealedNow): array
    {
        $before ??= [];
        $described = [];
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $described[$field['key']] = $field;
            }
        }
        $keys = array_keys($described);
        foreach ([...array_keys($before), ...array_keys($after)] as $key) {
            if (!in_array((string) $key, $keys, true)) {
                $keys[] = (string) $key;
            }
        }
        $changes = [];
        foreach ($keys as $key) {
            $old = self::filled($before[$key] ?? null);
            $new = self::filled($after[$key] ?? null);
            $field = $described[$key] ?? ['key' => $key];
            $sealed = isset($sealedNow[$key]) || $this->sealedField($field) || !isset($readable[$key])
                || StorageEncryptedFields::isSealed($old) || StorageEncryptedFields::isSealed($new);
            if ($sealed ? StorageEncryptedFields::same($old, $new) : self::canonical($old) === self::canonical($new)) {
                continue;
            }
            $label = $this->fieldLabel($field);
            $changes[] = $sealed ? ['field' => $key, 'label' => $label, 'sealed' => true]
                : ['field' => $key, 'label' => $label, 'before' => $old, 'after' => $new];
        }

        return $changes;
    }

    /**
     * record.neighbours: the records before and after this one in the list the card was opened from, read with that
     * list's query {filters, search, sort, include_archived} (the data view's shape, sort entries {key, direction}
     * or view-config's {field, dir}). The list's order, never the ids'.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function neighbours(string $actor, string $scope, array $payload): array
    {
        if (!$this->storage instanceof StorageWorkbenchRecordCard) {
            return ['answer' => 'unavailable', 'reason' => 'neighbours_unavailable'];
        }
        $collection = $payload['collection'] ?? null;
        $recordId = $payload['record_id'] ?? null;
        $asked = $payload['query'] ?? [];
        if (!is_string($collection) || preg_match($this->collectionPattern(), $collection) !== 1
            || !is_string($recordId) || preg_match($this->recordPattern(), $recordId) !== 1) {
            return ['answer' => 'refused', 'reason' => 'record_invalid'];
        }
        if (!is_array($asked) || ($asked !== [] && array_is_list($asked))) {
            return ['answer' => 'refused', 'reason' => 'query_invalid'];
        }
        $search = $asked['search'] ?? '';
        $sort = $asked['sort'] ?? [];
        $chosen = $asked['filters'] ?? ($asked['filter'] ?? []);
        $archived = $asked['include_archived'] ?? false;
        if (!is_string($search) || mb_strlen($search) > 100 || !is_bool($archived) || !is_array($sort) || !array_is_list($sort) || count($sort) > 3
            || !is_array($chosen) || ($chosen !== [] && array_is_list($chosen)) || count($chosen) > 20) {
            return ['answer' => 'refused', 'reason' => 'query_invalid'];
        }
        try {
            $structure = $this->storage->readStructure($scope, $collection, $actor);
        } catch (Throwable) {
            return ['answer' => 'refused', 'reason' => 'access_denied'];
        }
        // As the list reads it: hidden fields filter and sort nothing; a filter that does not fit is dropped.
        $known = [];
        foreach ($structure->fields as $field) {
            if (is_string($field['key'] ?? null) && ($field['hidden'] ?? false) !== true) {
                $known[$field['key']] = true;
            }
        }
        $filters = DataviewFilterQuery::fromDataview($chosen, array_keys($known))['filters'];
        $preferences = self::listPreferences();
        if ($preferences !== null) {
            $kept = [];
            foreach ($filters as $key => $filter) {
                try {
                    $kept += $preferences->queryFilters($structure, [$key => $filter]);
                } catch (\InvalidArgumentException) {
                }
            }
            $filters = $kept;
        }
        $order = [];
        foreach ($sort as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? ($entry['field'] ?? null)) : null;
            $direction = is_array($entry) ? ($entry['direction'] ?? ($entry['dir'] ?? null)) : null;
            if (!is_string($key) || !in_array($direction, ['asc', 'desc'], true) || (!isset($known[$key]) && !StorageListOrder::isSystem($key))) {
                return ['answer' => 'refused', 'reason' => 'sort_invalid'];
            }
            $order[] = ['field' => $key, 'direction' => $direction];
        }
        $search = trim($search);
        try {
            $found = $this->storage->recordNeighbours(new StorageWorkbenchRecordQuery($scope, $collection, $filters,
                $search === '' ? null : $search, $order === [] ? StorageListOrder::NEWEST_FIRST : $order, includeArchived: $archived), $recordId, $actor);
        } catch (StorageRejected $exception) {
            return ['answer' => $exception->reasonCode === \Larena\Storage\Runtime\DatabaseStorageWorkbench::VIEW_NOT_READY ? 'unavailable' : 'refused',
                'reason' => str_contains($exception->reasonCode, 'scope') ? 'access_denied' : $exception->reasonCode];
        } catch (Throwable) {
            return ['answer' => 'unavailable', 'reason' => 'neighbours_unavailable'];
        }

        return ['answer' => 'applied', 'listed' => $found['listed'],
            'previous' => $found['previous'] === null ? null : ['record_id' => $found['previous']],
            'next' => $found['next'] === null ? null : ['record_id' => $found['next']]];
    }

    /** The list's filter rules (relative dates, operators by type) when the application has them. */
    private static function listPreferences(): ?DataviewListPreferences
    {
        $container = \Illuminate\Container\Container::getInstance();
        try {
            return $container->bound(DataviewListPreferences::class) ? $container->make(DataviewListPreferences::class) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The person who wrote a version, by name; null when no name is known (a reference is never shown). */
    private function actorName(string $actor): ?string
    {
        if ($actor === '') {
            return null;
        }
        if ($this->names === null) {
            $container = \Illuminate\Container\Container::getInstance();
            try {
                $this->names = $container->bound(AdminIdentityLabelResolver::class) ? $container->make(AdminIdentityLabelResolver::class) : null;
            } catch (Throwable) {
                $this->names = null;
            }
        }
        try {
            $name = $this->names?->resolve($actor);
        } catch (Throwable) {
            $name = null;
        }

        return is_string($name) && $name !== '' && $name !== $actor ? $name : null;
    }

    /** @param array<string,mixed> $field */
    private function fieldLabel(array $field): string
    {
        $key = (string) ($field['key'] ?? '');
        $label = is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $key;
        if (preg_match(PropertyField::LABEL_KEY_PATTERN, $label) === 1) {
            $container = \Illuminate\Container\Container::getInstance();
            $text = $container->bound('translator') ? $container->make('translator')->get($label) : null;

            return is_string($text) && $text !== $label ? $text : $key;
        }

        return $label;
    }

    private static function kindOf(string $operation): string
    {
        foreach (self::HISTORY_KINDS as $kind => $operations) {
            if (in_array($operation, $operations, true)) {
                return $kind;
            }
        }

        return 'update';
    }

    /** Storage's UTC time as ISO 8601 with its offset. */
    private static function isoTime(string $time): string
    {
        try {
            return (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        } catch (Throwable) {
            return $time;
        }
    }

    /** An empty value (none, '' or []) reads as none. */
    private static function filled(mixed $value): mixed
    {
        return $value === '' || $value === [] ? null : $value;
    }

    /** A value compared by content: object keys in order, lists as they are. */
    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $value) use (&$sort): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '';
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
            ? '/admin/storage/'.rawurlencode($collection).'?'.http_build_query(['record_id' => $recordId, 'record_mode' => 'view'], '', '&', PHP_QUERY_RFC3986).'#admin-record-view'
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
