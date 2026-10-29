<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use InvalidArgumentException;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * Labels and choices for record@1 fields: records of the field's target structure, read through
 * the Storage workbench as the current actor in the current scope. A label is the target's title
 * (a field named title or name, else its first string field); the raw identifier never is one, except for a
 * record whose title field is encrypted: its value is never shown, so the record is named by its ID.
 */
final class StorageRecordReferences
{
    /** @var array<string, StorageWorkbenchStructure|null> */
    private array $structures = [];

    /** @var array<string, string|null> */
    private array $labels = [];

    public function __construct(private readonly StorageWorkbench $workbench) {}

    public function label(string $actorRef, string $scopeRef, string $targetStructureId, string $recordId): ?string
    {
        $key = $actorRef."\n".$scopeRef."\n".$targetStructureId."\n".$recordId;
        if (array_key_exists($key, $this->labels)) {
            return $this->labels[$key];
        }
        $structure = $this->structure($actorRef, $scopeRef, $targetStructureId);
        $label = null;
        if ($structure !== null && preg_match('/\Arecord-[0-9a-f]{32}\z/D', $recordId) === 1) {
            try {
                $label = $this->title($structure, $this->workbench->readRecord($scopeRef, $targetStructureId, $recordId, $actorRef));
            } catch (Throwable) {
                $label = null;
            }
        }

        return $this->labels[$key] = $label;
    }

    /**
     * One page of the target structure's active records, ordered by their title.
     *
     * @return array{items:list<array{value:string,label:string}>,next_cursor:?string}
     */
    public function page(string $actorRef, string $scopeRef, string $targetStructureId, ?string $cursor, int $limit): array
    {
        // The cursor is Storage's own continuation, passed back as it came.
        if ($cursor !== null && preg_match('/\A[A-Za-z0-9_-]{1,2048}\z/D', $cursor) !== 1) {
            throw new InvalidArgumentException('record_filter_options_cursor_invalid');
        }
        $structure = $this->structure($actorRef, $scopeRef, $targetStructureId);
        if ($structure === null) {
            throw new InvalidArgumentException('record_filter_options_field_invalid');
        }
        $titleKey = $this->titleKey($structure);
        // Storage sorts by no encrypted field.
        if ($titleKey !== null && $this->encrypted($structure, $titleKey)) {
            $titleKey = null;
        }
        try {
            $result = $this->workbench->listRecords(new StorageWorkbenchRecordQuery(
                $scopeRef,
                $targetStructureId,
                sort: $titleKey === null ? [] : [['field' => $titleKey, 'direction' => 'asc']],
                limit: $limit,
                continuation: $cursor,
                // An option picker pages on; it never shows a total.
                countMode: StorageWorkbenchCountMode::Skip,
            ), $actorRef);
        } catch (StorageRejected $rejected) {
            if ($rejected->reasonCode === 'storage_workbench_record_continuation_invalid') {
                throw new InvalidArgumentException('record_filter_options_cursor_invalid');
            }
            throw $rejected;
        }
        $items = [];
        foreach ($result->items as $record) {
            $label = $this->title($structure, $record);
            if ($label !== null) {
                $items[] = ['value' => $record->recordId, 'label' => $label];
            }
        }

        return ['items' => $items, 'next_cursor' => $result->continuation];
    }

    private function structure(string $actorRef, string $scopeRef, string $structureId): ?StorageWorkbenchStructure
    {
        $key = $actorRef."\n".$scopeRef."\n".$structureId;
        if (!array_key_exists($key, $this->structures)) {
            try {
                $this->structures[$key] = $this->workbench->readStructure($scopeRef, $structureId, $actorRef);
            } catch (Throwable) {
                $this->structures[$key] = null;
            }
        }

        return $this->structures[$key];
    }

    private function encrypted(StorageWorkbenchStructure $structure, string $key): bool
    {
        return in_array($key, StorageEncryptedFields::keys($structure->fields), true);
    }

    private function titleKey(StorageWorkbenchStructure $structure): ?string
    {
        $strings = [];
        foreach ($structure->fields as $field) {
            if (in_array($field['type'] ?? null, ['string', 'text'], true) && is_string($field['key'] ?? null)) {
                $strings[(string) $field['key']] = (int) ($field['position'] ?? 0);
            }
        }
        foreach (['title', 'name'] as $preferred) {
            if (isset($strings[$preferred])) {
                return $preferred;
            }
        }
        asort($strings);

        return array_key_first($strings);
    }

    private function title(StorageWorkbenchStructure $structure, StorageWorkbenchRecord $record): ?string
    {
        $key = $this->titleKey($structure);
        if ($key !== null && $this->encrypted($structure, $key)) {
            return $record->recordId;
        }
        $value = $key === null ? null : ($record->values[$key] ?? null);
        if (!is_string($value)) {
            return null;
        }
        $value = trim(str_replace(["\r", "\n", "\t"], ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, 200);
    }
}
