<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/structure-role-schema.php';

use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;

$connection = larena_storage_structure_role_connection();
$registry = new DatabaseStructureRoleRegistry($connection);
$starter = new StarterStructureRoles($registry);

$starter->install('actor:installer', 'correlation-1');

// The exact rows after the first install.
$before = $connection->table(DatabaseStructureRoleRegistry::ROLES_TABLE)
    ->orderBy('role_ref')
    ->get()
    ->map(static fn ($row): array => (array) $row)
    ->all();

larena_storage_role_assert(count($before) === 5);

// Three more installs, by different actors, with different correlation ids.
$starter->install('actor:second', 'correlation-2');
$starter->install('actor:third');
$report = $starter->install('actor:fourth', 'correlation-4');

$after = $connection->table(DatabaseStructureRoleRegistry::ROLES_TABLE)
    ->orderBy('role_ref')
    ->get()
    ->map(static fn ($row): array => (array) $row)
    ->all();

// Idempotent means the rows are byte-identical, not merely the same count: an
// install that rewrote created_by or updated_at would quietly rewrite history.
larena_storage_role_assert($before == $after, 'a re-run must change no row at all');
larena_storage_role_assert($report['installed'] === [], 'a re-run installs nothing');
larena_storage_role_assert(count($report['already_present']) === 5);
larena_storage_role_assert($report['role_count'] === 5);

// The declared starter set is exactly what the freeze names, with the lifecycles
// it names.
$expected = [
    'doc_page@v1' => RoleLifecycle::Publishable,
    'doc_space@v1' => RoleLifecycle::Publishable,
    'org_chart@v1' => RoleLifecycle::Plain,
    'redirect@v1' => RoleLifecycle::Plain,
    'site_node@v1' => RoleLifecycle::Publishable,
];

$actual = [];
foreach ($registry->list() as $role) {
    $actual[$role->ref()] = $role->lifecycle;
}
ksort($actual);

larena_storage_role_assert($actual == $expected, 'the starter roles and their lifecycles are the frozen set');

// Every starter role is owned by storage and active.
foreach ($registry->list() as $role) {
    larena_storage_role_assert($role->ownerPackage === 'larena/storage', $role->ref() . ' is owned by storage');
    larena_storage_role_assert($role->status->value === 'active');
}

// A redirect has no publication lifecycle, and that is deliberate: a draft
// redirect is a contradiction.
larena_storage_role_assert(!$registry->read('redirect@v1')->isPublishable());

// The site_node role requires exactly what the freeze says a site tree needs.
$siteNode = $registry->read('site_node@v1');
larena_storage_role_assert($siteNode->requiredFieldKeys() === ['slug', 'title', 'order_index', 'target']);
larena_storage_role_assert(count($siteNode->requiredRelations) === 1);
larena_storage_role_assert($siteNode->requiredRelations[0]['kind'] === 'tree_parent');

echo "Starter structure role idempotency passed.\n";
