<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

use Illuminate\Database\ConnectionInterface;
use JsonException;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * A document element on Storage's own records: the `composition@1` field of one workbench record.
 *
 * - A draft is a record version: the revision the port answers is the id of its row in
 *   larena_storage_record_versions, unique and never reused, so one revision stands for every language of the record.
 *   A save writes the record through the workbench (its access, role checks, history keep and витрина), with the
 *   record's other fields as they are; the workbench's compare-and-swap refuses a draft that moved.
 * - A publication is the record's revision published through the publication lifecycle in each of its languages
 *   (and taken down in the element's other languages), under the site scope of the binding; the head of each
 *   language is what the site reads.
 * - The port's own facts that neither a record version nor the publication log can hold go into
 *   larena_storage_document_port_receipts: a write's client_key with the fingerprint of its input (version
 *   correlation ids are stored hashed, so a key could not be read back), the revision a restore came from, and a
 *   publication's id, languages and the publication a rollback repeats.
 */
final class DatabaseDocumentPortStore implements DocumentPortStore
{
    public const RECEIPTS_TABLE = 'larena_storage_document_port_receipts';

    private const VERSIONS_TABLE = 'larena_storage_record_versions';

    private ?string $schemaId = null;

    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly StorageWorkbench $workbench,
        private readonly PublicationLifecycle $publications,
        private readonly DocumentPortBinding $binding,
        private readonly string $actor,
    ) {
    }

    public function current(): array
    {
        $record = $this->record();

        return ['revision' => $this->rowOf($record->revision), 'at' => self::at($record->updatedAt), 'values' => $this->field($record->values)];
    }

    public function drafts(): array
    {
        $restored = $this->database->table(self::RECEIPTS_TABLE)
            ->where('schema_id', $this->schemaId())->where('record_id', $this->binding->recordId)->where('field_key', $this->binding->fieldKey)
            ->whereNotNull('restored_from')->pluck('restored_from', 'revision')->all();
        $drafts = [];
        foreach ($this->versions()->orderByDesc('id')->get(['id', 'created_at', 'values_json']) as $row) {
            $id = (string) $row->id;
            $drafts[] = ['revision' => $id, 'at' => self::at((string) $row->created_at), 'languages' => array_map('strval', array_keys($this->decode($row->values_json)))]
                + (isset($restored[$id]) ? ['restored_from' => (string) $restored[$id]] : []);
        }

        return $drafts;
    }

    public function valuesAt(string $revision): ?array
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $revision) !== 1) {
            return null;
        }
        $json = $this->versions()->where('id', (int) $revision)->value('values_json');

        return $json === null ? null : $this->decode($json);
    }

    public function write(string $expected, array $values, ?array $receipt): array
    {
        return $this->database->transaction(function () use ($expected, $values, $receipt): array {
            $record = $this->record();
            if ($this->rowOf($record->revision) !== $expected) {
                throw new StorageConflict('storage_workbench_record_revision_conflict');
            }
            $next = $record->values;
            $next[$this->binding->fieldKey] = $values;
            $written = $this->workbench->updateRecord($this->binding->scopeRef, $this->binding->structureId, $this->binding->recordId,
                $record->revision, $next, $this->actor, $receipt === null ? null : 'document-port:' . $receipt['client_key']);
            $revision = $this->rowOf($written->revision);
            if ($receipt !== null) {
                $this->database->table(self::RECEIPTS_TABLE)->insert($this->receiptRow($receipt) + [
                    'revision' => (int) $revision,
                    'restored_from' => isset($receipt['restored_from']) ? (int) $receipt['restored_from'] : null,
                ]);
            }

            return ['revision' => $revision, 'at' => self::at($written->updatedAt), 'values' => $this->field($written->values)];
        });
    }

    public function publications(): array
    {
        $live = $this->live();
        $list = [];
        foreach ($this->receipts()->whereNotNull('publication_id')->orderByDesc('id')->get() as $row) {
            $list[] = $this->publication($row, $live);
        }

        return $list;
    }

    public function live(): ?string
    {
        $site = $this->binding->siteScopeRef;
        if ($site === null) {
            return null;
        }
        $newest = $this->receipts()->whereNotNull('publication_id')->orderByDesc('id')->first();
        if ($newest === null) {
            return null;
        }
        $revision = $this->recordRevisionOf((string) $newest->revision);
        foreach ($this->languagesOf($newest->languages) as $language) {
            $head = $this->publications->head($this->schemaId(), $this->binding->recordId, $site, $language);
            if ($head === null || !$head->isPublished() || $head->publishedRevision !== $revision) {
                return null;
            }
        }

        return (string) $newest->publication_id;
    }

    /**
     * @param list<string> $languages
     * @param array{intent: string, client_key: string, params_hash: string} $receipt
     * @param string|null $id the publication's id; one is made when null (a fixture names its own)
     */
    public function publish(string $revision, array $languages, ?string $from, array $receipt, ?string $id = null): array
    {
        $site = $this->binding->siteScopeRef ?? throw new StorageRejected('document_publication_site_missing');
        $recordRevision = $this->recordRevisionOf($revision) ?? throw new StorageRejected('document_revision_not_found');

        return $this->database->transaction(function () use ($site, $revision, $recordRevision, $languages, $from, $receipt, $id): array {
            $row = $this->receiptRow($receipt) + ['revision' => (int) $revision, 'publication_id' => $id ?? 'pending:' . bin2hex(random_bytes(8)),
                'publication_from' => $from, 'languages' => json_encode($languages, JSON_THROW_ON_ERROR)];
            $receiptId = (int) $this->database->table(self::RECEIPTS_TABLE)->insertGetId($row);
            $id ??= 'p' . $receiptId;
            $this->database->table(self::RECEIPTS_TABLE)->where('id', $receiptId)->update(['publication_id' => $id]);
            $correlation = 'document-port:' . $id;
            foreach ($this->binding->languages as $language) {
                if (in_array($language, $languages, true)) {
                    $this->publications->publish($this->schemaId(), $this->binding->recordId, $site, $language, $recordRevision, $this->actor, $correlation);
                    continue;
                }
                if ($this->publications->head($this->schemaId(), $this->binding->recordId, $site, $language)?->isPublished() === true) {
                    $this->publications->unpublish($this->schemaId(), $this->binding->recordId, $site, $language, $this->actor, $correlation);
                }
            }
            $made = $this->receipts()->where('id', $receiptId)->first();

            return $this->publication($made, $id);
        });
    }

    public function receipt(string $clientKey): ?array
    {
        $row = $this->receipts()->where('client_key', $clientKey)->first();

        return $row === null ? null : [
            'intent' => (string) $row->intent,
            'params_hash' => (string) $row->params_hash,
            'revision' => $row->revision === null ? null : (string) $row->revision,
            'publication' => $row->publication_id === null ? null : (string) $row->publication_id,
        ];
    }

    private function record(): StorageWorkbenchRecord
    {
        $record = $this->workbench->readRecord($this->binding->scopeRef, $this->binding->structureId, $this->binding->recordId, $this->actor);

        return $record;
    }

    private function schemaId(): string
    {
        return $this->schemaId ??= $this->workbench->readStructure($this->binding->scopeRef, $this->binding->structureId, $this->actor)->schema->schemaId;
    }

    private function versions(): \Illuminate\Database\Query\Builder
    {
        return $this->database->table(self::VERSIONS_TABLE)->where('schema_id', $this->schemaId())->where('record_id', $this->binding->recordId);
    }

    private function receipts(): \Illuminate\Database\Query\Builder
    {
        return $this->database->table(self::RECEIPTS_TABLE)
            ->where('schema_id', $this->schemaId())->where('record_id', $this->binding->recordId)->where('field_key', $this->binding->fieldKey);
    }

    /** The row id of a record revision: the revision the port answers. */
    private function rowOf(int $recordRevision): string
    {
        $id = $this->versions()->where('revision', $recordRevision)->value('id');

        return $id === null ? throw new StorageRejected('storage_workbench_record_head_corrupt') : (string) $id;
    }

    private function recordRevisionOf(string $revision): ?int
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $revision) !== 1) {
            return null;
        }
        $recordRevision = $this->versions()->where('id', (int) $revision)->value('revision');

        return $recordRevision === null ? null : (int) $recordRevision;
    }

    /** @param array<array-key, mixed> $values @return array<string, mixed> */
    private function field(array $values): array
    {
        $value = $values[$this->binding->fieldKey] ?? [];

        return is_array($value) ? $value : [];
    }

    /** The composition value of a version row. @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        try {
            $values = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StorageRejected('storage_workbench_record_head_corrupt');
        }

        return is_array($values) ? $this->field($values) : [];
    }

    /** @return list<string> */
    private function languagesOf(mixed $json): array
    {
        $languages = is_string($json) ? json_decode($json, true) : null;

        return is_array($languages) ? array_values(array_filter($languages, is_string(...))) : [];
    }

    /**
     * @param array{intent: string, client_key: string, params_hash: string} $receipt
     * @return array<string, mixed>
     */
    private function receiptRow(array $receipt): array
    {
        return [
            'schema_id' => $this->schemaId(),
            'record_id' => $this->binding->recordId,
            'field_key' => $this->binding->fieldKey,
            'intent' => $receipt['intent'],
            'client_key' => $receipt['client_key'],
            'params_hash' => $receipt['params_hash'],
            'actor_id' => $this->actor,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @return array{id: string, revision: string, state: string, at: string, languages: list<string>, from?: string, client_key?: string} */
    private function publication(object $row, ?string $live): array
    {
        return [
            'id' => (string) $row->publication_id,
            'revision' => (string) $row->revision,
            'state' => $row->publication_id === $live ? 'published' : 'superseded',
            'at' => self::at((string) $row->created_at),
            'languages' => $this->languagesOf($row->languages),
        ] + ($row->publication_from !== null ? ['from' => (string) $row->publication_from] : [])
          + ($row->client_key !== null ? ['client_key' => (string) $row->client_key] : []);
    }

    /** A stored time (UTC) as the port writes it: 2026-11-20T10:00:00Z. */
    private static function at(string $time): string
    {
        $parsed = date_create_immutable($time, new \DateTimeZone('UTC'));

        return $parsed === false ? $time : $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
