<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/structure-role-schema.php';

use Larena\Storage\Contracts\StructureRole;
use Larena\Storage\Enums\RoleBindingStatus;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Exceptions\StructureRoleRejected;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;

$registry = new DatabaseStructureRoleRegistry(larena_storage_structure_role_connection());

$role = new StructureRole(
    roleCode: 'site_node',
    roleVersion: 1,
    title: 'Site node',
    requiredFields: [
        ['key' => 'slug', 'type' => 'string'],
        ['key' => 'title', 'type' => 'string'],
        ['key' => 'order_index', 'type' => 'integer'],
        ['key' => 'target', 'type' => 'string'],
    ],
    optionalFields: [['key' => 'description', 'type' => 'string']],
    requiredRelations: [
        ['relation_key' => 'site_tree_parent', 'kind' => 'tree_parent', 'target_role_code' => 'site_node'],
    ],
    lifecycle: RoleLifecycle::Publishable,
    ownerPackage: 'larena/storage',
);

larena_storage_role_assert($role->ref() === 'site_node@v1', 'a versioned role is one addressable string');

$registry->register($role, 'actor:installer', 'correlation-1');

$read = $registry->read('site_node@v1');
larena_storage_role_assert($read !== null, 'a registered role reads back');
larena_storage_role_assert($read->title === 'Site node');
larena_storage_role_assert($read->isPublishable(), 'the lifecycle survives the round trip');
larena_storage_role_assert($read->requiredFieldKeys() === ['slug', 'title', 'order_index', 'target']);
larena_storage_role_assert(count($read->requiredRelations) === 1);
larena_storage_role_assert($read->requiredRelations[0]['relation_key'] === 'site_tree_parent');
larena_storage_role_assert($registry->read('site_node@v2') === null, 'another version is another role');

// A role row is never edited: the same code and version twice is refused.
try {
    $registry->register($role, 'actor:installer');
    throw new RuntimeException('a duplicate role version must be rejected');
} catch (StructureRoleRejected $rejection) {
    larena_storage_role_assert($rejection->reasonCode === 'duplicate_role_version');
}

// A second version is a different role and coexists with the first.
$v2 = new StructureRole(
    roleCode: 'site_node',
    roleVersion: 2,
    title: 'Site node v2',
    requiredFields: [['key' => 'slug', 'type' => 'string']],
    optionalFields: [],
    requiredRelations: [],
    lifecycle: RoleLifecycle::Publishable,
    ownerPackage: 'larena/storage',
);
$registry->register($v2, 'actor:installer');
larena_storage_role_assert($registry->read('site_node@v2') !== null, 'a new version installs beside the old one');
larena_storage_role_assert($registry->read('site_node@v1')->title === 'Site node', 'the old version is untouched');

// Listing is ordered and filterable by owner.
$listed = array_map(static fn (StructureRole $r): string => $r->ref(), $registry->list());
larena_storage_role_assert($listed === ['site_node@v1', 'site_node@v2'], 'roles list in code then version order');
larena_storage_role_assert($registry->list('larena/docara') === [], 'nothing belongs to a package that registered nothing');

// Binding a conforming structure.
$binding = $registry->bindStructure(
    'site_node@v1',
    'site.pages',
    'site:main',
    larena_storage_site_node_fields(),
    'actor:admin',
    ['site_tree_parent'],
    'publishable',
);

larena_storage_role_assert($binding->bindingId === 'site_node@v1|site.pages|site:main');
larena_storage_role_assert($binding->status === RoleBindingStatus::Active);
larena_storage_role_assert($binding->conformanceCheckedAt !== null, 'a binding records when conformance was checked');

$bindings = $registry->listStructures('site:main');
larena_storage_role_assert(count($bindings) === 1);
larena_storage_role_assert($bindings[0]->schemaId === 'site.pages');
larena_storage_role_assert($registry->listStructures('site:other') === [], 'a binding belongs to one scope');
larena_storage_role_assert(count($registry->listStructures('site:main', 'site_node@v1')) === 1);
larena_storage_role_assert($registry->listStructures('site:main', 'site_node@v2') === []);

// The same schema may play the same role in another scope.
$registry->bindStructure(
    'site_node@v1',
    'site.pages',
    'site:second',
    larena_storage_site_node_fields(),
    'actor:admin',
    ['site_tree_parent'],
    'publishable',
);
larena_storage_role_assert(count($registry->listStructures('site:second')) === 1, 'scope is part of the binding identity');

$explained = $registry->explain('site_node@v1');
larena_storage_role_assert($explained['exists'] === true);
larena_storage_role_assert($explained['active_binding_count'] === 2);
larena_storage_role_assert($registry->explain('site_node@v9')['exists'] === false);

// The five starter roles install, and installing them again changes nothing.
$fresh = new DatabaseStructureRoleRegistry(larena_storage_structure_role_connection());
$starter = new StarterStructureRoles($fresh);

$first = $starter->install('actor:installer');
larena_storage_role_assert(count($first['installed']) === 5, 'five starter roles install');
larena_storage_role_assert($first['already_present'] === []);

$second = $starter->install('actor:installer');
larena_storage_role_assert($second['installed'] === [], 'a re-run installs nothing');
larena_storage_role_assert(count($second['already_present']) === 5, 'a re-run reports what was already there');

$codes = array_map(static fn (StructureRole $r): string => $r->roleCode, $fresh->list());
larena_storage_role_assert(
    $codes === ['doc_page', 'doc_space', 'org_chart', 'redirect', 'site_node'],
    'the starter set is site_node, redirect, doc_space, doc_page and org_chart: ' . implode(', ', $codes),
);

echo "Structure role registry passed.\n";
