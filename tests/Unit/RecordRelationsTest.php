<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/record-relation-schema.php';

use Larena\Storage\Enums\RelationKind;
use Larena\Storage\Enums\RelationStatus;
use Larena\Storage\Runtime\DatabaseRecordRelations;

$relations = new DatabaseRecordRelations(larena_storage_relation_connection());
$reference = larena_storage_reference_descriptor();

// A reference edge: defined, resolved, explained.
$edge = $relations->define($reference, 'docs.pages', 'page-1', 'space-1', 'actor:editor', null, 'correlation-1');

larena_storage_role_assert($edge->relationId === 'doc_space_ref|page-1|space-1');
larena_storage_role_assert($edge->kind === RelationKind::Reference);
larena_storage_role_assert($edge->status === RelationStatus::Active);
larena_storage_role_assert($edge->path === null, 'a reference carries no path');
larena_storage_role_assert($edge->depth === 0);

$resolved = $relations->resolve('doc_space_ref', 'page-1');
larena_storage_role_assert(count($resolved) === 1);
larena_storage_role_assert($resolved[0]->toRecordId === 'space-1');
larena_storage_role_assert($relations->resolve('doc_space_ref', 'page-9') === [], 'an unrelated record resolves to nothing');

// A record may hold several references under one key, in order.
$relations->define($reference, 'docs.pages', 'page-1', 'space-2', 'actor:editor');
$multi = $relations->resolve('doc_space_ref', 'page-1');
larena_storage_role_assert(count($multi) === 2);
larena_storage_role_assert($multi[0]->orderIndex <= $multi[1]->orderIndex, 'references keep their order');

$explained = $relations->explain('doc_space_ref', 'page-1');
larena_storage_role_assert($explained['has_tree_edge'] === false);
larena_storage_role_assert($explained['reference_count'] === 2);
larena_storage_role_assert($explained['traversal_budget'] === DatabaseRecordRelations::DEFAULT_BUDGET);

// A tree edge carries a path and a depth, and the root child's path is its own id.
$tree = larena_storage_tree_descriptor();
$root = $relations->define($tree, 'site.pages', 'node-root', 'node-root-parent', 'actor:editor');
larena_storage_role_assert($root->path === 'node-root-parent/node-root', 'the path is parent then child');
larena_storage_role_assert($root->depth === 2);

$child = $relations->define($tree, 'site.pages', 'node-child', 'node-root', 'actor:editor');
larena_storage_role_assert($child->path === 'node-root-parent/node-root/node-child');
larena_storage_role_assert($child->depth === 3);

$treeExplained = $relations->explain('site_tree_parent', 'node-child');
larena_storage_role_assert($treeExplained['has_tree_edge'] === true);
larena_storage_role_assert(
    $treeExplained['reference_count'] === 0,
    'a tree edge is not a reference: a record with one parent and no references reports zero',
);
larena_storage_role_assert($treeExplained['parent_record_id'] === 'node-root');
larena_storage_role_assert($treeExplained['depth'] === 3);

// A record with no edge for the key explains itself as having none rather than
// failing: absent and denied are different answers.
$none = $relations->explain('site_tree_parent', 'node-absent');
larena_storage_role_assert($none['has_tree_edge'] === false);
larena_storage_role_assert($none['path'] === null);

echo "Record relations passed.\n";
