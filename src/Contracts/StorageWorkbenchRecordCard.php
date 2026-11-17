<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * What the Framework record card (C305) reads about one record besides its values: the record's changes, newest
 * first, a page at a time, and the records before and after it in the list the person opened it from.
 *
 * The changes come from the record's versions (`larena_storage_record_versions`), read by the unique
 * (schema_id, record_id, revision) index: a page is a range of revisions below the cursor, never a walk of the
 * whole history. The neighbours come from the storage's витрина with the list's own query (filters, search, sort),
 * so «next» is the next row the person sees, not the next id.
 */
interface StorageWorkbenchRecordCard
{
    /** The most versions one page of changes reads. */
    public const MAX_PAGE = 50;

    /**
     * One page of the record's versions, newest first, under `storage.workbench.record.history`.
     *
     * Each version carries the values it was written with (the reserved scope and state keys left out) and the
     * values of the version before it, so the caller works out what changed; `previous` is null for the first
     * version still kept. Encrypted values are their envelopes (or left out while an encryption run has not
     * reached the version): a caller never shows them. `fields` names each schema version's fields as the
     * structure described them then (labels), by schema version.
     *
     * @param ?int $before only versions with a revision below this one (the cursor); null for the newest
     * @param list<string> $operations only versions written by these operations (create, update, delete,
     *                                 restore, schema_migration, revert); all when empty
     * @return array{
     *     versions: list<array{revision: int, schema_version: int, operation: string, actor: string,
     *         created_at: string, values: array<string, mixed>, previous: array<string, mixed>|null}>,
     *     more: bool,
     *     fields: array<int, list<array<string, mixed>>>
     * }
     * @throws StorageRejected storage_workbench_record_unknown, storage_workbench_history_limit_invalid, a refused
     *         scope or right
     */
    public function recordChanges(
        string $scopeRef,
        string $structureId,
        string $recordId,
        string $actor,
        ?int $before = null,
        array $operations = [],
        int $limit = 20,
    ): array;

    /**
     * The records just before and just after this one in the list the query reads (its filters, search and sort,
     * archived records only when it includes them), under `storage.workbench.record.list`. `listed` is false when
     * the record is not in that list (filtered out meanwhile): both neighbours are null then.
     *
     * @return array{previous: ?string, next: ?string, listed: bool}
     * @throws StorageRejected a refused query, a витрина not ready, a refused scope or right
     */
    public function recordNeighbours(StorageWorkbenchRecordQuery $query, string $recordId, string $actor): array;
}
