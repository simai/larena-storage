<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/record-relation-schema.php';

use Larena\Storage\Runtime\DatabaseRecordRelations;
use Larena\Storage\Runtime\RelationPath;

$connection = larena_storage_relation_connection();
$relations = new DatabaseRecordRelations($connection);
$tree = larena_storage_tree_descriptor();

// company -> sales -> field, plus marketing under company.
$relations->define($tree, 'site.pages', 'sales', 'company', 'actor:admin');
$relations->define($tree, 'site.pages', 'marketing', 'company', 'actor:admin');
$relations->define($tree, 'site.pages', 'field', 'sales', 'actor:admin');
$relations->define($tree, 'site.pages', 'inside', 'field', 'actor:admin');

// Children read in sibling order, not in insertion order by id.
$children = $relations->children('site_tree_parent', 'company');
larena_storage_role_assert($children->count() === 2);
larena_storage_role_assert(!$children->truncated);
larena_storage_role_assert(
    array_map(static fn ($r): string => $r->fromRecordId, $children->records) === ['sales', 'marketing'],
    'children read in the order they were added',
);

// Ancestors come from the path, nearest last, with no recursive query.
$ancestors = $relations->ancestors('site_tree_parent', 'inside');
larena_storage_role_assert(
    array_map(static fn ($r): string => $r->fromRecordId, $ancestors->records) === ['sales', 'field'],
    'ancestors read nearest last: ' . implode(', ', array_map(static fn ($r): string => $r->fromRecordId, $ancestors->records)),
);

// A root child has no ancestors and says so with an empty page, not an error.
$rootAncestors = $relations->ancestors('site_tree_parent', 'sales');
larena_storage_role_assert($rootAncestors->count() === 0);
larena_storage_role_assert(!$rootAncestors->truncated);

// Moving a subtree rewrites the path and depth of every descendant.
$before = $connection->table(DatabaseRecordRelations::TABLE)
    ->where('from_record_id', 'inside')->value('path');
larena_storage_role_assert($before === 'company/sales/field/inside');

$moved = $relations->move('site_tree_parent', 'field', 'marketing', 'actor:admin');

$paths = [];
foreach ($connection->table(DatabaseRecordRelations::TABLE)->orderBy('from_record_id')->get() as $row) {
    $paths[(string) $row->from_record_id] = (string) $row->path;
}

larena_storage_role_assert($paths['field'] === 'company/marketing/field', 'the moved record takes its new path');
larena_storage_role_assert($paths['inside'] === 'company/marketing/field/inside', 'a descendant follows its ancestor');
larena_storage_role_assert($paths['sales'] === 'company/sales', 'an unrelated branch is untouched');
larena_storage_role_assert($paths['marketing'] === 'company/marketing');

// Depth is rewritten with the path, because a stale depth is worse than none.
$depths = [];
foreach ($connection->table(DatabaseRecordRelations::TABLE)->get() as $row) {
    $depths[(string) $row->from_record_id] = (int) $row->depth;
}
larena_storage_role_assert($depths['field'] === 3);
larena_storage_role_assert($depths['inside'] === 4);

// The identity encodes the parent, so the move rewrote it: a primary key that
// named the old parent would be a row describing itself incorrectly.
$movedId = $connection->table(DatabaseRecordRelations::TABLE)
    ->where('from_record_id', 'field')->value('relation_id');
larena_storage_role_assert(
    $movedId === 'site_tree_parent|field|marketing',
    'the relation identity follows the move, got ' . (string) $movedId,
);

// The move returns the moved set, the record itself first.
larena_storage_role_assert($moved->count() === 2, 'the moved set is the record and its descendant');
larena_storage_role_assert($moved->records[0]->fromRecordId === 'field');
larena_storage_role_assert($moved->records[1]->fromRecordId === 'inside');

// Sibling order is preserved: field joins marketing's children after any existing
// ones rather than displacing them.
$marketingChildren = $relations->children('site_tree_parent', 'marketing');
larena_storage_role_assert($marketingChildren->count() === 1);
larena_storage_role_assert($marketingChildren->records[0]->fromRecordId === 'field');

// Moving to the root makes the record its own path.
$relations->move('site_tree_parent', 'field', null, 'actor:admin');
$rootedPaths = [];
foreach ($connection->table(DatabaseRecordRelations::TABLE)->get() as $row) {
    $rootedPaths[(string) $row->from_record_id] = (string) $row->path;
}
larena_storage_role_assert($rootedPaths['field'] === 'field', 'a rooted record is its own path');
larena_storage_role_assert($rootedPaths['inside'] === 'field/inside', 'its subtree follows');

// The traversal budget reports truncation instead of a silently short answer.
for ($i = 0; $i < 5; ++$i) {
    $relations->define($tree, 'site.pages', 'leaf-' . $i, 'company', 'actor:admin');
}

$budgeted = $relations->children('site_tree_parent', 'company', 3);
larena_storage_role_assert($budgeted->count() === 3, 'the budget limits the slice');
larena_storage_role_assert($budgeted->truncated, 'and the page says it was truncated');
larena_storage_role_assert($budgeted->budget === 3);

$full = $relations->children('site_tree_parent', 'company');
larena_storage_role_assert(!$full->truncated, 'a page inside the budget is not truncated');

// The path value object holds the depth limit the freeze names, and it is the limit
// the tree actually enforces: build exactly that many levels and the next one is
// refused. Asserting the behaviour rather than the constant keeps the test
// meaningful if the constant is ever read from configuration.
$deep = RelationPath::root('d0');
for ($i = 1; $i < RelationPath::MAX_DEPTH; ++$i) {
    $deep = $deep->child('d' . $i);
}
larena_storage_role_assert($deep->depth() === RelationPath::MAX_DEPTH, 'the limit is reachable');
$refusedBeyondLimit = false;
try {
    $deep->child('one-too-many');
} catch (\Larena\Storage\Exceptions\RelationRejected $rejection) {
    $refusedBeyondLimit = $rejection->reasonCode === 'depth_exceeded';
}
larena_storage_role_assert($refusedBeyondLimit, 'and one level beyond it is refused');

echo "Record relation trees passed.\n";
