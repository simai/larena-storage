<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaCompatibilityReport;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageSchemaEvolutionTransactionScope;
use Larena\Storage\Contracts\StorageSchemaMigrationPlan;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;

require_once __DIR__ . '/StorageSchemaEvolutionTest.php';

final class StorageOwnerProtectionToken
{
}

final readonly class StorageOwnerProtectionDenyAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
        throw new AccessMutationRejected('access_actor_forbidden');
    }
}

/** @return array<string, mixed> */
function storageOwnerProtectionDefinition(string $schemaId, ?string $addedField = null, string $owner = 'larena/protected-test'): array
{
    $fields = [
        ['key' => 'title', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => []],
        ['key' => 'zero', 'type' => 'integer', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
    ];
    if ($addedField !== null) {
        $fields[] = ['key' => $addedField, 'type' => 'string', 'type_version' => 1, 'required' => false, 'visibility' => 'public', 'constraints' => []];
    }

    return ['schema_id' => $schemaId, 'owner_package' => $owner, 'fields' => $fields];
}

function storageOwnerProtectionConnection(string $path): Connection
{
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $connection = $capsule->getConnection();
    $connection->statement('PRAGMA busy_timeout=5000');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.connection', $connection);
    $container->instance('db.schema', $connection->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Schema::swap($connection->getSchemaBuilder());

    return $connection;
}

/**
 * @param WeakMap<StorageOwnerProtectionToken, array<string, mixed>> $claims
 * @param array<string, mixed> $claim
 */
function storageOwnerProtectionIssue(WeakMap $claims, array $claim): StorageOwnerProtectionToken
{
    $token = new StorageOwnerProtectionToken();
    $claims[$token] = $claim;

    return $token;
}

/**
 * @return array<string, mixed>
 */
function storageOwnerProtectionClaim(
    string $operation,
    string $actor,
    StorageSchemaVersionRef $source,
    string $sourceHash,
    string $targetHash,
    Connection $connection,
    StorageSchemaEvolutionTransactionScope $scope,
    ?string $planRef = null,
    ?string $planHash = null,
): array {
    return compact(
        'operation',
        'actor',
        'source',
        'sourceHash',
        'targetHash',
        'connection',
        'scope',
        'planRef',
        'planHash',
    );
}

/** @param array<string, mixed> $claim */
function storageOwnerProtectionClaimMatches(array $claim, StorageSchemaEvolutionOwnerContext $context): bool
{
    return ($claim['operation'] ?? null) === $context->operation
        && ($claim['actor'] ?? null) === $context->actor
        && ($claim['source'] ?? null) instanceof StorageSchemaVersionRef
        && $claim['source']->key() === $context->source->key()
        && ($claim['sourceHash'] ?? null) === $context->sourceHash
        && ($claim['targetHash'] ?? null) === $context->targetHash
        && ($claim['planRef'] ?? null) === $context->planRef
        && ($claim['planHash'] ?? null) === $context->planHash
        && ($claim['connection'] ?? null) === $context->connection
        && ($claim['scope'] ?? null) === $context->transactionScope;
}

/**
 * @param WeakMap<StorageOwnerProtectionToken, array<string, mixed>> $claims
 * @param array<string, mixed> $claimOverrides
 * @param array<string, mixed> $candidate
 */
function storageOwnerProtectionExpectPlanRejected(
    DatabaseStorageSchemaEvolution $evolution,
    StorageSchemaEvolutionOwnerPolicyRegistry $registry,
    Connection $connection,
    WeakMap $claims,
    StorageSchemaVersionRef $source,
    StorageSchemaCompatibilityReport $report,
    array $candidate,
    array $claimOverrides = [],
    ?object $capabilityOverride = null,
    string $actor = 'user:admin:1',
): void {
    $connection->transaction(static function () use (
        $evolution,
        $registry,
        $connection,
        $claims,
        $source,
        $report,
        $candidate,
        $claimOverrides,
        $capabilityOverride,
        $actor,
    ): void {
        $registry->withinTransaction($connection, static function (StorageSchemaEvolutionTransactionScope $scope) use (
            $evolution,
            $connection,
            $claims,
            $source,
            $report,
            $candidate,
            $claimOverrides,
            $capabilityOverride,
            $actor,
        ): void {
            $claim = array_replace(storageOwnerProtectionClaim(
                'plan',
                $actor,
                $source,
                $report->sourceHash,
                $report->targetHash,
                $connection,
                $scope,
            ), $claimOverrides);
            $capability = $capabilityOverride ?? storageOwnerProtectionIssue($claims, $claim);
            storageEvolutionExpectRejected(
                static fn () => $evolution->plan(
                    $source,
                    $candidate,
                    $actor,
                    'protected-plan-rejected',
                    $scope,
                    $capability,
                ),
                'storage_schema_migration_owner_orchestration_required',
            );
        });
    });
}

/** @param WeakMap<StorageOwnerProtectionToken, array<string, mixed>> $claims @param array<string, mixed> $candidate */
function storageOwnerProtectionPlan(
    DatabaseStorageSchemaEvolution $evolution,
    StorageSchemaEvolutionOwnerPolicyRegistry $registry,
    Connection $connection,
    WeakMap $claims,
    StorageSchemaVersionRef $source,
    StorageSchemaCompatibilityReport $report,
    array $candidate,
): array {
    $token = null;
    $plan = $connection->transaction(static function () use (
        $evolution,
        $registry,
        $connection,
        $claims,
        $source,
        $report,
        $candidate,
        &$token,
    ): StorageSchemaMigrationPlan {
        return $registry->withinTransaction(
            $connection,
            static function (StorageSchemaEvolutionTransactionScope $scope) use (
                $evolution,
                $connection,
                $claims,
                $source,
                $report,
                $candidate,
                &$token,
            ): StorageSchemaMigrationPlan {
                $token = storageOwnerProtectionIssue($claims, storageOwnerProtectionClaim(
                    'plan',
                    'user:admin:1',
                    $source,
                    $report->sourceHash,
                    $report->targetHash,
                    $connection,
                    $scope,
                ));

                return $evolution->plan(
                    $source,
                    $candidate,
                    'user:admin:1',
                    'protected-plan-authorized',
                    $scope,
                    $token,
                );
            },
        );
    });

    return ['plan' => $plan, 'token' => $token];
}

$opened = storageEvolutionOpen();
$secondConnection = null;
try {
    $connection = $opened['connection'];
    $authorizer = new StorageEvolutionRecordingAuthorizer();
    $sink = new StorageEvolutionRecordingAuditSink();
    $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [$sink]);
    $propertyTypes = PropertyTypeRegistry::builtIns();
    $storage = new VersionedStorage($connection, $propertyTypes, $authorizer, $audit);
    $schemaV1 = $storage->registerSchemaVersion(
        storageOwnerProtectionDefinition('protected.schema.article'),
        null,
        'user:admin:1',
        'protected-schema-v1',
    );
    $secondSchemaV1 = $storage->registerSchemaVersion(
        storageOwnerProtectionDefinition('protected.schema.secondary'),
        null,
        'user:admin:1',
        'protected-schema-secondary-v1',
    );
    $storage->create(
        'protected:article:1',
        $schemaV1->ref,
        ['title' => 'Protected exact value', 'zero' => 0],
        'user:admin:1',
        'protected-record-v1',
    );

    $candidate = storageOwnerProtectionDefinition('protected.schema.article', 'protected_secret_field');
    $alternativeCandidate = storageOwnerProtectionDefinition('protected.schema.article', 'alternative_field');
    $secondCandidate = storageOwnerProtectionDefinition('protected.schema.secondary', 'secondary_field');
    $registry = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $unsealedEvolution = new DatabaseStorageSchemaEvolution($connection, $propertyTypes, $authorizer, $audit, $registry);
    storageEvolutionExpectRejected(
        static fn () => $unsealedEvolution->plan($schemaV1->ref, $candidate, 'user:admin:1', 'unsealed-plan'),
        'storage_schema_migration_owner_registry_unsealed',
    );
    storageEvolutionExpect($connection->table('larena_storage_schema_migration_plans')->count() === 0, 'unsealed registry persisted a plan');

    /** @var WeakMap<StorageOwnerProtectionToken, array<string, mixed>> $claims */
    $claims = new WeakMap();
    $registry->protect(
        'larena/protected-test',
        static function (StorageSchemaEvolutionOwnerContext $context, ?object $capability) use ($claims): void {
            if (!$capability instanceof StorageOwnerProtectionToken || !isset($claims[$capability])) {
                throw new RuntimeException('capability_missing');
            }
            $claim = $claims[$capability];
            unset($claims[$capability]);
            if (!storageOwnerProtectionClaimMatches($claim, $context)) {
                throw new RuntimeException('capability_mismatch');
            }
        },
        'protected.schema.',
    );
    try {
        $registry->protect('larena/protected-test', static function (): void {}, 'protected.schema.');
        throw new RuntimeException('duplicate owner policy unexpectedly replaced the first validator');
    } catch (InvalidArgumentException $exception) {
        storageEvolutionExpect($exception->getMessage() === 'storage_schema_evolution_owner_policy_already_registered', 'duplicate policy reason mismatch');
    }
    $registry->seal();
    storageEvolutionExpect($registry->isSealed(), 'owner policy registry did not seal');
    try {
        $registry->protect('larena/late-policy', static function (): void {});
        throw new RuntimeException('late owner policy registration unexpectedly succeeded');
    } catch (InvalidArgumentException $exception) {
        storageEvolutionExpect($exception->getMessage() === 'storage_schema_evolution_owner_policy_registry_sealed', 'late policy reason mismatch');
    }

    $evolution = new DatabaseStorageSchemaEvolution($connection, $propertyTypes, $authorizer, $audit, $registry);
    $report = $evolution->analyze($schemaV1->ref, $candidate, 'user:admin:1', 'protected-analyze');
    $alternativeReport = $evolution->analyze($schemaV1->ref, $alternativeCandidate, 'user:admin:1', 'protected-alternative-analyze');
    $secondReport = $evolution->analyze($secondSchemaV1->ref, $secondCandidate, 'user:admin:1', 'protected-secondary-analyze');

    storageEvolutionExpectRejected(
        static fn () => $evolution->plan($schemaV1->ref, $candidate, 'user:admin:1', 'protected-direct-plan'),
        'storage_schema_migration_owner_orchestration_required',
    );
    storageEvolutionExpect($connection->table('larena_storage_schema_migration_plans')->count() === 0, 'direct protected plan persisted state');
    storageOwnerProtectionExpectPlanRejected(
        $evolution,
        $registry,
        $connection,
        $claims,
        $schemaV1->ref,
        $report,
        $candidate,
        capabilityOverride: new StorageOwnerProtectionToken(),
    );
    $cloneSource = storageOwnerProtectionIssue($claims, ['never' => 'valid']);
    storageOwnerProtectionExpectPlanRejected(
        $evolution,
        $registry,
        $connection,
        $claims,
        $schemaV1->ref,
        $report,
        $candidate,
        capabilityOverride: clone $cloneSource,
    );
    storageOwnerProtectionExpectPlanRejected($evolution, $registry, $connection, $claims, $schemaV1->ref, $report, $candidate, ['actor' => 'user:other:2']);
    storageOwnerProtectionExpectPlanRejected($evolution, $registry, $connection, $claims, $schemaV1->ref, $report, $candidate, ['operation' => 'apply']);
    storageOwnerProtectionExpectPlanRejected($evolution, $registry, $connection, $claims, $schemaV1->ref, $report, $candidate, ['source' => new StorageSchemaVersionRef($schemaV1->ref->schemaId, 2)]);
    storageOwnerProtectionExpectPlanRejected($evolution, $registry, $connection, $claims, $schemaV1->ref, $report, $candidate, ['sourceHash' => str_repeat('a', 64)]);
    storageOwnerProtectionExpectPlanRejected($evolution, $registry, $connection, $claims, $schemaV1->ref, $report, $alternativeCandidate, ['targetHash' => $report->targetHash]);
    storageOwnerProtectionExpectPlanRejected($evolution, $registry, $connection, $claims, $secondSchemaV1->ref, $secondReport, $secondCandidate, ['source' => $schemaV1->ref]);

    $expiredScope = null;
    $connection->transaction(static function () use ($registry, $connection, &$expiredScope): void {
        $registry->withinTransaction($connection, static function (StorageSchemaEvolutionTransactionScope $scope) use (&$expiredScope): void {
            $expiredScope = $scope;
        });
    });
    storageEvolutionExpect($expiredScope instanceof StorageSchemaEvolutionTransactionScope, 'expired scope fixture missing');
    $expiredToken = storageOwnerProtectionIssue($claims, storageOwnerProtectionClaim(
        'plan',
        'user:admin:1',
        $schemaV1->ref,
        $report->sourceHash,
        $report->targetHash,
        $connection,
        $expiredScope,
    ));
    $connection->transaction(static function () use ($evolution, $schemaV1, $candidate, $expiredScope, $expiredToken): void {
        storageEvolutionExpectRejected(
            static fn () => $evolution->plan(
                $schemaV1->ref,
                $candidate,
                'user:admin:1',
                'expired-scope',
                $expiredScope,
                $expiredToken,
            ),
            'storage_schema_migration_owner_orchestration_required',
        );
    });

    $secondConnection = storageOwnerProtectionConnection($opened['path']);
    $secondEvolution = new DatabaseStorageSchemaEvolution($secondConnection, $propertyTypes, $authorizer, $audit, $registry);
    storageOwnerProtectionExpectPlanRejected(
        $secondEvolution,
        $registry,
        $secondConnection,
        $claims,
        $schemaV1->ref,
        $report,
        $candidate,
        ['connection' => $connection],
    );
    Schema::swap($connection->getSchemaBuilder());

    $deniedAudit = new StorageEvolutionRecordingAuditSink();
    $denied = new DatabaseStorageSchemaEvolution(
        $connection,
        $propertyTypes,
        new StorageOwnerProtectionDenyAuthorizer(),
        new AuditEventPipeline(new DefaultAuditRedactor(), [$deniedAudit]),
        $registry,
    );
    try {
        $denied->plan($schemaV1->ref, $candidate, 'user:reader:1', 'denied-protected-plan');
        throw new RuntimeException('Access-denied protected plan unexpectedly reached owner policy');
    } catch (AccessMutationRejected $exception) {
        storageEvolutionExpect($exception->reasonCode === 'access_actor_forbidden', 'protected plan Access denial reason mismatch');
    }
    storageEvolutionExpect($deniedAudit->events === [], 'Access-denied protected plan emitted owner Audit');

    $first = storageOwnerProtectionPlan($evolution, $registry, $connection, $claims, $schemaV1->ref, $report, $candidate);
    /** @var StorageSchemaMigrationPlan $planA */
    $planA = $first['plan'];
    $usedPlanToken = $first['token'];
    storageOwnerProtectionExpectPlanRejected(
        $evolution,
        $registry,
        $connection,
        $claims,
        $schemaV1->ref,
        $report,
        $candidate,
        capabilityOverride: $usedPlanToken,
    );
    $second = storageOwnerProtectionPlan($evolution, $registry, $connection, $claims, $schemaV1->ref, $report, $candidate);
    /** @var StorageSchemaMigrationPlan $planB */
    $planB = $second['plan'];
    storageEvolutionExpect($connection->table('larena_storage_schema_migration_plans')->count() === 2, 'authorized protected plan count mismatch');

    storageEvolutionExpectRejected(
        static fn () => $evolution->apply($planA->planRef, $planA->planHash, 'user:admin:1', 'protected-direct-apply'),
        'storage_schema_migration_owner_orchestration_required',
    );
    storageEvolutionExpect($connection->table('larena_storage_schema_migration_results')->count() === 0, 'direct protected apply persisted state');

    $expectApplyRejected = static function (StorageSchemaMigrationPlan $calledPlan, array $overrides = [], ?object $overrideToken = null) use (
        $connection,
        $registry,
        $evolution,
        $claims,
        $report,
        $schemaV1,
    ): void {
        $connection->transaction(static function () use (
            $connection,
            $registry,
            $evolution,
            $claims,
            $report,
            $schemaV1,
            $calledPlan,
            $overrides,
            $overrideToken,
        ): void {
            $registry->withinTransaction($connection, static function (StorageSchemaEvolutionTransactionScope $scope) use (
                $connection,
                $evolution,
                $claims,
                $report,
                $schemaV1,
                $calledPlan,
                $overrides,
                $overrideToken,
            ): void {
                $claim = array_replace(storageOwnerProtectionClaim(
                    'apply',
                    'user:admin:1',
                    $schemaV1->ref,
                    $report->sourceHash,
                    $calledPlan->targetHash,
                    $connection,
                    $scope,
                    $calledPlan->planRef,
                    $calledPlan->planHash,
                ), $overrides);
                $token = $overrideToken ?? storageOwnerProtectionIssue($claims, $claim);
                storageEvolutionExpectRejected(
                    static fn () => $evolution->apply(
                        $calledPlan->planRef,
                        $calledPlan->planHash,
                        'user:admin:1',
                        'protected-apply-rejected',
                        $scope,
                        $token,
                    ),
                    'storage_schema_migration_owner_orchestration_required',
                );
            });
        });
    };
    $expectApplyRejected($planA, overrideToken: new StorageOwnerProtectionToken());
    $expectApplyRejected($planB, ['planRef' => $planA->planRef]);
    $expectApplyRejected($planA, ['planHash' => str_repeat('a', 64)]);
    $expectApplyRejected($planA, ['operation' => 'plan']);
    $expectApplyRejected($planA, ['actor' => 'user:other:2']);
    $expectApplyRejected($planA, ['source' => new StorageSchemaVersionRef($schemaV1->ref->schemaId, 2)]);
    $expectApplyRejected($planA, ['sourceHash' => str_repeat('b', 64)]);
    $expectApplyRejected($planA, ['targetHash' => str_repeat('c', 64)]);

    $applyToken = null;
    $connection->transaction(static function () use (
        $connection,
        $registry,
        $evolution,
        $claims,
        $schemaV1,
        $report,
        $planA,
        &$applyToken,
    ): void {
        $registry->withinTransaction($connection, static function (StorageSchemaEvolutionTransactionScope $scope) use (
            $connection,
            $evolution,
            $claims,
            $schemaV1,
            $report,
            $planA,
            &$applyToken,
        ): void {
            $applyToken = storageOwnerProtectionIssue($claims, storageOwnerProtectionClaim(
                'apply',
                'user:admin:1',
                $schemaV1->ref,
                $report->sourceHash,
                $planA->targetHash,
                $connection,
                $scope,
                $planA->planRef,
                $planA->planHash,
            ));
            $evolution->apply(
                $planA->planRef,
                $planA->planHash,
                'user:admin:1',
                'protected-apply-authorized',
                $scope,
                $applyToken,
            );
        });
    });
    storageEvolutionExpect($connection->table('larena_storage_schema_migration_results')->count() === 1, 'authorized protected apply result missing');
    $expectApplyRejected($planB, overrideToken: $applyToken);

    $unprotectedDefinition = storageOwnerProtectionDefinition('generic.schema.article', null, 'larena/unprotected-test');
    $unprotectedV1 = $storage->registerSchemaVersion($unprotectedDefinition, null, 'user:admin:1', 'unprotected-v1');
    $unprotectedCandidate = storageOwnerProtectionDefinition('generic.schema.article', 'subtitle', 'larena/unprotected-test');
    $unprotectedPlan = $evolution->plan($unprotectedV1->ref, $unprotectedCandidate, 'user:admin:1', 'unprotected-plan');
    $evolution->apply($unprotectedPlan->planRef, $unprotectedPlan->planHash, 'user:admin:1', 'unprotected-apply');
    storageEvolutionExpect($connection->table('larena_storage_schema_migration_results')->count() === 2, 'unprotected owner compatibility changed');

    $auditJson = json_encode($sink->events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['protected_secret_field', 'alternative_field', 'protected-plan-rejected'] as $secret) {
        storageEvolutionExpect(!str_contains($auditJson, $secret), 'owner protection Audit leaked raw schema/correlation material');
    }
} finally {
    if ($secondConnection instanceof Connection) {
        $secondConnection->disconnect();
    }
    Facade::clearResolvedInstances();
    foreach ([$opened['path'], $opened['path'] . '-wal', $opened['path'] . '-shm', $opened['path'] . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageSchemaEvolutionOwnerProtectionTest passed.\n";
