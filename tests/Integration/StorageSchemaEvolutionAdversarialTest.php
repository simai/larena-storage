<?php

declare(strict_types=1);

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Exceptions\StoragePersistenceFailed;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;

require_once __DIR__ . '/StorageSchemaEvolutionTest.php';

final readonly class StorageEvolutionDenyAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
        throw new AccessMutationRejected('access_actor_forbidden');
    }
}

final readonly class StorageEvolutionFailAppliedAuditSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return $descriptor->type() === 'storage.schema_migration.applied';
    }

    public function write(AuditEvent $event): void
    {
        throw new RuntimeException('forced_schema_migration_audit_failure');
    }
}

final readonly class StorageEvolutionFailPlannedAuditSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return $descriptor->type() === 'storage.schema_migration.planned';
    }

    public function write(AuditEvent $event): void
    {
        throw new RuntimeException('forced_schema_migration_plan_audit_failure');
    }
}

/** @param callable(array<string, mixed>): void $scenario */
function storageEvolutionScenario(callable $scenario): void
{
    $opened = storageEvolutionOpen();
    try {
        $authorizer = new StorageEvolutionRecordingAuthorizer();
        $sink = new StorageEvolutionRecordingAuditSink();
        $propertyTypes = PropertyTypeRegistry::builtIns();
        $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [$sink]);
        $scenario([
            'connection' => $opened['connection'],
            'authorizer' => $authorizer,
            'sink' => $sink,
            'property_types' => $propertyTypes,
            'audit' => $audit,
            'storage' => new VersionedStorage($opened['connection'], $propertyTypes, $authorizer, $audit),
            'evolution' => new DatabaseStorageSchemaEvolution(
                $opened['connection'],
                $propertyTypes,
                $authorizer,
                $audit,
                storageEvolutionOwnerPolicies(),
            ),
        ]);
    } finally {
        foreach ([$opened['path'], $opened['path'] . '-wal', $opened['path'] . '-shm', $opened['path'] . '-journal'] as $file) {
            @unlink($file);
        }
    }
}

/** @param array<string, mixed> $runtime @return array<string, mixed> */
function storageEvolutionPrepared(array $runtime): array
{
    /** @var VersionedStorage $storage */
    $storage = $runtime['storage'];
    /** @var DatabaseStorageSchemaEvolution $evolution */
    $evolution = $runtime['evolution'];
    $v1 = $storage->registerSchemaVersion(storageEvolutionDefinition(), null, 'user:admin:1', 'prepare-v1');
    $record = $storage->create(
        'docara:page:adversarial-1',
        $v1->ref,
        ['title' => 'Проверка', 'zero' => 0, 'flag' => false, 'empty' => ''],
        'user:admin:1',
        'prepare-record',
    )->version;
    $plan = $evolution->plan($v1->ref, storageEvolutionDefinition(true), 'user:admin:1', 'prepare-plan');

    return ['v1' => $v1, 'record' => $record, 'plan' => $plan];
}

storageEvolutionScenario(static function (array $runtime): void {
    /** @var VersionedStorage $storage */
    $storage = $runtime['storage'];
    /** @var DatabaseStorageSchemaEvolution $evolution */
    $evolution = $runtime['evolution'];
    $v1 = $storage->registerSchemaVersion(storageEvolutionDefinition(), null, 'user:admin:1', 'matrix-v1');

    $cases = [];
    $candidate = storageEvolutionDefinition();
    $cases['storage_schema_migration_no_changes'] = $candidate;
    $candidate = storageEvolutionDefinition(true);
    $candidate['schema_id'] = 'docara.page.other';
    $cases['storage_schema_migration_identity_changed'] = $candidate;
    $candidate = storageEvolutionDefinition(true);
    $candidate['owner_package'] = 'larena/other';
    $cases['identity-owner'] = $candidate;
    $candidate = storageEvolutionDefinition();
    array_pop($candidate['fields']);
    $cases['storage_schema_migration_field_removed'] = $candidate;
    $candidate = storageEvolutionDefinition(true);
    $candidate['fields'][0]['type'] = 'integer';
    $cases['existing-type'] = $candidate;
    $candidate = storageEvolutionDefinition(true);
    $candidate['fields'][0]['required'] = false;
    $cases['existing-required'] = $candidate;
    $candidate = storageEvolutionDefinition(true);
    $candidate['fields'][0]['visibility'] = 'admin';
    $cases['existing-visibility'] = $candidate;
    $candidate = storageEvolutionDefinition(true);
    $candidate['fields'][0]['constraints'] = ['max_length' => 99];
    $cases['existing-constraint'] = $candidate;
    $candidate = storageEvolutionDefinition(true);
    [$candidate['fields'][0], $candidate['fields'][1]] = [$candidate['fields'][1], $candidate['fields'][0]];
    $cases['storage_schema_migration_field_order_changed'] = $candidate;
    $candidate = storageEvolutionDefinition(true);
    $candidate['fields'][5]['required'] = true;
    $cases['storage_schema_migration_required_field_added'] = $candidate;
    $candidate = storageEvolutionDefinition(true);
    $candidate['fields'][5]['constraints'] = ['max_length' => 40];
    $cases['storage_schema_migration_added_field_constraints_unsupported'] = $candidate;

    foreach ($cases as $label => $definition) {
        $report = $evolution->analyze($v1->ref, $definition, 'user:admin:1', 'matrix-' . $label);
        storageEvolutionExpect(!$report->compatible, 'incompatible case accepted: ' . $label);
        $expectedReason = str_starts_with($label, 'storage_') ? $label : match ($label) {
            'identity-owner' => 'storage_schema_migration_identity_changed',
            default => 'storage_schema_migration_existing_field_changed',
        };
        storageEvolutionExpect(in_array($expectedReason, $report->reasonCodes, true), 'missing reason for ' . $label);
        storageEvolutionExpectRejected(
            static fn () => $evolution->plan($v1->ref, $definition, 'user:admin:1', 'matrix-plan-' . $label),
            $expectedReason,
        );
    }

    $unknown = storageEvolutionDefinition(true);
    $unknown['unknown'] = true;
    storageEvolutionExpectRejected(
        static fn () => $evolution->analyze($v1->ref, $unknown, 'user:admin:1', 'unknown-top'),
        'storage_schema_definition_unknown_key',
    );
    $unknown = storageEvolutionDefinition(true);
    $unknown['fields'][5]['unknown'] = true;
    storageEvolutionExpectRejected(
        static fn () => $evolution->analyze($v1->ref, $unknown, 'user:admin:1', 'unknown-field'),
        'storage_schema_field_unknown_key',
    );
    $unknown = storageEvolutionDefinition(true);
    $unknown['fields'][0]['type_version'] = 3;
    storageEvolutionExpectRejected(
        static fn () => $evolution->analyze($v1->ref, $unknown, 'user:admin:1', 'unknown-type-version'),
        'storage_schema_field_invalid',
    );
    storageEvolutionExpect(
        $runtime['connection']->table('larena_storage_schema_migration_plans')->count() === 0,
        'incompatible/unknown analysis persisted a plan',
    );
    storageEvolutionExpectRejected(
        static fn () => $storage->create(
            'docara:page:null-not-owned',
            $v1->ref,
            ['title' => 'valid', 'nullable' => null],
            'user:admin:1',
            'explicit-null',
        ),
        'storage_record_field_invalid',
    );

    $legacy = [
        'schema_id' => 'docara.page.legacy_defaults',
        'owner_package' => 'larena/docara',
        'fields' => [['key' => 'title', 'type' => 'string', 'visibility' => 'public']],
    ];
    $legacyV1 = $storage->registerSchemaVersion($legacy, null, 'user:admin:1', 'legacy-v1');
    $legacy['fields'][] = ['key' => 'subtitle', 'type' => 'string', 'visibility' => 'public'];
    storageEvolutionExpect(
        $evolution->analyze($legacyV1->ref, $legacy, 'user:admin:1', 'legacy-v2')->compatible,
        'established omitted descriptor defaults stopped working',
    );

    $auditJson = json_encode($runtime['sink']->events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['title', 'subtitle', 'Проверка'] as $secret) {
        storageEvolutionExpect(!str_contains($auditJson, $secret), 'rejection audit leaked schema/value material');
    }
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    /** @var DatabaseStorageSchemaEvolution $evolution */
    $evolution = $runtime['evolution'];
    $secretPlanRef = 'SECRET_RAW_PLAN_REF_TOKEN_4d5f9c8b';
    storageEvolutionExpectRejected(
        static fn () => $evolution->explain('INVALID_PLAN_REF', 'user:admin:1'),
        'storage_schema_migration_plan_ref_invalid',
    );
    storageEvolutionExpectRejected(
        static fn () => $evolution->apply('INVALID_PLAN_REF', 'INVALID_HASH', 'user:admin:1', 'invalid-ref'),
        'storage_schema_migration_plan_ref_invalid',
    );
    try {
        $evolution->apply($secretPlanRef, str_repeat('a', 64), 'user:admin:1', 'secret-ref');
        throw new RuntimeException('malformed secret-bearing plan ref unexpectedly accepted');
    } catch (StorageRejected $exception) {
        storageEvolutionExpect($exception->reasonCode === 'storage_schema_migration_plan_ref_invalid', 'secret plan ref reason mismatch');
        $safeExceptionSurface = $exception->reasonCode . ':' . $exception->getMessage();
        storageEvolutionExpect(!str_contains($safeExceptionSurface, $secretPlanRef), 'secret plan ref leaked through exception output');
    }
    $auditJson = json_encode($runtime['sink']->events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    storageEvolutionExpect(!str_contains($auditJson, $secretPlanRef), 'secret plan ref leaked through rejection Audit');
    storageEvolutionExpectRejected(
        static fn () => $evolution->apply($prepared['plan']->planRef, 'INVALID_HASH', 'user:admin:1', 'invalid-hash'),
        'storage_schema_migration_plan_hash_invalid',
    );
    storageEvolutionExpectRejected(
        static fn () => $evolution->apply($prepared['plan']->planRef, str_repeat('a', 64), 'user:admin:1', 'wrong-hash'),
        'storage_schema_migration_plan_hash_mismatch',
    );
    storageEvolutionExpect($runtime['connection']->table('larena_storage_schema_migration_results')->count() === 0, 'wrong hash mutated result state');
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $definition = storageEvolutionDefinition(true);
    $definition['owner_package'] = 'larena/tampered';
    $runtime['connection']->table('larena_storage_schema_migration_plans')
        ->where('plan_ref', $prepared['plan']->planRef)
        ->update(['target_definition' => json_encode($definition, JSON_THROW_ON_ERROR)]);
    storageEvolutionExpectRejected(
        static fn () => $runtime['evolution']->apply($prepared['plan']->planRef, $prepared['plan']->planHash, 'user:admin:1', 'tampered-definition'),
        'storage_schema_migration_plan_tampered',
    );
    storageEvolutionExpect($runtime['connection']->table('larena_storage_schemas')->value('current_version') === 1, 'definition tamper advanced schema');
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $runtime['connection']->table('larena_storage_schema_migration_plan_records')
        ->where('plan_ref', $prepared['plan']->planRef)
        ->update(['owner_ref' => 'docara:page:tampered']);
    storageEvolutionExpectRejected(
        static fn () => $runtime['evolution']->apply($prepared['plan']->planRef, $prepared['plan']->planHash, 'user:admin:1', 'tampered-item'),
        'storage_schema_migration_plan_tampered',
    );
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $tampered = storageEvolutionDefinition();
    $tampered['fields'][0]['visibility'] = 'admin';
    $json = json_encode($tampered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $runtime['connection']->table('larena_storage_schema_versions')
        ->where('schema_id', $prepared['v1']->ref->schemaId)
        ->where('version', 1)
        ->update(['definition' => $json, 'definition_hash' => hash('sha256', $json)]);
    storageEvolutionExpectRejected(
        static fn () => $runtime['evolution']->apply($prepared['plan']->planRef, $prepared['plan']->planHash, 'user:admin:1', 'tampered-source'),
        'storage_schema_migration_plan_tampered',
    );
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $runtime['connection']->table('larena_storage_record_versions')
        ->where('record_id', $prepared['record']->ref->recordId)
        ->where('revision', 1)
        ->update(['owner_ref' => 'docara:page:foreign-owner']);
    storageEvolutionExpectRejected(
        static fn () => $runtime['evolution']->apply($prepared['plan']->planRef, $prepared['plan']->planHash, 'user:admin:1', 'tampered-record-owner'),
        'storage_schema_migration_record_incompatible',
    );
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $runtime['storage']->compareAndSwap(
        'docara:page:adversarial-1',
        $prepared['record']->ref,
        $prepared['v1']->ref,
        ['title' => 'new head', 'zero' => 0, 'flag' => false, 'empty' => ''],
        'user:admin:1',
        'stale-record-write',
    );
    storageEvolutionExpectRejected(
        static fn () => $runtime['evolution']->apply($prepared['plan']->planRef, $prepared['plan']->planHash, 'user:admin:1', 'stale-record-apply'),
        'storage_schema_migration_record_heads_stale',
    );
    storageEvolutionExpect($runtime['connection']->table('larena_storage_schemas')->value('current_version') === 1, 'stale record plan advanced schema');
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $winner = $runtime['evolution']->plan($prepared['v1']->ref, storageEvolutionDefinition(true), 'user:admin:1', 'winner-plan');
    $runtime['evolution']->apply($winner->planRef, $winner->planHash, 'user:admin:1', 'winner-apply');
    storageEvolutionExpectRejected(
        static fn () => $runtime['evolution']->apply($prepared['plan']->planRef, $prepared['plan']->planHash, 'user:admin:1', 'stale-schema-apply'),
        'storage_schema_migration_schema_head_stale',
    );
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $failing = new DatabaseStorageSchemaEvolution(
        $runtime['connection'],
        $runtime['property_types'],
        $runtime['authorizer'],
        new AuditEventPipeline(new DefaultAuditRedactor(), [new StorageEvolutionFailAppliedAuditSink()]),
        storageEvolutionOwnerPolicies(),
    );
    try {
        $failing->apply($prepared['plan']->planRef, $prepared['plan']->planHash, 'user:admin:1', 'audit-failure');
        throw new RuntimeException('audit failure unexpectedly committed');
    } catch (StoragePersistenceFailed $exception) {
        storageEvolutionExpect($exception->reasonCode === 'storage_persistence_failed', 'audit failure reason mismatch');
    }
    storageEvolutionExpect($runtime['connection']->table('larena_storage_schemas')->value('current_version') === 1, 'audit failure advanced schema');
    storageEvolutionExpect($runtime['connection']->table('larena_storage_records')->value('current_revision') === 1, 'audit failure advanced record');
    storageEvolutionExpect($runtime['connection']->table('larena_storage_schema_migration_results')->count() === 0, 'audit failure persisted result');
    storageEvolutionExpect($runtime['connection']->table('larena_storage_schema_versions')->count() === 1, 'audit failure persisted schema version');
});

storageEvolutionScenario(static function (array $runtime): void {
    $v1 = $runtime['storage']->registerSchemaVersion(storageEvolutionDefinition(), null, 'user:admin:1', 'plan-audit-v1');
    $failing = new DatabaseStorageSchemaEvolution(
        $runtime['connection'],
        $runtime['property_types'],
        $runtime['authorizer'],
        new AuditEventPipeline(new DefaultAuditRedactor(), [new StorageEvolutionFailPlannedAuditSink()]),
        storageEvolutionOwnerPolicies(),
    );
    try {
        $failing->plan($v1->ref, storageEvolutionDefinition(true), 'user:admin:1', 'plan-audit-failure');
        throw new RuntimeException('plan Audit failure unexpectedly committed');
    } catch (StoragePersistenceFailed $exception) {
        storageEvolutionExpect($exception->reasonCode === 'storage_persistence_failed', 'plan Audit failure reason mismatch');
    }
    storageEvolutionExpect($runtime['connection']->table('larena_storage_schema_migration_plans')->count() === 0, 'plan Audit failure persisted plan row');
    storageEvolutionExpect($runtime['connection']->table('larena_storage_schema_migration_plan_records')->count() === 0, 'plan Audit failure persisted item rows');
});

storageEvolutionScenario(static function (array $runtime): void {
    $runtime['storage']->registerSchemaVersion(storageEvolutionDefinition(), null, 'user:admin:1', 'denied-v1');
    $denied = new DatabaseStorageSchemaEvolution(
        $runtime['connection'],
        $runtime['property_types'],
        new StorageEvolutionDenyAuthorizer(),
        $runtime['audit'],
        storageEvolutionOwnerPolicies(),
    );
    try {
        $denied->analyze(new Larena\Storage\Contracts\StorageSchemaVersionRef('docara.page.evolution', 1), storageEvolutionDefinition(true), 'user:reader:1');
        throw new RuntimeException('denied analysis unexpectedly succeeded');
    } catch (AccessMutationRejected $exception) {
        storageEvolutionExpect($exception->reasonCode === 'access_actor_forbidden', 'Access denial reason mismatch');
    }
    foreach ([
        static fn () => $denied->plan(
            new Larena\Storage\Contracts\StorageSchemaVersionRef('docara.page.evolution', 1),
            storageEvolutionDefinition(true),
            'user:reader:1',
        ),
        static fn () => $denied->explain('PRIVATE_INVALID_PLAN_REF', 'user:reader:1'),
        static fn () => $denied->apply('PRIVATE_INVALID_PLAN_REF', 'PRIVATE_INVALID_HASH', 'user:reader:1'),
    ] as $deniedOperation) {
        try {
            $deniedOperation();
            throw new RuntimeException('denied plan operation unexpectedly reached validation');
        } catch (AccessMutationRejected $exception) {
            storageEvolutionExpect($exception->reasonCode === 'access_actor_forbidden', 'Access validation-oracle denial mismatch');
        }
    }
    storageEvolutionExpect($runtime['connection']->table('larena_storage_schema_migration_plans')->count() === 0, 'Access denial persisted plan');
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $runtime['connection']->table('larena_storage_record_versions')
        ->where('record_id', $prepared['record']->ref->recordId)
        ->where('revision', 1)
        ->update(['values_json' => json_encode(['title' => 'PRIVATE_TAMPER'], JSON_THROW_ON_ERROR)]);
    storageEvolutionExpectRejected(
        static fn () => $runtime['storage']->readAdminCurrentVersion('docara.page.evolution', 'docara:page:adversarial-1', 'user:admin:1'),
        'storage_record_values_corrupt',
    );
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $transplanted = storageEvolutionDefinition();
    $transplanted['schema_id'] = 'docara.page.transplanted';
    $normalizer = new Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer($runtime['property_types']);
    $json = $normalizer->canonicalJson($normalizer->normalize($transplanted));
    $runtime['connection']->table('larena_storage_schema_versions')
        ->where('schema_id', $prepared['v1']->ref->schemaId)
        ->where('version', 1)
        ->update(['definition' => $json, 'definition_hash' => hash('sha256', $json)]);
    storageEvolutionExpectRejected(
        static fn () => $runtime['storage']->schemaVersion($prepared['v1']->ref),
        'storage_schema_definition_corrupt',
    );
});

storageEvolutionScenario(static function (array $runtime): void {
    $prepared = storageEvolutionPrepared($runtime);
    $runtime['connection']->table('larena_storage_records')
        ->where('record_id', $prepared['record']->ref->recordId)
        ->update(['current_hash' => str_repeat('f', 64)]);
    storageEvolutionExpectRejected(
        static fn () => $runtime['storage']->readAdminCurrentVersion(
            'docara.page.evolution',
            'docara:page:adversarial-1',
            'user:admin:1',
        ),
        'storage_record_head_corrupt',
    );
    storageEvolutionExpectRejected(
        static fn () => $runtime['storage']->compareAndSwap(
            'docara:page:adversarial-1',
            $prepared['record']->ref,
            $prepared['v1']->ref,
            ['title' => 'safe', 'zero' => 0, 'flag' => false, 'empty' => ''],
            'user:admin:1',
            'corrupt-head-cas',
        ),
        'storage_record_head_corrupt',
    );
});

echo "StorageSchemaEvolutionAdversarialTest passed.\n";
