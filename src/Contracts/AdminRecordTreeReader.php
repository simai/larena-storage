<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * The current records of one structure with their tree parents, for an editor.
 * Drafts included; nothing here is public.
 */
interface AdminRecordTreeReader
{
    /**
     * Flat rows, one per current record, each naming its parent under the tree
     * relation (null at the root), in sibling order.
     *
     * @return list<array{record_id: string, parent_record_id: ?string, order_index: int, revision: int, schema_version: int, owner_ref: string, values: array<string, mixed>}>
     */
    public function tree(string $schemaId, string $relationKey, string $actor, int $limit = 500): array;
}
