<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/record-relation-schema.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Storage\Exceptions\RelationRejected;
use Larena\Storage\Runtime\DatabaseRecordRelations;
use Larena\Storage\Runtime\RelationPath;

/**
 * @param callable(): mixed $call
 */
function larena_storage_relation_denied(callable $call, string $expectedReason): void
{
    try {
        $call();
    } catch (RelationRejected $rejection) {
        larena_storage_role_assert(
            $rejection->reasonCode === $expectedReason,
            'expected ' . $expectedReason . ', got ' . $rejection->reasonCode,
        );

        return;
    }

    throw new RuntimeException('Boundary "' . $expectedReason . '" did not fail closed.');
}

$connection = larena_storage_relation_connection();
$relations = new DatabaseRecordRelations($connection);
$tree = larena_storage_tree_descriptor();

$relations->define($tree, 'site.pages', 'sales', 'company', 'actor:admin');
$relations->define($tree, 'site.pages', 'field', 'sales', 'actor:admin');

// A record cannot relate to itself.
larena_storage_relation_denied(
    static fn (): mixed => $relations->define($tree, 'site.pages', 'sales', 'sales', 'actor:admin'),
    'cycle_detected',
);

// A record cannot become its own ancestor.
larena_storage_relation_denied(
    static fn (): mixed => $relations->define($tree, 'site.pages', 'company', 'field', 'actor:admin'),
    'cycle_detected',
);

// A second tree parent is refused. The unique index is what refuses it, which is
// why the check cannot be lost to a race.
larena_storage_relation_denied(
    static fn (): mixed => $relations->define($tree, 'site.pages', 'field', 'company', 'actor:admin'),
    'second_tree_parent',
);

// The index really is the guarantee: a direct insert bypassing the class is
// refused by the database too.
$insertRefused = false;
try {
    $connection->table(DatabaseRecordRelations::TABLE)->insert([
        'relation_id' => 'site_tree_parent|field|company',
        'relation_key' => 'site_tree_parent',
        'schema_id' => 'site.pages',
        'from_record_id' => 'field',
        'to_record_id' => 'company',
        'kind' => 'tree_parent',
        'tree_child_key' => 'field',
        'path' => 'company/field',
        'depth' => 2,
        'order_index' => 0,
        'delete_policy' => 'cascade',
        'status' => 'active',
        'created_by' => 'actor:sneaky',
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
} catch (\Throwable) {
    $insertRefused = true;
}
larena_storage_role_assert($insertRefused, 'the unique index, not the code path, is the single-parent guarantee');

// But a reference edge to the same pair is allowed, because tree_child_key is NULL
// for references and repeated NULLs are legal in a unique index on both drivers.
$reference = larena_storage_reference_descriptor(key: 'related_page');
$relations->define($reference, 'site.pages', 'field', 'company', 'actor:admin');
larena_storage_role_assert(count($relations->resolve('related_page', 'field')) === 1);

// A cross-schema parent is refused.
$relations->define($tree, 'other.pages', 'other-root', 'other-parent', 'actor:admin');
larena_storage_relation_denied(
    static fn (): mixed => $relations->define($tree, 'site.pages', 'stray', 'other-root', 'actor:admin'),
    'cross_schema_parent',
);

// Moving into one's own subtree is refused, and so is becoming one's own parent.
// 'sales' has a tree edge and 'field' is below it, which is the real shape of this
// mistake: an editor dragging a branch into its own child.
larena_storage_relation_denied(
    static fn (): mixed => $relations->move('site_tree_parent', 'sales', 'field', 'actor:admin'),
    'cycle_detected',
);
larena_storage_relation_denied(
    static fn (): mixed => $relations->move('site_tree_parent', 'sales', 'sales', 'actor:admin'),
    'cycle_detected',
);

// Moving a record with no tree edge is refused rather than silently creating one.
larena_storage_relation_denied(
    static fn (): mixed => $relations->move('site_tree_parent', 'nowhere', 'company', 'actor:admin'),
    'unknown_relation',
);

// An invalid record id is refused before any write.
larena_storage_relation_denied(
    static fn (): mixed => $relations->define($tree, 'site.pages', '', 'company', 'actor:admin'),
    'invalid_record_id',
);
larena_storage_relation_denied(
    static fn (): mixed => $relations->define($tree, 'site.pages', 'has/slash', 'company', 'actor:admin'),
    'invalid_record_id',
);
larena_storage_relation_denied(
    static fn (): mixed => $relations->define($tree, 'site.pages', str_repeat('x', 40), 'company', 'actor:admin'),
    'invalid_record_id',
);

// The depth limit is enforced by the path itself.
$deep = RelationPath::root('r0');
for ($i = 1; $i < RelationPath::MAX_DEPTH; ++$i) {
    $deep = $deep->child('r' . $i);
}
larena_storage_role_assert($deep->depth() === RelationPath::MAX_DEPTH);
larena_storage_relation_denied(static fn (): mixed => $deep->child('one-too-many'), 'depth_exceeded');

// A budget must be a positive integer.
larena_storage_relation_denied(
    static fn (): mixed => $relations->children('site_tree_parent', 'company', 0),
    'invalid_budget',
);

// An unknown relation key has no declared policy, so nothing may be assumed.
larena_storage_relation_denied(
    static fn (): mixed => $relations->deleteRecord('no_such_relation', 'company', 'actor:admin'),
    'unknown_relation',
);

// Without the table nothing pretends to work.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$bare = new DatabaseRecordRelations($capsule->getConnection());
larena_storage_relation_denied(static fn (): mixed => $bare->resolve('site_tree_parent', 'company'), 'schema_missing');

echo "Record relation fail-closed boundaries passed.\n";
