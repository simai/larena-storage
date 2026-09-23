<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface RecordRelations
{
    /**
     * Define one edge. For a tree relation the child may have only one parent,
     * and a cycle, a cross-scope parent or a cross-schema parent is refused.
     */
    public function define(
        RelationDescriptor $descriptor,
        string $schemaId,
        string $fromRecordId,
        string $toRecordId,
        string $actorId,
        ?int $orderIndex = null,
        ?string $correlationId = null,
    ): RelationRecord;

    /**
     * @return list<RelationRecord> the edges of this key leaving this record
     */
    public function resolve(string $relationKey, string $fromRecordId): array;

    /**
     * Direct children of a parent, in sibling order.
     */
    public function children(
        string $relationKey,
        string $parentRecordId,
        ?int $budget = null,
        ?callable $visibilityFilter = null,
    ): RelationTraversalPage;

    /**
     * Ancestors of a record, nearest last. Read from the materialized path, so no
     * recursive query is issued.
     */
    public function ancestors(
        string $relationKey,
        string $recordId,
        ?int $budget = null,
        ?callable $visibilityFilter = null,
    ): RelationTraversalPage;

    /**
     * Move a record under a new parent, rewriting the path and depth of the whole
     * subtree inside one transaction and preserving sibling order.
     */
    public function move(
        string $relationKey,
        string $recordId,
        ?string $newParentRecordId,
        string $actorId,
        ?int $orderIndex = null,
        ?string $correlationId = null,
    ): RelationTraversalPage;

    /**
     * Apply the declared delete policy for one record.
     *
     * @return array<string, mixed> what the policy did
     */
    public function deleteRecord(
        string $relationKey,
        string $recordId,
        string $actorId,
        ?string $correlationId = null,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function explain(string $relationKey, string $recordId): array;
}
