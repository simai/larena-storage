<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/structure-role-schema.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Core\Contracts\ScopeRecord;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\ScopeStatus;
use Larena\Core\Scope\ScopeRef;
use Larena\Storage\Exceptions\StructureRoleRejected;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;

/**
 * @param callable(): mixed $call
 */
function larena_storage_role_denied(callable $call, string $expectedReason): void
{
    try {
        $call();
    } catch (StructureRoleRejected $rejection) {
        larena_storage_role_assert(
            $rejection->reasonCode === $expectedReason,
            'expected ' . $expectedReason . ', got ' . $rejection->reasonCode,
        );

        return;
    }

    throw new RuntimeException('Boundary "' . $expectedReason . '" did not fail closed.');
}

$connection = larena_storage_structure_role_connection();
$registry = new DatabaseStructureRoleRegistry($connection);
(new StarterStructureRoles($registry))->install('actor:installer');

// An unknown role fails closed in both the validate and the bind path.
larena_storage_role_denied(
    static fn (): mixed => $registry->validateStructure('nope@v1', 'site.pages', larena_storage_site_node_fields()),
    'unknown_role',
);
larena_storage_role_denied(
    static fn (): mixed => $registry->bindStructure('nope@v1', 'site.pages', 'site:main', [], 'actor:admin'),
    'unknown_role',
);

// Binding a non-conforming structure writes nothing.
larena_storage_role_denied(
    static fn (): mixed => $registry->bindStructure(
        'site_node@v1',
        'site.pages',
        'site:main',
        [['key' => 'slug', 'type' => 'string']],
        'actor:admin',
        ['site_tree_parent'],
        'publishable',
    ),
    'role_conformance_failed',
);
larena_storage_role_assert(
    $registry->listStructures('site:main') === [],
    'a refused binding must leave no row behind',
);

// The same structure cannot be bound twice to the same role in the same scope.
$registry->bindStructure(
    'site_node@v1',
    'site.pages',
    'site:main',
    larena_storage_site_node_fields(),
    'actor:admin',
    ['site_tree_parent'],
    'publishable',
);
larena_storage_role_denied(
    static fn (): mixed => $registry->bindStructure(
        'site_node@v1',
        'site.pages',
        'site:main',
        larena_storage_site_node_fields(),
        'actor:admin',
        ['site_tree_parent'],
        'publishable',
    ),
    'duplicate_binding',
);

// An unknown scope fails closed when a scope resolver is bound. Without one the
// caller has taken responsibility for the scope, which the registry states rather
// than pretending to check.
final class LarenaStorageTestScopeResolver implements ScopeRefResolver
{
    /** @param list<string> $known */
    public function __construct(private readonly array $known)
    {
    }

    public function resolve(string $reference): ?ScopeRecord
    {
        if (!in_array($reference, $this->known, true)) {
            return null;
        }

        return new ScopeRecord(
            ref: ScopeRef::parse($reference),
            kind: ScopeKind::Site,
            parentRef: null,
            name: 'Main',
            status: ScopeStatus::Active,
            createdBy: 'actor:test',
        );
    }

    public function exists(string $reference): bool
    {
        return $this->resolve($reference) !== null;
    }
}

$scoped = new DatabaseStructureRoleRegistry($connection, new LarenaStorageTestScopeResolver(['site:main']));

larena_storage_role_denied(
    static fn (): mixed => $scoped->bindStructure(
        'site_node@v1',
        'site.other',
        'site:absent',
        larena_storage_site_node_fields(),
        'actor:admin',
        ['site_tree_parent'],
        'publishable',
    ),
    'unknown_scope',
);
larena_storage_role_assert($scoped->listStructures('site:absent') === [], 'an unknown scope writes nothing');

// A known scope still binds through the same path.
$bound = $scoped->bindStructure(
    'site_node@v1',
    'site.other',
    'site:main',
    larena_storage_site_node_fields(),
    'actor:admin',
    ['site_tree_parent'],
    'publishable',
);
larena_storage_role_assert($bound->scopeRef === 'site:main');

// Without the tables nothing pretends to work.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$bare = new DatabaseStructureRoleRegistry($capsule->getConnection());
larena_storage_role_denied(static fn (): mixed => $bare->read('site_node@v1'), 'schema_missing');
larena_storage_role_denied(static fn (): mixed => $bare->list(), 'schema_missing');

echo "Structure role fail-closed boundaries passed.\n";
