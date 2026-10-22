<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordPage;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\View\StorageRecordQueryMatcher;
use Larena\Storage\View\StorageRecordQueryNormalizer;
use Larena\Storage\View\StorageViewFieldOptions;

/**
 * The expected answer of a record list, for the витрина tests: every current record
 * of the structure read one by one through the workbench (`readRecord`, which verifies
 * its hash), then filtered, searched and sorted in PHP with the shared matcher, the
 * semantics the витрина's SQL must reproduce. It never reads the витрина.
 *
 * Its continuation is the offset form (version 1) the workbench still continues.
 * Records are cached by revision, so a scenario of many lists stays fast.
 */
final class StorageReferenceList
{
    /** @var array<string, StorageWorkbenchRecord> Records by "id@revision". */
    private array $records = [];
    private SchemaDefinitionNormalizer $normalizer;
    private StorageRecordQueryNormalizer $queryNormalizer;

    public function __construct(
        private readonly Connection $connection,
        private readonly StorageWorkbench $reader,
        private readonly QueryScopeProvider $scopes,
        private readonly string $cursorKey,
    ) {
        $this->normalizer = new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns());
        $this->queryNormalizer = new StorageRecordQueryNormalizer(PropertyTypeRegistry::builtIns());
    }

    public function listRecords(StorageWorkbenchRecordQuery $query, string $actor): StorageWorkbenchRecordPage
    {
        $structure = $this->reader->readStructure($query->scopeRef, $query->structureId, $actor);
        $fields = [];
        $types = [];
        foreach ($structure->fields as $field) {
            $fields[(string) $field['key']] = $field;
            $types[(string) $field['key']] = (string) $field['type'];
        }
        $normalized = $this->queryNormalizer->normalize($fields, $query->filters, $query->search, $query->sort, $query->includeArchived);
        $searchable = StorageViewFieldOptions::resolve($structure->fields)->searchable;
        $matched = [];
        $createdAt = [];
        foreach ($this->connection->table('larena_storage_records')->where('schema_id', $structure->schema->schemaId)
            ->orderBy('record_id')->get(['record_id', 'current_revision', 'created_at']) as $head) {
            $createdAt[(string) $head->record_id] = (string) $head->created_at;
            $key = $head->record_id . '@' . $head->current_revision;
            if (!isset($this->records[$key])) {
                try {
                    $this->records[$key] = $this->reader->readRecord($query->scopeRef, $query->structureId, (string) $head->record_id, $actor);
                } catch (StorageRejected) {
                    continue;
                }
            }
            $record = $this->records[$key];
            if ((!$normalized['include_archived'] && $record->state === 'archived')
                || !StorageRecordQueryMatcher::filtersMatch($record->values, $normalized['filters'], $types)
                || ($normalized['search'] !== null && !StorageRecordQueryMatcher::searchMatches($record->values, $normalized['search'], $searchable))) {
                continue;
            }
            $matched[] = $record;
        }
        $sort = $normalized['sort'];
        usort($matched, static function (StorageWorkbenchRecord $left, StorageWorkbenchRecord $right) use ($sort, $types, $createdAt): int {
            foreach ($sort as $item) {
                $field = $item['field'];
                $value = static fn (StorageWorkbenchRecord $record): mixed => match ($field) {
                    '_record_id' => $record->recordId,
                    '_revision' => $record->revision,
                    '_created_at' => $createdAt[$record->recordId],
                    default => $record->values[$field] ?? null,
                };
                $comparison = StorageRecordQueryMatcher::compareValues($value($left), $value($right), $types[$field] ?? null);
                if ($comparison !== 0) {
                    return $item['direction'] === 'desc' ? -$comparison : $comparison;
                }
            }
            // Equal keys follow the record id in the direction of the last key, as the витрина's indexes read.
            $tiebreak = strcmp($left->recordId, $right->recordId);

            return ($sort[count($sort) - 1]['direction'] ?? 'asc') === 'desc' ? -$tiebreak : $tiebreak;
        });
        [$queryHash, $scopeHash] = $this->hashes($query, $normalized, $actor);
        $offset = $query->page !== null ? ($query->page - 1) * $query->limit : $this->offset($query->continuation, $queryHash, $scopeHash);
        $items = array_slice($matched, $offset, $query->limit);
        $next = $offset + count($items);

        return new StorageWorkbenchRecordPage($items, $next < count($matched) ? $this->continuation($next, $queryHash, $scopeHash) : null, count($matched));
    }

    /**
     * The workbench's query and scope hashes, which its continuations are bound to.
     *
     * @param array<string, mixed> $normalized
     * @return array{0: string, 1: string}
     */
    private function hashes(StorageWorkbenchRecordQuery $query, array $normalized, string $actor): array
    {
        $operation = 'storage.workbench.record.list';
        $decision = $this->scopes->explain('storage.workbench.record', $actor, $operation,
            ['requested_scope_ref' => $query->scopeRef, 'resource_type' => 'storage.workbench.record']);

        return [
            hash('sha256', $this->normalizer->canonicalJson($normalized)),
            hash('sha256', $this->normalizer->canonicalJson([
                'actor' => $decision->actor,
                'operation' => $decision->operation,
                'scope_ref' => $query->scopeRef,
                'target' => $decision->target,
                'reason_code' => $decision->reasonCode,
            ])),
        ];
    }

    private function continuation(int $offset, string $queryHash, string $scopeHash): string
    {
        $payload = ['version' => 1, 'query' => $queryHash, 'scope' => $scopeHash, 'offset' => $offset];
        $payload['signature'] = hash_hmac('sha256', $this->normalizer->canonicalJson($payload), $this->cursorKey);

        return rtrim(strtr(base64_encode($this->normalizer->canonicalJson($payload)), '+/', '-_'), '=');
    }

    private function offset(?string $continuation, string $queryHash, string $scopeHash): int
    {
        if ($continuation === null) {
            return 0;
        }
        $payload = json_decode((string) base64_decode(strtr($continuation, '-_', '+/'), false), true);
        if (!is_array($payload) || ($payload['query'] ?? null) !== $queryHash || ($payload['scope'] ?? null) !== $scopeHash || !is_int($payload['offset'] ?? null)) {
            throw new StorageRejected('storage_workbench_record_continuation_invalid');
        }

        return $payload['offset'];
    }
}
