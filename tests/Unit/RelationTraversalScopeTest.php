<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/record-relation-schema.php';

use Larena\Storage\Contracts\RelationRecord;
use Larena\Storage\Runtime\DatabaseRecordRelations;

$relations = new DatabaseRecordRelations(larena_storage_relation_connection());
$tree = larena_storage_tree_descriptor();

// company -> sales -> field -> inside, plus two public siblings.
$relations->define($tree, 'site.pages', 'sales', 'company', 'actor:admin');
$relations->define($tree, 'site.pages', 'field', 'sales', 'actor:admin');
$relations->define($tree, 'site.pages', 'inside', 'field', 'actor:admin');
$relations->define($tree, 'site.pages', 'public-a', 'company', 'actor:admin');
$relations->define($tree, 'site.pages', 'public-b', 'company', 'actor:admin');

/** A filter standing in for the caller's query scope. */
$hide = static fn (array $hidden): callable => static fn (RelationRecord $record): bool
    => !in_array($record->fromRecordId, $hidden, true);

// A hidden ancestor is counted, never named. That is the whole contract: a caller
// must be able to tell "there is something above you that you cannot see" from
// "there is nothing above you", without learning what it is.
$ancestors = $relations->ancestors('site_tree_parent', 'inside', null, $hide(['sales']));

larena_storage_role_assert($ancestors->count() === 1, 'one ancestor survives the filter');
larena_storage_role_assert($ancestors->records[0]->fromRecordId === 'field');
larena_storage_role_assert($ancestors->filteredCount === 1, 'the hidden ancestor is counted');

$rendered = json_encode($ancestors->toArray());
larena_storage_role_assert(is_string($rendered));
larena_storage_role_assert(!str_contains($rendered, 'sales'), 'a filtered ancestor is never named in the payload');
larena_storage_role_assert(str_contains($rendered, '"filtered_count":1'), 'but the count is there');

// The redaction reaches every identifier, not just the path: the parent link and
// the relation id both name the hidden record, and both are masked. The depth and
// the order survive, because they are structural rather than identifying.
larena_storage_role_assert($ancestors->records[0]->toRecordId === '*', 'the hidden parent link is masked');
larena_storage_role_assert($ancestors->records[0]->path === '*/*/field', 'hidden path segments are masked');
larena_storage_role_assert($ancestors->records[0]->depth === 3, 'the depth is kept');
larena_storage_role_assert(str_ends_with($ancestors->records[0]->relationId, '|*'), 'the relation id is masked too');

// Unfiltered, the same call returns both with nothing masked.
$open = $relations->ancestors('site_tree_parent', 'inside');
larena_storage_role_assert($open->count() === 2);
larena_storage_role_assert($open->filteredCount === 0);
larena_storage_role_assert($open->records[1]->path === 'company/sales/field', 'an unfiltered read is complete');
larena_storage_role_assert($open->records[1]->toRecordId === 'sales');

// A filter that hides nothing still names everything it returns: redaction is
// driven by what was actually filtered, not by the mere presence of a filter.
$permissive = $relations->ancestors('site_tree_parent', 'inside', null, static fn (): bool => true);
larena_storage_role_assert($permissive->count() === 2);
larena_storage_role_assert($permissive->records[1]->toRecordId === 'sales', 'a visible parent keeps its name');
larena_storage_role_assert(
    $permissive->records[1]->path === '*/sales/field',
    'only ids outside the readable set are masked, got ' . (string) $permissive->records[1]->path,
);

// Children are filtered the same way, and the count survives the page shape.
$children = $relations->children('site_tree_parent', 'company', null, $hide(['public-a', 'public-b']));
larena_storage_role_assert($children->count() === 1);
larena_storage_role_assert($children->records[0]->fromRecordId === 'sales');
larena_storage_role_assert($children->filteredCount === 2);

$childPayload = json_encode($children->toArray());
larena_storage_role_assert(is_string($childPayload));
larena_storage_role_assert(!str_contains($childPayload, 'public-a'));
larena_storage_role_assert(!str_contains($childPayload, 'public-b'));

// A filter that hides everything returns an empty page with the full count, not an
// error: "you may see none of these" is a valid answer.
$everything = $relations->children('site_tree_parent', 'company', null, static fn (): bool => false);
larena_storage_role_assert($everything->count() === 0);
larena_storage_role_assert($everything->filteredCount === 3);
larena_storage_role_assert(!$everything->truncated);

// Truncation and filtering are independent signals: a page can be both.
$both = $relations->children('site_tree_parent', 'company', 2, $hide(['sales']));
larena_storage_role_assert($both->truncated, 'the budget stopped the walk');
larena_storage_role_assert($both->filteredCount === 1, 'and one of the rows read was filtered');
larena_storage_role_assert($both->count() === 1, 'so one row survives');

echo "Relation traversal scope filtering passed.\n";
