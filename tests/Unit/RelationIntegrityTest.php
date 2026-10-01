<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/record-relation-schema.php';

use Larena\Core\Contracts\OperationContext;
use Larena\Storage\Audit\RelationAuditEventCatalog;
use Larena\Storage\Contracts\RecordReadVisibility;
use Larena\Storage\Contracts\RelationDescriptor;
use Larena\Storage\Contracts\RelationTargets;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Enums\RelationDeletePolicy;
use Larena\Storage\Enums\RelationKind;
use Larena\Storage\Exceptions\RelationRejected;
use Larena\Storage\Runtime\DatabaseRecordRelations;
use Larena\Storage\Runtime\RelationOperationHandlers;

$targets = new class implements RelationTargets {
    /** @var array<string, array{schema: string, scope: ?string}> */
    public array $records = [];

    /** @var array<string, array<string, array<string, string>>> */
    public array $declared = [];

    public function schemaOf(string $recordId): ?string
    {
        return $this->records[$recordId]['schema'] ?? null;
    }

    public function scopeOf(string $recordId): ?string
    {
        return $this->records[$recordId]['scope'] ?? null;
    }

    public function playsRole(string $schemaId, string $roleCode): bool
    {
        return $schemaId === 'site.pages' && $roleCode === 'site_node';
    }

    public function declaredRelations(string $recordId): ?array
    {
        return $this->declared[$this->schemaOf($recordId) ?? ''] ?? null;
    }
};
foreach (['root', 'a', 'b', 'c', 'x', 'y', 'z'] as $id) {
    $targets->records[$id] = ['schema' => 'site.pages', 'scope' => 'site:main'];
}
$targets->records['other-site'] = ['schema' => 'site.pages', 'scope' => 'site:other'];
$targets->records['doc'] = ['schema' => 'docs.pages', 'scope' => 'site:main'];

$sink = new class implements StorageSecurityEventSink {
    /** @var list<StorageSecurityEvent> */
    public array $events = [];

    public function emit(StorageSecurityEvent $event): void
    {
        $this->events[] = $event;
    }
};
$connection = larena_storage_relation_connection();
$relations = new DatabaseRecordRelations($connection, $targets, $sink);
$tree = larena_storage_tree_descriptor(RelationDeletePolicy::Detach);
$refused = static function (callable $call, string $reason): void {
    try {
        $call();
    } catch (RelationRejected $rejection) {
        larena_storage_role_assert($rejection->reasonCode === $reason, $rejection->reasonCode . ' instead of ' . $reason);

        return;
    }
    larena_storage_role_assert(false, 'expected ' . $reason);
};

// Targets are resolved before anything is written.
$refused(static fn () => $relations->define($tree, 'site.pages', 'a', 'ghost', 'actor:e'), 'target_not_found');
$refused(static fn () => $relations->define($tree, 'site.pages', 'ghost', 'root', 'actor:e'), 'source_not_found');
$refused(static fn () => $relations->define($tree, 'docs.pages', 'a', 'root', 'actor:e'), 'source_schema_mismatch');
$refused(static fn () => $relations->define($tree, 'site.pages', 'a', 'doc', 'actor:e'), 'target_role_mismatch');
$bySchema = new RelationDescriptor('see_also', RelationKind::Reference, RelationDeletePolicy::Restrict, 'docs.pages');
$refused(static fn () => $relations->define($bySchema, 'site.pages', 'a', 'b', 'actor:e'), 'target_schema_mismatch');
$relations->define($bySchema, 'site.pages', 'a', 'doc', 'actor:e');

// A tree parent shares the schema and the scope, even when it is a root itself.
$anyRole = new RelationDescriptor('site_tree_parent', RelationKind::TreeParent, RelationDeletePolicy::Detach);
$refused(static fn () => $relations->define($anyRole, 'site.pages', 'a', 'doc', 'actor:e'), 'cross_schema_parent');
$refused(static fn () => $relations->define($tree, 'site.pages', 'a', 'other-site', 'actor:e'), 'cross_scope_parent');
larena_storage_role_assert($connection->table(DatabaseRecordRelations::TABLE)->count() === 1, 'only the valid reference was written');

// A schema version that declares its relations is followed exactly.
$targets->declared['site.pages'] = ['site_tree_parent' => ['relation_key' => 'site_tree_parent', 'kind' => 'tree_parent', 'delete_policy' => 'detach', 'target_role_code' => 'site_node']];
$refused(static fn () => $relations->define(larena_storage_tree_descriptor(RelationDeletePolicy::Cascade), 'site.pages', 'a', 'root', 'actor:e'), 'relation_descriptor_mismatch');
$refused(static fn () => $relations->define(new RelationDescriptor('menu', RelationKind::Reference, RelationDeletePolicy::Restrict), 'site.pages', 'a', 'b', 'actor:e'), 'relation_undeclared');

foreach (['a', 'b', 'c'] as $child) {
    $relations->define($tree, 'site.pages', $child, 'root', 'actor:e', null, 'corr-define');
}
$relations->define($tree, 'site.pages', 'x', 'a', 'actor:e');
$relations->define($tree, 'site.pages', 'y', 'x', 'actor:e');
$defined = array_values(array_filter($sink->events, static fn ($event): bool => $event->type === RelationAuditEventCatalog::DEFINED));
larena_storage_role_assert(count($defined) === 6 && $defined[1]->correlationId === 'corr-define', 'every edge is audited once');

// A move keeps sibling order at both ends and is one audit event naming the moved set.
$order = static fn (string $parent): array => array_map(
    static fn (array $record): string => $record['from_record_id'],
    $relations->children('site_tree_parent', $parent)->toArray()['records'],
);
$before = count($sink->events);
$relations->move('site_tree_parent', 'x', 'root', 'actor:e', null, 'corr-move');
larena_storage_role_assert($order('root') === ['a', 'b', 'c', 'x'], 'the moved record joins after its new siblings: ' . implode(',', $order('root')));
$relations->move('site_tree_parent', 'b', 'c', 'actor:e');
larena_storage_role_assert($order('root') === ['a', 'c', 'x'], 'the remaining siblings keep their order');
$moves = array_values(array_filter(array_slice($sink->events, $before), static fn ($event): bool => $event->type === RelationAuditEventCatalog::MOVED));
larena_storage_role_assert(count($moves) === 2, 'one event per move');
larena_storage_role_assert($moves[0]->payload['moved_record_ids'] === ['x', 'y'] && $moves[0]->correlationId === 'corr-move', 'the event names the moved subtree');

// The tree operations apply the caller's visibility and count what they hide.
$hideC = new class implements RecordReadVisibility {
    public function filterFor(string $actor, string $relationKey): ?Closure
    {
        return $actor === 'actor:reader' ? static fn (string $recordId): bool => $recordId !== 'c' : null;
    }
};
$handlers = new RelationOperationHandlers($relations, $hideC);
$children = $handlers->handle(RelationOperationHandlers::descriptors()['storage.tree.children'], new OperationContext(
    actorId: 'actor:reader', correlationId: 'corr-read', metadata: ['relation_key' => 'site_tree_parent', 'parent_record_id' => 'root'],
));
larena_storage_role_assert($children['filtered_count'] === 1 && !str_contains((string) json_encode($children), '"c"'), 'a hidden child is counted, not shown');

// Detaching a parent rewrites the whole subtree, so no descendant keeps a path through it.
$relations->define($tree, 'site.pages', 'z', 'y', 'actor:e');
$relations->deleteRecord('site_tree_parent', 'x', 'actor:e');
$zPath = $connection->table(DatabaseRecordRelations::TABLE)->where('from_record_id', 'z')->value('path');
larena_storage_role_assert(!str_contains((string) $zPath, 'x'), 'a grandchild no longer runs through the removed record: ' . $zPath);
$ancestors = $relations->ancestors('site_tree_parent', 'z')->toArray()['records'];
larena_storage_role_assert(array_column($ancestors, 'from_record_id') === ['y'], 'the detached record is the root of its subtree and still an ancestor');

// Records removed for good: restrict refuses, detach frees the children with their subtree.
$refused(static fn () => $relations->releaseIncomingEdges(['doc'], 'actor:e'), 'delete_restricted');
$released = $relations->releaseIncomingEdges(['root'], 'actor:e', 'corr-purge');
larena_storage_role_assert($released['detached'] === ['a', 'c'], 'the root\'s children are detached: ' . implode(',', $released['detached']));
larena_storage_role_assert($relations->ancestors('site_tree_parent', 'b')->toArray()['records'] !== [], 'b keeps its parent c');
larena_storage_role_assert(!str_contains((string) $connection->table(DatabaseRecordRelations::TABLE)->where('from_record_id', 'b')->value('path'), 'root'), 'no path runs through the removed root');

// The delete operation exists and applies the declared policy.
$deleted = $handlers->handle(RelationOperationHandlers::descriptors()['storage.relation.delete_record'], new OperationContext(
    actorId: 'actor:e', correlationId: 'corr-delete', metadata: ['relation_key' => 'site_tree_parent', 'record_id' => 'c'],
));
larena_storage_role_assert($deleted['policy'] === 'detach' && $deleted['detached'] === ['b']);

echo "Relation integrity passed.\n";
