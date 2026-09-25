<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Storage\Contracts\RecordRelations;
use Larena\Storage\Contracts\RelationDescriptor;
use Larena\Storage\Contracts\RelationRecord;
use Larena\Storage\Contracts\RelationTraversalPage;
use Larena\Storage\Enums\RelationDeletePolicy;
use Larena\Storage\Enums\RelationKind;
use Larena\Storage\Enums\RelationStatus;
use Larena\Storage\Exceptions\RelationRejected;
use Throwable;

/**
 * Relations and trees on one table, with no recursive query anywhere.
 *
 * Children are read by parent; ancestors are read from the child's materialized
 * path; a subtree is a prefix match. That keeps the behaviour identical on MySQL
 * 5.7, MySQL 8 and SQLite, which is the constraint the whole design serves.
 *
 * The single parent per child is enforced by a unique index rather than by a check
 * in this class, because a check can lose a race and an index cannot.
 */
final class DatabaseRecordRelations implements RecordRelations
{
    public const TABLE = 'larena_storage_record_relations';

    public const DEFAULT_BUDGET = 2000;

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @phpstan-impure */
    public function define(
        RelationDescriptor $descriptor,
        string $schemaId,
        string $fromRecordId,
        string $toRecordId,
        string $actorId,
        ?int $orderIndex = null,
        ?string $correlationId = null,
    ): RelationRecord {
        $this->assertSchema();
        $this->assertRecordId($fromRecordId);
        $this->assertRecordId($toRecordId);

        if ($fromRecordId === $toRecordId) {
            throw new RelationRejected('cycle_detected', 'A record cannot relate to itself in a tree or a reference.');
        }

        $relationId = RelationRecord::identity($descriptor->relationKey, $fromRecordId, $toRecordId);
        if (strlen($relationId) > 190) {
            throw new RelationRejected('relation_identity_too_long', 'Relation identity exceeds 190 characters.');
        }

        $path = null;
        $depth = 0;

        if ($descriptor->kind->isTree()) {
            $parent = $this->treeEdgeForChild($descriptor->relationKey, $toRecordId);
            $parentPath = $parent === null
                ? RelationPath::root($toRecordId)
                : RelationPath::parse((string) $parent->path);

            if ($parentPath->contains($fromRecordId)) {
                throw new RelationRejected('cycle_detected', 'A record cannot become its own ancestor.');
            }

            if ($parent !== null && $parent->schemaId !== $schemaId) {
                throw new RelationRejected('cross_schema_parent', 'A tree parent must live in the same schema.');
            }

            $childPath = $parentPath->child($fromRecordId);
            $path = $childPath->toString();
            $depth = $childPath->depth();
        }

        $now = $this->now();
        $order = $orderIndex ?? $this->nextOrderIndex($descriptor->relationKey, $toRecordId);

        try {
            $this->connection->table(self::TABLE)->insert([
                'relation_id' => $relationId,
                'relation_key' => $descriptor->relationKey,
                'schema_id' => $schemaId,
                'from_record_id' => $fromRecordId,
                'to_record_id' => $toRecordId,
                'kind' => $descriptor->kind->value,
                // NULL for a reference, the child id for a tree edge: this column
                // is the single-parent guarantee.
                'tree_child_key' => $descriptor->kind->isTree() ? $fromRecordId : null,
                'path' => $path,
                'depth' => $depth,
                'order_index' => $order,
                'delete_policy' => $descriptor->deletePolicy->value,
                'status' => RelationStatus::Active->value,
                'created_by' => $actorId,
                'correlation_id' => $correlationId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $failure) {
            if ($this->isUniqueViolation($failure)) {
                // Which unique index fired tells the caller what they did.
                throw new RelationRejected(
                    $descriptor->kind->isTree() && $this->treeEdgeForChild($descriptor->relationKey, $fromRecordId) !== null
                        ? 'second_tree_parent'
                        : 'duplicate_relation',
                    'The relation already exists.',
                );
            }

            throw $failure;
        }

        return new RelationRecord(
            relationId: $relationId,
            relationKey: $descriptor->relationKey,
            schemaId: $schemaId,
            fromRecordId: $fromRecordId,
            toRecordId: $toRecordId,
            kind: $descriptor->kind,
            deletePolicy: $descriptor->deletePolicy,
            status: RelationStatus::Active,
            path: $path,
            depth: $depth,
            orderIndex: $order,
            createdBy: $actorId,
            correlationId: $correlationId,
        );
    }

    /**
     * @return list<RelationRecord>
     * @phpstan-impure
     */
    public function resolve(string $relationKey, string $fromRecordId): array
    {
        $this->assertSchema();

        $rows = $this->connection->table(self::TABLE)
            ->where('relation_key', $relationKey)
            ->where('from_record_id', $fromRecordId)
            ->where('status', RelationStatus::Active->value)
            ->orderBy('order_index')
            ->orderBy('to_record_id')
            ->get();

        $records = [];
        foreach ($rows as $row) {
            $records[] = $this->hydrate((array) $row);
        }

        return $records;
    }

    /** @phpstan-impure */
    public function children(
        string $relationKey,
        string $parentRecordId,
        ?int $budget = null,
        ?callable $visibilityFilter = null,
    ): RelationTraversalPage {
        $this->assertSchema();
        $limit = $this->budget($budget);

        $rows = $this->connection->table(self::TABLE)
            ->where('relation_key', $relationKey)
            ->where('to_record_id', $parentRecordId)
            ->where('kind', RelationKind::TreeParent->value)
            ->where('status', RelationStatus::Active->value)
            ->orderBy('order_index')
            ->orderBy('from_record_id')
            // One row beyond the budget, so truncation is observed rather than
            // guessed from a full page.
            ->limit($limit + 1)
            ->get();

        return $this->page($rows->all(), $limit, $visibilityFilter, [$parentRecordId]);
    }

    /** @phpstan-impure */
    public function ancestors(
        string $relationKey,
        string $recordId,
        ?int $budget = null,
        ?callable $visibilityFilter = null,
    ): RelationTraversalPage {
        $this->assertSchema();
        $limit = $this->budget($budget);

        $edge = $this->treeEdgeForChild($relationKey, $recordId);
        if ($edge === null || $edge->path === null) {
            return new RelationTraversalPage([], false, 0, $limit);
        }

        $ancestorIds = RelationPath::parse($edge->path)->ancestorIds();
        if ($ancestorIds === []) {
            return new RelationTraversalPage([], false, 0, $limit);
        }

        // The path already holds the ancestry, so this is one query by id set and
        // never a walk up the table.
        $rows = $this->connection->table(self::TABLE)
            ->where('relation_key', $relationKey)
            ->where('kind', RelationKind::TreeParent->value)
            ->whereIn('from_record_id', $ancestorIds)
            ->get()
            ->keyBy('from_record_id');

        $ordered = [];
        foreach ($ancestorIds as $ancestorId) {
            $row = $rows->get($ancestorId);
            if ($row !== null) {
                $ordered[] = $row;
            }
        }

        return $this->page(array_slice($ordered, 0, $limit + 1), $limit, $visibilityFilter, [$recordId]);
    }

    /** @phpstan-impure */
    public function move(
        string $relationKey,
        string $recordId,
        ?string $newParentRecordId,
        string $actorId,
        ?int $orderIndex = null,
        ?string $correlationId = null,
    ): RelationTraversalPage {
        $this->assertSchema();

        $edge = $this->treeEdgeForChild($relationKey, $recordId)
            ?? throw new RelationRejected('unknown_relation', 'The record has no tree edge for this relation.');

        if ($newParentRecordId === $recordId) {
            throw new RelationRejected('cycle_detected', 'A record cannot become its own parent.');
        }

        $currentPath = RelationPath::parse((string) $edge->path);

        $newPath = $currentPath;
        if ($newParentRecordId === null) {
            // Becoming a root: the path is the record itself.
            $newPath = RelationPath::root($recordId);
        } else {
            $parentEdge = $this->treeEdgeForChild($relationKey, $newParentRecordId);
            $parentPath = $parentEdge === null
                ? RelationPath::root($newParentRecordId)
                : RelationPath::parse((string) $parentEdge->path);

            if ($parentPath->contains($recordId)) {
                throw new RelationRejected('cycle_detected', 'A record cannot be moved into its own subtree.');
            }

            if ($parentEdge !== null && $parentEdge->schemaId !== $edge->schemaId) {
                throw new RelationRejected('cross_schema_parent', 'A tree parent must live in the same schema.');
            }

            $newPath = $parentPath->child($recordId);
        }

        $moved = [];

        $this->connection->transaction(function () use (
            $relationKey,
            $recordId,
            $newParentRecordId,
            $edge,
            $currentPath,
            $newPath,
            $orderIndex,
            $actorId,
            $correlationId,
            &$moved,
        ): void {
            $now = $this->now();
            $order = $orderIndex ?? $this->nextOrderIndex($relationKey, $newParentRecordId ?? $recordId);

            // The identity encodes the parent, so a move rewrites it too. Leaving
            // the old identity in place would mean a primary key that names a
            // parent the row no longer has — true of the row when it was created
            // and false of the row as it is.
            $this->connection->table(self::TABLE)
                ->where('relation_id', $edge->relationId)
                ->update([
                    'relation_id' => RelationRecord::identity(
                        $relationKey,
                        $recordId,
                        $newParentRecordId ?? $recordId,
                    ),
                    'to_record_id' => $newParentRecordId ?? $recordId,
                    'path' => $newPath->toString(),
                    'depth' => $newPath->depth(),
                    'order_index' => $order,
                    'created_by' => $edge->createdBy ?? $actorId,
                    'correlation_id' => $correlationId,
                    'updated_at' => $now,
                ]);

            // Every descendant keeps its position relative to the moved record, so
            // the rewrite is a prefix swap and sibling order is untouched.
            $descendants = $this->connection->table(self::TABLE)
                ->where('relation_key', $relationKey)
                ->where('kind', RelationKind::TreeParent->value)
                ->where('path', 'like', $currentPath->descendantPrefix() . '%')
                ->orderBy('depth')
                ->get();

            foreach ($descendants as $row) {
                $row = (array) $row;
                $suffix = substr((string) $row['path'], strlen($currentPath->descendantPrefix()));
                $rewritten = RelationPath::parse($newPath->toString() . RelationPath::SEPARATOR . $suffix);

                $this->connection->table(self::TABLE)
                    ->where('relation_id', $row['relation_id'])
                    ->update([
                        'path' => $rewritten->toString(),
                        'depth' => $rewritten->depth(),
                        'updated_at' => $now,
                    ]);
            }

            $moved = $this->connection->table(self::TABLE)
                ->where('relation_key', $relationKey)
                ->where('kind', RelationKind::TreeParent->value)
                ->where(function ($query) use ($newPath): void {
                    $query->where('path', $newPath->toString())
                        ->orWhere('path', 'like', $newPath->descendantPrefix() . '%');
                })
                ->orderBy('depth')
                ->orderBy('order_index')
                ->get()
                ->all();
        });

        return $this->page($moved, $this->budget(null), null);
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function deleteRecord(
        string $relationKey,
        string $recordId,
        string $actorId,
        ?string $correlationId = null,
    ): array {
        $this->assertSchema();

        $edge = $this->treeEdgeForChild($relationKey, $recordId);
        $policy = $edge === null ? $this->declaredPolicy($relationKey) : $edge->deletePolicy;

        $descendantQuery = fn () => $this->connection->table(self::TABLE)
            ->where('relation_key', $relationKey)
            ->where('kind', RelationKind::TreeParent->value)
            ->where('path', 'like', ($edge === null ? $recordId : (string) $edge->path) . RelationPath::SEPARATOR . '%');

        $descendantCount = $edge === null ? 0 : $descendantQuery()->count();

        if ($policy === RelationDeletePolicy::Restrict && $descendantCount > 0) {
            throw new RelationRejected(
                'delete_restricted',
                'The record has ' . $descendantCount . ' descendant(s) and the relation restricts deletion.',
            );
        }

        $removed = [];
        $detached = [];

        $this->connection->transaction(function () use (
            $relationKey,
            $recordId,
            $edge,
            $policy,
            $descendantQuery,
            $correlationId,
            &$removed,
            &$detached,
        ): void {
            $now = $this->now();

            if ($policy === RelationDeletePolicy::Cascade && $edge !== null) {
                foreach ($descendantQuery()->get() as $row) {
                    $removed[] = (string) ((array) $row)['from_record_id'];
                }

                $descendantQuery()->delete();
            }

            if ($policy === RelationDeletePolicy::Detach && $edge !== null) {
                // The descendants stay reachable: only the edge to the removed
                // record is detached, and each former child becomes a root.
                $children = $this->connection->table(self::TABLE)
                    ->where('relation_key', $relationKey)
                    ->where('to_record_id', $recordId)
                    ->where('kind', RelationKind::TreeParent->value)
                    ->get();

                foreach ($children as $row) {
                    $row = (array) $row;
                    $childId = (string) $row['from_record_id'];
                    $detached[] = $childId;
                    $rootPath = RelationPath::root($childId);

                    $this->connection->table(self::TABLE)
                        ->where('relation_id', $row['relation_id'])
                        ->update([
                            'to_record_id' => $childId,
                            'path' => $rootPath->toString(),
                            'depth' => $rootPath->depth(),
                            'status' => RelationStatus::Detached->value,
                            'correlation_id' => $correlationId,
                            'updated_at' => $now,
                        ]);
                }
            }

            // The record's own edges go in every policy.
            $this->connection->table(self::TABLE)
                ->where('relation_key', $relationKey)
                ->where(function ($query) use ($recordId): void {
                    $query->where('from_record_id', $recordId)->orWhere('to_record_id', $recordId);
                })
                ->when($policy === RelationDeletePolicy::Detach, function ($query) use ($recordId) {
                    // A detached child keeps its own row, rewritten above.
                    return $query->where('from_record_id', $recordId);
                })
                ->delete();
        });

        return [
            'relation_key' => $relationKey,
            'record_id' => $recordId,
            'policy' => $policy->value,
            'descendant_count' => $descendantCount,
            'removed' => $removed,
            'detached' => $detached,
        ];
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function explain(string $relationKey, string $recordId): array
    {
        $this->assertSchema();

        $edge = $this->treeEdgeForChild($relationKey, $recordId);

        // Counted separately from the tree edge: an explain that folded the two
        // together would report a record with one parent and no references as
        // having one reference.
        $referenceCount = 0;
        foreach ($this->resolve($relationKey, $recordId) as $relation) {
            if (!$relation->kind->isTree()) {
                ++$referenceCount;
            }
        }

        return [
            'relation_key' => $relationKey,
            'record_id' => $recordId,
            'has_tree_edge' => $edge !== null,
            'path' => $edge === null ? null : $edge->path,
            'depth' => $edge === null ? 0 : $edge->depth,
            'parent_record_id' => $edge === null ? null : $edge->toRecordId,
            'reference_count' => $referenceCount,
            'traversal_budget' => self::DEFAULT_BUDGET,
            'audit_events' => \Larena\Storage\Audit\RelationAuditEventCatalog::all(),
        ];
    }

    /** @phpstan-impure */
    private function treeEdgeForChild(string $relationKey, string $childRecordId): ?RelationRecord
    {
        $row = $this->connection->table(self::TABLE)
            ->where('relation_key', $relationKey)
            ->where('tree_child_key', $childRecordId)
            ->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    /** @phpstan-impure */
    private function declaredPolicy(string $relationKey): RelationDeletePolicy
    {
        $row = $this->connection->table(self::TABLE)->where('relation_key', $relationKey)->first();
        if ($row === null) {
            // Nothing declares this relation, so nothing may be assumed about it.
            throw new RelationRejected('unknown_relation', 'No relation is declared for ' . $relationKey . '.');
        }

        return RelationDeletePolicy::from((string) ((array) $row)['delete_policy']);
    }

    /**
     * Turn rows into a page, applying the caller's visibility filter.
     *
     * Redaction is the delicate part. A materialized path spells out every
     * ancestor id and every edge names its parent, so handing those back to a
     * caller who is being filtered would leak exactly what the filter hides. But
     * blanking every identifier would also hide ancestors the caller *may* read,
     * which makes the answer useless for building a tree.
     *
     * So the filter is evaluated for the whole slice first, and only the ids it
     * rejected are masked — plus the ids the caller already supplied, which are
     * never a leak because the caller named them.
     *
     * @param list<mixed> $rows
     * @param list<string> $knownIds ids the caller passed in and therefore knows
     */
    private function page(array $rows, int $limit, ?callable $visibilityFilter, array $knownIds = []): RelationTraversalPage
    {
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $hydrated = [];
        foreach ($rows as $row) {
            $hydrated[] = $this->hydrate((array) $row);
        }

        if ($visibilityFilter === null) {
            return new RelationTraversalPage($hydrated, $truncated, 0, $limit);
        }

        $visible = [];
        $filtered = 0;
        foreach ($hydrated as $record) {
            if ($visibilityFilter($record) === true) {
                $visible[] = $record;

                continue;
            }

            ++$filtered;
        }

        $knownSet = array_fill_keys($knownIds, true);
        foreach ($visible as $record) {
            $knownSet[$record->fromRecordId] = true;
        }

        $records = [];
        foreach ($visible as $record) {
            $records[] = $this->redact($record, $knownSet);
        }

        return new RelationTraversalPage($records, $truncated, $filtered, $limit);
    }

    /**
     * Replace every identifier the caller is not allowed to learn with `*`,
     * keeping the depth and the order, which are structural rather than
     * identifying.
     *
     * @param array<string, bool> $knownIds
     */
    private function redact(RelationRecord $record, array $knownIds): RelationRecord
    {
        $parentVisible = isset($knownIds[$record->toRecordId]);
        $path = $record->path;

        if ($path !== null) {
            $segments = [];
            foreach (RelationPath::parse($path)->segments as $segment) {
                $segments[] = $segment === $record->fromRecordId || isset($knownIds[$segment]) ? $segment : '*';
            }

            $path = implode(RelationPath::SEPARATOR, $segments);
        }

        $parent = $parentVisible ? $record->toRecordId : '*';

        return new RelationRecord(
            relationId: $parentVisible
                ? $record->relationId
                : RelationRecord::identity($record->relationKey, $record->fromRecordId, '*'),
            relationKey: $record->relationKey,
            schemaId: $record->schemaId,
            fromRecordId: $record->fromRecordId,
            toRecordId: $parent,
            kind: $record->kind,
            deletePolicy: $record->deletePolicy,
            status: $record->status,
            path: $path,
            depth: $record->depth,
            orderIndex: $record->orderIndex,
            createdBy: $record->createdBy,
            correlationId: $record->correlationId,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): RelationRecord
    {
        return new RelationRecord(
            relationId: (string) $row['relation_id'],
            relationKey: (string) $row['relation_key'],
            schemaId: (string) $row['schema_id'],
            fromRecordId: (string) $row['from_record_id'],
            toRecordId: (string) $row['to_record_id'],
            kind: RelationKind::from((string) $row['kind']),
            deletePolicy: RelationDeletePolicy::from((string) $row['delete_policy']),
            status: RelationStatus::from((string) $row['status']),
            path: $row['path'] === null ? null : (string) $row['path'],
            depth: (int) $row['depth'],
            orderIndex: (int) $row['order_index'],
            createdBy: $row['created_by'] === null ? null : (string) $row['created_by'],
            correlationId: $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
        );
    }

    /** @phpstan-impure */
    private function nextOrderIndex(string $relationKey, string $parentRecordId): int
    {
        $max = $this->connection->table(self::TABLE)
            ->where('relation_key', $relationKey)
            ->where('to_record_id', $parentRecordId)
            ->max('order_index');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    private function budget(?int $budget): int
    {
        if ($budget === null) {
            return self::DEFAULT_BUDGET;
        }

        if ($budget < 1) {
            throw new RelationRejected('invalid_budget', 'A traversal budget must be a positive integer.');
        }

        return min($budget, self::DEFAULT_BUDGET);
    }

    private function assertRecordId(string $recordId): void
    {
        if ($recordId === '' || strlen($recordId) > 39 || str_contains($recordId, RelationPath::SEPARATOR)) {
            throw new RelationRejected('invalid_record_id', 'A record id must be non-empty, at most 39 characters and contain no slash.');
        }
    }

    /** @phpstan-impure */
    private function assertSchema(): void
    {
        if (!$this->connection->getSchemaBuilder()->hasTable(self::TABLE)) {
            throw new RelationRejected('schema_missing', 'The record relations table is not migrated.');
        }
    }

    private function isUniqueViolation(Throwable $failure): bool
    {
        $message = strtolower($failure->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, '1062');
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
