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

larena_storage_role_assert(count($operations) === 27, 'waves A to E declare twenty-seven operations, got ' . count($operations));

$names = array_map(static fn (array $o): string => $o['declaration']->name, $operations);
sort($names);
larena_storage_role_assert($names === [
    'storage.locale.coverage',
    'storage.locale.explain',
    'storage.locale.fallback_resolve',
    'storage.locale.read',
    'storage.locale.write',
    'storage.publication.archive',
    'storage.publication.explain',
    'storage.publication.head',
    'storage.publication.history',
    'storage.publication.publish',
    'storage.publication.schedule',
    'storage.publication.sweep',
    'storage.publication.unpublish',
    'storage.read.projection_explain',
    'storage.read.published_projection',
    'storage.read.resolve_key',
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
        in_array($operation['handler_ref'], [
            'storage.handler.structure_role',
            'storage.handler.relation',
            'storage.handler.locale',
            'storage.handler.publication',
            'storage.handler.read_contract',
        ], true),
        $operation['declaration']->name . ' binds to a storage handler',
    );
}

// Registering storage beside core gives one catalogue, which is the whole point:
// REST parity and the MCP projection read this one registry.
$registry = DeclaredOperationRegistry::fromProviders([new CoreOperationProvider(), $provider]);

// Storage's own count is asserted absolutely, because an operation appearing here
// without anyone noticing is what this test is for. Core's is derived, not
// asserted: a literal would break this suite every time core adds an operation,
// which is core's business and not Storage's. The claim that matters is that the
// two catalogues compose into one without either losing an entry.
$coreCount = count($registry->list('larena/core'));
larena_storage_role_assert(count($registry->list('larena/storage')) === 27, 'storage declares 27 operations');
larena_storage_role_assert($coreCount >= 22, 'core contributes at least the operations Batch 3 declared, got ' . $coreCount);
larena_storage_role_assert(
    count($registry->list()) === $coreCount + 27,
    'one catalogue holds both packages with nothing lost',
);

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
        'storage.locale.read',
        'storage.locale.write',
        'storage.publication.archive',
        'storage.publication.publish',
        'storage.publication.read',
        'storage.publication.schedule',
        'storage.publication.unpublish',
        'storage.read.public',
        'storage.relation.manage',
        'storage.relation.read',
        'storage.role.manage',
        'storage.role.read',
    ],
    'waves A to E use exactly twelve access codes: ' . implode(', ', array_keys($scopes)),
);

// Publish, unpublish, schedule and archive are four separate access codes, which is
// what lets an editor save and schedule without holding the right to publish.
$publicationScopes = [];
foreach (['publish', 'unpublish', 'schedule', 'archive'] as $transition) {
    $publicationScopes[] = (string) $registry->describe('storage.publication.' . $transition)->accessScope;
}
larena_storage_role_assert(
    count(array_unique($publicationScopes)) === 4,
    'the four transitions do not share an access code: ' . implode(', ', $publicationScopes),
);

// The sweep is bulk, so a human running it by hand is asked first.
larena_storage_role_assert(
    $registry->describe('storage.publication.sweep')->riskClass === OperationRiskClass::Bulk,
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
