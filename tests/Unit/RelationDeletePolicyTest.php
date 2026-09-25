<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/record-relation-schema.php';

use Larena\Storage\Enums\RelationDeletePolicy;
use Larena\Storage\Exceptions\RelationRejected;
use Larena\Storage\Runtime\DatabaseRecordRelations;

/**
 * Build company -> sales -> field for one policy.
 *
 * @return array{0: DatabaseRecordRelations, 1: \Illuminate\Database\Connection}
 */
function larena_storage_policy_tree(RelationDeletePolicy $policy): array
{
    $connection = larena_storage_relation_connection();
    $relations = new DatabaseRecordRelations($connection);
    $tree = larena_storage_tree_descriptor($policy);

    $relations->define($tree, 'site.pages', 'sales', 'company', 'actor:admin');
    $relations->define($tree, 'site.pages', 'field', 'sales', 'actor:admin');
    $relations->define($tree, 'site.pages', 'inside', 'field', 'actor:admin');

    return [$relations, $connection];
}

// restrict refuses while descendants exist, and names how many.
[$restrict, $restrictConnection] = larena_storage_policy_tree(RelationDeletePolicy::Restrict);
$refused = false;
try {
    $restrict->deleteRecord('site_tree_parent', 'sales', 'actor:admin');
} catch (RelationRejected $rejection) {
    $refused = true;
    larena_storage_role_assert($rejection->reasonCode === 'delete_restricted');
    larena_storage_role_assert(str_contains($rejection->getMessage(), '2'), 'the refusal says how many descendants block it');
}
larena_storage_role_assert($refused, 'restrict must refuse a delete with descendants');
larena_storage_role_assert(
    $restrictConnection->table(DatabaseRecordRelations::TABLE)->count() === 3,
    'a refused delete removes nothing',
);

// A leaf may be deleted under restrict, because nothing points below it.
$leaf = $restrict->deleteRecord('site_tree_parent', 'inside', 'actor:admin');
larena_storage_role_assert($leaf['policy'] === 'restrict');
larena_storage_role_assert($leaf['descendant_count'] === 0);
larena_storage_role_assert($restrictConnection->table(DatabaseRecordRelations::TABLE)->count() === 2);

// cascade removes the subtree and reports what it removed.
[$cascade, $cascadeConnection] = larena_storage_policy_tree(RelationDeletePolicy::Cascade);
$cascaded = $cascade->deleteRecord('site_tree_parent', 'sales', 'actor:admin');

larena_storage_role_assert($cascaded['policy'] === 'cascade');
larena_storage_role_assert($cascaded['descendant_count'] === 2);
larena_storage_role_assert(
    $cascaded['removed'] === ['field', 'inside'],
    'cascade names what it removed: ' . implode(', ', $cascaded['removed']),
);

$remaining = $cascadeConnection->table(DatabaseRecordRelations::TABLE)->pluck('from_record_id')->all();
larena_storage_role_assert($remaining === [], 'cascade removed the branch and the record itself');

// detach keeps the descendants reachable: the former child becomes a root.
[$detach, $detachConnection] = larena_storage_policy_tree(RelationDeletePolicy::Detach);
$detached = $detach->deleteRecord('site_tree_parent', 'sales', 'actor:admin');

larena_storage_role_assert($detached['policy'] === 'detach');
larena_storage_role_assert($detached['detached'] === ['field'], 'detach names the children it freed');

$rows = [];
foreach ($detachConnection->table(DatabaseRecordRelations::TABLE)->get() as $row) {
    $rows[(string) $row->from_record_id] = ['path' => (string) $row->path, 'status' => (string) $row->status];
}

larena_storage_role_assert(!isset($rows['sales']), 'the deleted record is gone');
larena_storage_role_assert(isset($rows['field']), 'its child is still there');
larena_storage_role_assert($rows['field']['path'] === 'field', 'the freed child is a root now');
larena_storage_role_assert($rows['field']['status'] === 'detached', 'and the row says it was detached');
larena_storage_role_assert(isset($rows['inside']), 'the grandchild is still reachable');

// A reference edge to the deleted record is removed under every policy, because
// pointing at nothing is not a state worth keeping.
$connection = larena_storage_relation_connection();
$relations = new DatabaseRecordRelations($connection);
$reference = larena_storage_reference_descriptor(RelationDeletePolicy::Cascade);
$relations->define($reference, 'docs.pages', 'page-1', 'space-1', 'actor:editor');
$relations->deleteRecord('doc_space_ref', 'space-1', 'actor:editor');
larena_storage_role_assert($relations->resolve('doc_space_ref', 'page-1') === [], 'a dangling reference is removed');

echo "Relation delete policies passed.\n";
