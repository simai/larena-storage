<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/structure-role-schema.php';

use Larena\Storage\Audit\StructureRoleAuditEventCatalog;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Contracts\StructureRole;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Exceptions\StructureRoleRejected;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\Runtime\StructureRoleDependencies;

$sink = new class implements StorageSecurityEventSink {
    /** @var list<StorageSecurityEvent> */
    public array $events = [];

    public function emit(StorageSecurityEvent $event): void
    {
        $this->events[] = $event;
    }
};
$registry = new DatabaseStructureRoleRegistry(larena_storage_structure_role_connection(), null, $sink);
(new StarterStructureRoles($registry))->install('actor:installer', 'corr-install');
$rejected = static function (callable $call, string $reason): StructureRoleRejected {
    try {
        $call();
    } catch (StructureRoleRejected $rejection) {
        larena_storage_role_assert($rejection->reasonCode === $reason, $rejection->reasonCode . ' instead of ' . $reason);

        return $rejection;
    }
    larena_storage_role_assert(false, 'expected ' . $reason);
    throw new LogicException('unreachable');
};

// Every starter role ships a fixture that satisfies it, doc_space included, and a
// fixture missing a required field is reported by name.
$fixtures = StarterStructureRoles::fixtures();
larena_storage_role_assert(array_keys($fixtures) === ['site_node@v1', 'redirect@v1', 'doc_space@v1', 'doc_page@v1', 'org_chart@v1']);
foreach ($fixtures as $roleRef => $fixture) {
    $report = $registry->validateStructure($roleRef, 'fixture.' . strtok($roleRef, '@'), $fixture['fields'], $fixture['relation_keys'], $fixture['lifecycle']);
    larena_storage_role_assert($report->conforms, $roleRef . ' fixture conforms');
    $broken = $registry->validateStructure($roleRef, 'fixture.broken', array_slice($fixture['fields'], 1), $fixture['relation_keys'], $fixture['lifecycle']);
    larena_storage_role_assert(!$broken->conforms && $broken->missingFields === [$fixture['fields'][0]['key']], $roleRef . ' names the missing field');
}

// A refused bind carries the full diagnostic, not only a message.
$refusal = $rejected(static fn () => $registry->bindStructure('site_node@v1', 'site.bare', 'site:main', [['key' => 'title', 'type' => 'string']], 'actor:admin'), 'role_conformance_failed');
larena_storage_role_assert($refusal->diagnostic['missing_fields'] === ['slug', 'order_index', 'target'], 'the missing fields are listed');
larena_storage_role_assert($refusal->diagnostic['missing_relations'] === ['site_tree_parent'], 'the missing relation is listed');
larena_storage_role_assert(is_string($refusal->diagnostic['lifecycle_mismatch']), 'the lifecycle mismatch is stated');

// A breaking change is a new role version, and bindings move to it by a plan.
$site = $fixtures['site_node@v1'];
$registry->bindStructure('site_node@v1', 'site.pages', 'site:main', $site['fields'], 'actor:admin', $site['relation_keys'], 'publishable');
$registry->bindStructure('site_node@v1', 'site.legacy', 'site:main', $site['fields'], 'actor:admin', $site['relation_keys'], 'publishable');
$v1 = $registry->read('site_node@v1');
$registry->register(new StructureRole(
    roleCode: 'site_node',
    roleVersion: 2,
    title: 'Site node',
    requiredFields: [...$v1->requiredFields, ['key' => 'menu_label', 'type' => 'string']],
    optionalFields: $v1->optionalFields,
    requiredRelations: $v1->requiredRelations,
    lifecycle: RoleLifecycle::Publishable,
    ownerPackage: 'larena/storage',
), 'actor:admin');
$plan = $registry->planRoleMigration('site_node@v1', 'site_node@v2');
larena_storage_role_assert($plan['breaking_changes'] === ['required_field_added:menu_label'], 'the plan names the breaking change');
larena_storage_role_assert(array_column($plan['bindings'], 'schema_id') === ['site.legacy', 'site.pages'], 'the plan lists every binding still on v1');
$rejected(static fn () => $registry->planRoleMigration('site_node@v2', 'site_node@v1'), 'role_migration_invalid');

$blocked = $rejected(static fn () => $registry->migrateBinding('site_node@v1', 'site_node@v2', 'site.legacy', 'site:main', $site['fields'], 'actor:admin', $site['relation_keys'], 'publishable'), 'role_migration_blocked');
larena_storage_role_assert($blocked->diagnostic['missing_fields'] === ['menu_label']);
larena_storage_role_assert(count($registry->listStructures('site:main', 'site_node@v1')) === 2, 'a blocked structure stays on v1');

$withLabel = [...$site['fields'], ['key' => 'menu_label', 'type' => 'string']];
$registry->migrateBinding('site_node@v1', 'site_node@v2', 'site.pages', 'site:main', $withLabel, 'actor:admin', $site['relation_keys'], 'publishable', 'corr-migrate');
larena_storage_role_assert(array_map(static fn ($b): string => $b->schemaId, $registry->listStructures('site:main', 'site_node@v1')) === ['site.legacy']);
larena_storage_role_assert(array_map(static fn ($b): string => $b->schemaId, $registry->listStructures('site:main', 'site_node@v2')) === ['site.pages']);

// Each change is audited.
$types = array_map(static fn ($event): string => $event->type, $sink->events);
larena_storage_role_assert(count(array_keys($types, StructureRoleAuditEventCatalog::REGISTERED, true)) === 6, 'five starter roles and v2 are registered');
larena_storage_role_assert(in_array(StructureRoleAuditEventCatalog::MIGRATED, $types, true), 'the migration is audited');
$migrated = array_values(array_filter($sink->events, static fn ($event): bool => $event->type === StructureRoleAuditEventCatalog::MIGRATED))[0];
larena_storage_role_assert($migrated->correlationId === 'corr-migrate' && $migrated->payload['schema_id'] === 'site.pages');

// A consumer reads structures only for a role it declared.
$dependencies = new StructureRoleDependencies($registry);
$rejected(static fn () => $dependencies->structuresFor('larena/site', 'site_node@v2', 'site:main'), 'role_dependency_undeclared');
$dependencies->declare('larena/site', 'site_node@v2');
$dependencies->declare('larena/docs', 'doc_page@v1');
larena_storage_role_assert(array_map(static fn ($b): string => $b->schemaId, $dependencies->structuresFor('larena/site', 'site_node@v2', 'site:main')) === ['site.pages']);
larena_storage_role_assert($dependencies->unsatisfied('site:main') === [['consumer' => 'larena/docs', 'role_ref' => 'doc_page@v1', 'reason' => 'no_bound_structure']], 'an unsatisfied dependency is reported');

// Storage holds no role-specific code: role codes appear only in the starter role
// set and the first-run starter site that seeds it.
$allowed = ['src/Runtime/StarterStructureRoles.php', 'src/FirstRun/StarterSite.php', 'src/FirstRun/SiteFirstRunContributor.php'];
$root = dirname(__DIR__, 2);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $relative = substr($file->getPathname(), strlen($root) + 1);
    if (in_array($relative, $allowed, true)) {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    foreach (['site_node', 'doc_space', 'doc_page', 'org_chart', "'redirect'"] as $code) {
        larena_storage_role_assert(!str_contains($source, $code), $relative . ' mentions the role ' . $code);
    }
}

echo "Structure role lifecycle passed.\n";
