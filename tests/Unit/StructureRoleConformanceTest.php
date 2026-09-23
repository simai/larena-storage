<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/structure-role-schema.php';

use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;

$registry = new DatabaseStructureRoleRegistry(larena_storage_structure_role_connection());
(new StarterStructureRoles($registry))->install('actor:installer');

// A conforming structure conforms, and nothing is reported.
$ok = $registry->validateStructure(
    'site_node@v1',
    'site.pages',
    larena_storage_site_node_fields(),
    ['site_tree_parent'],
    'publishable',
);
larena_storage_role_assert($ok->conforms, 'a conforming structure must conform');
larena_storage_role_assert($ok->missingFields === [] && $ok->typeMismatches === []);
larena_storage_role_assert($ok->missingRelations === [] && $ok->lifecycleMismatch === null);

// A role is a floor, not a ceiling: extra fields are fine.
$extra = $registry->validateStructure(
    'site_node@v1',
    'site.pages',
    [...larena_storage_site_node_fields(), ['key' => 'hero_image', 'type' => 'file']],
    ['site_tree_parent'],
    'publishable',
);
larena_storage_role_assert($extra->conforms, 'extra fields must not break conformance');

// Every missing required field is named.
$missing = $registry->validateStructure(
    'site_node@v1',
    'site.pages',
    [['key' => 'slug', 'type' => 'string']],
    ['site_tree_parent'],
    'publishable',
);
larena_storage_role_assert(!$missing->conforms);
larena_storage_role_assert(
    $missing->missingFields === ['title', 'order_index', 'target'],
    'every missing field is named: ' . implode(', ', $missing->missingFields),
);

// A wrong type is named with both types, so a reader does not have to look them up.
$mismatch = $registry->validateStructure(
    'site_node@v1',
    'site.pages',
    [
        ['key' => 'slug', 'type' => 'string'],
        ['key' => 'title', 'type' => 'string'],
        ['key' => 'order_index', 'type' => 'string'],
        ['key' => 'target', 'type' => 'string'],
    ],
    ['site_tree_parent'],
    'publishable',
);
larena_storage_role_assert(!$mismatch->conforms);
larena_storage_role_assert($mismatch->missingFields === []);
larena_storage_role_assert(count($mismatch->typeMismatches) === 1);
larena_storage_role_assert($mismatch->typeMismatches[0] === [
    'key' => 'order_index',
    'expected' => 'integer',
    'actual' => 'string',
]);

// A missing required relation is named.
$noRelation = $registry->validateStructure(
    'site_node@v1',
    'site.pages',
    larena_storage_site_node_fields(),
    [],
    'publishable',
);
larena_storage_role_assert(!$noRelation->conforms);
larena_storage_role_assert($noRelation->missingRelations === ['site_tree_parent']);

// A publishable role refuses a structure that is not publishable, and says so.
$wrongLifecycle = $registry->validateStructure(
    'site_node@v1',
    'site.pages',
    larena_storage_site_node_fields(),
    ['site_tree_parent'],
    'plain',
);
larena_storage_role_assert(!$wrongLifecycle->conforms);
larena_storage_role_assert($wrongLifecycle->lifecycleMismatch !== null);
larena_storage_role_assert(str_contains((string) $wrongLifecycle->lifecycleMismatch, 'publishable'));

$noLifecycle = $registry->validateStructure(
    'site_node@v1',
    'site.pages',
    larena_storage_site_node_fields(),
    ['site_tree_parent'],
    null,
);
larena_storage_role_assert(!$noLifecycle->conforms, 'an undeclared lifecycle is not publishable');
larena_storage_role_assert(str_contains((string) $noLifecycle->lifecycleMismatch, 'nothing'));

// A plain role does not care about the structure's lifecycle.
$plain = $registry->validateStructure(
    'redirect@v1',
    'site.redirects',
    [
        ['key' => 'from_path', 'type' => 'string'],
        ['key' => 'to_target', 'type' => 'string'],
        ['key' => 'status_code', 'type' => 'integer'],
    ],
    [],
    null,
);
larena_storage_role_assert($plain->conforms, 'a plain role imposes no lifecycle');

// doc_page needs both of its relations, and each missing one is named.
$docPage = $registry->validateStructure(
    'doc_page@v1',
    'docs.pages',
    [
        ['key' => 'slug', 'type' => 'string'],
        ['key' => 'title', 'type' => 'string'],
        ['key' => 'body', 'type' => 'string'],
        ['key' => 'order_index', 'type' => 'integer'],
    ],
    ['doc_tree_parent'],
    'publishable',
);
larena_storage_role_assert($docPage->missingRelations === ['doc_space_ref']);

// org_chart is an overlay: it needs the core plane node identifier and no relation
// of its own, because the hierarchy stays in core.
$orgChart = $registry->validateStructure(
    'org_chart@v1',
    'org.chart',
    [
        ['key' => 'core_plane_node_id', 'type' => 'string'],
        ['key' => 'title', 'type' => 'string'],
    ],
    [],
    null,
);
larena_storage_role_assert($orgChart->conforms, 'the org_chart overlay conforms without duplicating the hierarchy');

// The report serializes with every finding present.
$array = $missing->toArray();
larena_storage_role_assert($array['role_ref'] === 'site_node@v1');
larena_storage_role_assert($array['conforms'] === false);
larena_storage_role_assert($array['missing_fields'] === ['title', 'order_index', 'target']);

echo "Structure role conformance passed.\n";
