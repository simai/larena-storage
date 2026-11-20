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
 * - The drafts the history lists are the versions where the document changed: an edit of the record's other fields
 *   (title, slug) keeps the document as it was and makes no draft; it moves the revision of the draft it follows.
 *   The history is read a page at a time, newest first, in chunks of versions: never every version of the record.
 * - Live without a publication of the port (the admin's publication page, a schedule): outside() names it, id null.
 * - The port's own facts that neither a record version nor the publication log can hold go into
 *   larena_storage_document_port_receipts: a write's client_key with the fingerprint of its input (version
 *   correlation ids are stored hashed, so a key could not be read back), the revision a restore came from, and a
 *   publication's id, languages and the publication a rollback repeats.
 */
final class DatabaseDocumentPortStore implements DocumentPortStore
{
    public const RECEIPTS_TABLE = 'larena_storage_document_port_receipts';

    private const VERSIONS_TABLE = 'larena_storage_record_versions';

    /** The versions one query of the history reads: a page of 20 distinct drafts is one query. */
    private const DRAFT_CHUNK = 32;

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

    public function drafts(?string $before = null, int $limit = 20): ?array
    {
        $limit = max(1, $limit);
        $cursor = null;
        $skip = null;
        if ($before !== null) {
            if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $before) !== 1) {
                return null;
            }
            $row = $this->versions()->where('id', (int) $before)->first(['id', 'values_json']);
            if ($row === null) {
                return null;
            }
            // A draft is named by its newest version: the next newer version holds another document (or there is none).
            $skip = $this->decode($row->values_json);
            $newer = $this->versions()->where('id', '>', (int) $row->id)->orderBy('id')->value('values_json');
            if ($newer !== null && $this->decode($newer) == $skip) {
                return null;
            }
            $cursor = (int) $row->id;
        }
        // Newest first, in chunks: a run of versions with the same document is one draft, named by its newest
        // version (the revision a write is compared with), with the time and the restore of its oldest one. A draft
        // is closed by the first older version that holds another document; that version is the next page.
        $groups = [];
        $open = null;
        $more = false;
        foreach ($this->descending($cursor) as $row) {
            $document = $this->decode($row->values_json);
            if ($skip !== null) {
                if ($document == $skip) {
                    continue;
                }
                $skip = null;
            }
            if ($open !== null && $document == $open['document']) {
                $open['first'] = (string) $row->id;
                $open['at'] = (string) $row->created_at;
                continue;
            }
            if ($open !== null) {
                $groups[] = $open;
                if (count($groups) === $limit) {
                    $more = true;
                    $open = null;
                    break;
                }
            }
            $open = ['revision' => (string) $row->id, 'first' => (string) $row->id, 'at' => (string) $row->created_at, 'document' => $document];
        }
        if ($open !== null) {
            $groups[] = $open;
        }
        $restored = $groups === [] ? [] : $this->database->table(self::RECEIPTS_TABLE)
            ->where('schema_id', $this->schemaId())->where('record_id', $this->binding->recordId)->where('field_key', $this->binding->fieldKey)
            ->whereIn('revision', array_map(static fn (array $group): int => (int) $group['first'], $groups))
            ->whereNotNull('restored_from')->pluck('restored_from', 'revision')->all();
        $drafts = [];
        foreach ($groups as $group) {
            $drafts[] = ['revision' => $group['revision'], 'at' => self::at($group['at']), 'languages' => array_map('strval', array_keys($group['document']))]
                + (isset($restored[$group['first']]) ? ['restored_from' => (string) $restored[$group['first']]] : []);
        }

        return ['drafts' => $drafts, 'more' => $more];
    }

    /**
     * The record's versions newest first, older than $cursor when given, read DRAFT_CHUNK rows a query.
     *
     * @return \Generator<int, object>
     */
    private function descending(?int $cursor): \Generator
    {
        do {
            $rows = $this->versions()->when($cursor !== null, static fn ($query) => $query->where('id', '<', $cursor))
                ->orderByDesc('id')->limit(self::DRAFT_CHUNK)->get(['id', 'created_at', 'values_json'])->all();
            foreach ($rows as $row) {
                $cursor = (int) $row->id;
                yield $row;
            }
        } while (count($rows) === self::DRAFT_CHUNK);
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

    public function publicationPage(?string $before = null, int $limit = 20): ?array
    {
        $limit = max(1, $limit);
        $query = $this->receipts()->whereNotNull('publication_id');
        if ($before !== null) {
            $at = $this->receipts()->where('publication_id', $before)->value('id');
            if ($at === null) {
                return null;
            }
            $query->where('id', '<', (int) $at);
        }
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get()->all();
        $live = $rows === [] ? null : $this->live();
        $list = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $list[] = $this->publication($row, $live);
        }

        return ['publications' => $list, 'more' => count($rows) > $limit];
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

    public function outside(): ?array
    {
        $site = $this->binding->siteScopeRef;
        if ($site === null) {
            return null;
        }
        $languages = [];
        $revisions = [];
        $times = [];
        foreach ($this->binding->languages as $language) {
            $head = $this->publications->head($this->schemaId(), $this->binding->recordId, $site, $language);
            if ($head === null || !$head->isPublished()) {
                continue;
            }
            $languages[] = $language;
            $revisions[] = (int) $head->publishedRevision;
            if ($head->publishedAt !== null && $head->publishedAt !== '') {
                $times[] = self::at($head->publishedAt);
            }
        }
        if ($languages === []) {
            return null;
        }
        $revisions = array_values(array_unique($revisions));
        $row = count($revisions) === 1 ? $this->versions()->where('revision', $revisions[0])->value('id') : null;
        rsort($times);

        return ['id' => null] + ($row !== null ? ['revision' => (string) $row] : []) + ['state' => 'published']
            + ($times !== [] ? ['at' => $times[0]] : []) + ['languages' => $languages];
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
