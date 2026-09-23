<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/structure-role-schema.php';

use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Registry\CoreOperationProvider;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Storage\Registry\StorageOperationProvider;

// Storage's declarations load and every one of them binds to a descriptor. This
// is where a declaration that disagrees with its handler is caught: the registry
// refuses the pair and names the field.
$provider = new StorageOperationProvider();
$operations = $provider->operations();

larena_storage_role_assert(count($operations) === 11, 'waves A and B declare eleven operations, got ' . count($operations));

$names = array_map(static fn (array $o): string => $o['declaration']->name, $operations);
sort($names);
larena_storage_role_assert($names === [
    'storage.relation.define',
    'storage.relation.explain',
    'storage.relation.resolve',
    'storage.role.bind_structure',
    'storage.role.explain',
    'storage.role.list_structures',
    'storage.role.register',
    'storage.role.validate_structure',
    'storage.tree.ancestors',
    'storage.tree.children',
    'storage.tree.move',
], 'the declared names are the frozen ones: ' . implode(', ', $names));

foreach ($operations as $operation) {
    larena_storage_role_assert($operation['declaration']->package === 'larena/storage');
    larena_storage_role_assert(
        in_array($operation['handler_ref'], ['storage.handler.structure_role', 'storage.handler.relation'], true),
        $operation['declaration']->name . ' binds to a storage handler',
    );
}

// Registering storage beside core gives one catalogue, which is the whole point:
// REST parity and the MCP projection read this one registry.
$registry = DeclaredOperationRegistry::fromProviders([new CoreOperationProvider(), $provider]);

larena_storage_role_assert(count($registry->list()) === 33, 'core 22 plus storage 11');
larena_storage_role_assert(count($registry->list('larena/storage')) === 11);
larena_storage_role_assert(count($registry->list('larena/core')) === 22);

// The gates and risks survive into the registry.
$register = $registry->describe('storage.role.register');
larena_storage_role_assert($register->riskClass === OperationRiskClass::Change);
larena_storage_role_assert($register->reversible === true);
larena_storage_role_assert($register->transactional === true);
larena_storage_role_assert($register->idempotencyKey === 'role_ref');
larena_storage_role_assert($register->auditEvent === 'storage.role.registered');
larena_storage_role_assert($register->accessScope === 'storage.role.manage');
larena_storage_role_assert($register->receiptSchema !== null, 'a mutation declares its proposal receipt');

$explain = $registry->describe('storage.role.explain');
larena_storage_role_assert($explain->isRead());
larena_storage_role_assert($explain->auditEvent === null);
larena_storage_role_assert($explain->receiptSchema === null);

// The two access operation codes this wave adds are the ones the freeze names.
$scopes = [];
foreach ($registry->list('larena/storage') as $declaration) {
    $scopes[(string) $declaration->accessScope] = true;
}
ksort($scopes);
larena_storage_role_assert(
    array_keys($scopes) === [
        'storage.relation.manage',
        'storage.relation.read',
        'storage.role.manage',
        'storage.role.read',
    ],
    'waves A and B use exactly four access codes: ' . implode(', ', array_keys($scopes)),
);

// A subtree move is bulk, so the confirmation policy always asks before it runs.
$move = $registry->describe('storage.tree.move');
larena_storage_role_assert($move->riskClass === OperationRiskClass::Bulk, 'a subtree move is a bulk change');
larena_storage_role_assert((new \Larena\Core\Runtime\RiskClassConfirmationPolicy())->requiresConfirmation($move));

// A traversal read never asks and never audits.
foreach (['storage.tree.children', 'storage.tree.ancestors', 'storage.relation.resolve'] as $read) {
    $declaration = $registry->describe($read);
    larena_storage_role_assert($declaration->isRead(), $read . ' is a read');
    larena_storage_role_assert($declaration->auditEvent === null);
}

echo "Storage operation declarations passed.\n";
