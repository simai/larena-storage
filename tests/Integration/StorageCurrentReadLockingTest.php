<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;

require_once __DIR__ . '/../../vendor/autoload.php';

final class StorageCurrentReadRecordingConnection extends SQLiteConnection
{
    /** @var list<string> */
    private array $recordedSelects = [];

    /**
     * Execute MySQL locking-read SQL against disposable SQLite after recording
     * the exact generated statement.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @param bool $useReadPdo
     * @param array<int|string, mixed> $fetchUsing
     * @return array<int, object>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $sql = (string) $query;
        $this->recordedSelects[] = $sql;
        $executable = preg_replace('/\s+for update\s*$/i', '', $sql);
        if (!is_string($executable)) {
            throw new RuntimeException('storage_locking_test_sql_rewrite_failed');
        }

        return parent::select($executable, $bindings, $useReadPdo, $fetchUsing);
    }

    public function clearRecordedSelects(): void
    {
        $this->recordedSelects = [];
    }

    /** @return list<string> */
    public function recordedSelects(): array
    {
        return $this->recordedSelects;
    }
}

final readonly class StorageCurrentReadAllowAllAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
    }
}

function storageCurrentReadExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function storageCurrentReadDefinition(bool $versionTwo = false): array
{
    $fields = [
        [
            'key' => 'title',
            'type' => 'string',
            'type_version' => 1,
            'required' => true,
            'visibility' => 'public',
            'constraints' => [],
        ],
    ];
    if ($versionTwo) {
        $fields[] = [
            'key' => 'subtitle',
            'type' => 'string',
            'type_version' => 1,
            'required' => false,
            'visibility' => 'public',
            'constraints' => [],
        ];
    }

    return [
        'schema_id' => 'content.page.current_read',
        'owner_package' => 'larena/content',
        'fields' => $fields,
    ];
}

/** @param list<string> $statements */
function storageCurrentReadHasLockedSelect(array $statements, string $table): bool
{
    foreach ($statements as $statement) {
        if (str_contains($statement, '`' . $table . '`')
            && preg_match('/\sfor update\s*$/i', $statement) === 1) {
            return true;
        }
    }

    return false;
}

/** @param list<string> $statements */
function storageCurrentReadHasAnyLock(array $statements): bool
{
    foreach ($statements as $statement) {
        if (preg_match('/\sfor update\s*$/i', $statement) === 1) {
            return true;
        }
    }

    return false;
}

$pdo = new PDO('sqlite::memory:');
$connection = new StorageCurrentReadRecordingConnection(
    $pdo,
    ':memory:',
    '',
    ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
);
$container = new Container();
$container->instance('db.connection', $connection);
$container->instance('db.schema', $connection->getSchemaBuilder());
Facade::clearResolvedInstances();
Schema::swap($connection->getSchemaBuilder());

try {
    (require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/2026_07_14_000002_create_larena_storage_schema_migration_tables.php')->up();

    $propertyTypes = PropertyTypeRegistry::builtIns();
    $authorizer = new StorageCurrentReadAllowAllAuthorizer();
    $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [new InMemoryAuditSink()]);
    $storage = new VersionedStorage($connection, $propertyTypes, $authorizer, $audit);
    $ownerPolicies = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $ownerPolicies->seal();
    $evolution = new DatabaseStorageSchemaEvolution(
        $connection,
        $propertyTypes,
        $authorizer,
        $audit,
        $ownerPolicies,
    );

    $schema = $storage->registerSchemaVersion(
        storageCurrentReadDefinition(),
        null,
        'user:admin:1',
        'current-read-schema',
    );
    $created = $storage->create(
        'content:item:current-read-1',
        $schema->ref,
        ['title' => 'Current read'],
        'user:admin:1',
        'current-read-record',
    );

    $connection->setQueryGrammar(new MySqlGrammar($connection));

    $connection->clearRecordedSelects();
    $lockedSchema = $storage->schemaVersion($schema->ref, true);
    storageCurrentReadExpect($lockedSchema->ref->key() === $schema->ref->key(), 'locked schema read returned the wrong version');
    storageCurrentReadExpect(
        storageCurrentReadHasLockedSelect($connection->recordedSelects(), 'larena_storage_schema_versions'),
        'locked schema read did not lock the exact immutable schema version',
    );

    $connection->clearRecordedSelects();
    $storage->schemaVersion($schema->ref);
    storageCurrentReadExpect(
        !storageCurrentReadHasAnyLock($connection->recordedSelects()),
        'default schema read unexpectedly acquired a write lock',
    );

    $connection->clearRecordedSelects();
    $lockedHistoricalRecord = $storage->readAdminVersion(
        $created->version->ref,
        'user:admin:1',
        true,
    );
    storageCurrentReadExpect(
        $lockedHistoricalRecord->ref->key() === $created->version->ref->key(),
        'locked historical record read returned the wrong version',
    );
    storageCurrentReadExpect(
        storageCurrentReadHasLockedSelect($connection->recordedSelects(), 'larena_storage_record_versions'),
        'locked historical record read did not lock the exact immutable record version',
    );

    $connection->clearRecordedSelects();
    $storage->readAdminVersion($created->version->ref, 'user:admin:1');
    storageCurrentReadExpect(
        !storageCurrentReadHasAnyLock($connection->recordedSelects()),
        'default historical record read unexpectedly acquired a write lock',
    );

    $connection->clearRecordedSelects();
    $lockedRecord = $storage->readAdminCurrentVersion(
        $schema->ref->schemaId,
        $created->version->ownerRef,
        'user:admin:1',
        true,
    );
    storageCurrentReadExpect($lockedRecord?->ref->key() === $created->version->ref->key(), 'locked current read returned the wrong record');
    storageCurrentReadExpect(
        storageCurrentReadHasLockedSelect($connection->recordedSelects(), 'larena_storage_records'),
        'locked current read did not lock the record head',
    );
    storageCurrentReadExpect(
        storageCurrentReadHasLockedSelect($connection->recordedSelects(), 'larena_storage_record_versions'),
        'locked current read did not lock the exact immutable record version',
    );

    $connection->clearRecordedSelects();
    $storage->readAdminCurrentVersion(
        $schema->ref->schemaId,
        $created->version->ownerRef,
        'user:admin:1',
    );
    storageCurrentReadExpect(
        !storageCurrentReadHasAnyLock($connection->recordedSelects()),
        'default current read unexpectedly acquired write locks',
    );

    $connection->clearRecordedSelects();
    $secondCreated = $storage->create(
        'content:item:current-read-2',
        $schema->ref,
        ['title' => 'Second current read'],
        'user:admin:1',
        'current-read-record-2',
    );
    storageCurrentReadExpect(
        storageCurrentReadHasLockedSelect($connection->recordedSelects(), 'larena_storage_schemas'),
        'record create did not lock the schema head',
    );
    storageCurrentReadExpect(
        storageCurrentReadHasLockedSelect($connection->recordedSelects(), 'larena_storage_schema_versions'),
        'record create did not current-read the exact schema version',
    );

    $connection->clearRecordedSelects();
    $updated = $storage->compareAndSwap(
        $created->version->ownerRef,
        $created->version->ref,
        $schema->ref,
        ['title' => 'Updated current read'],
        'user:admin:1',
        'current-read-update',
    );
    storageCurrentReadExpect($updated->version->ref->revision === 2, 'record CAS did not advance the revision');
    foreach ([
        'larena_storage_schemas',
        'larena_storage_schema_versions',
        'larena_storage_records',
        'larena_storage_record_versions',
    ] as $lockedTable) {
        storageCurrentReadExpect(
            storageCurrentReadHasLockedSelect($connection->recordedSelects(), $lockedTable),
            'record CAS missed current-read table ' . $lockedTable,
        );
    }

    $connection->clearRecordedSelects();
    $lockedProjection = $storage->projectPublicVersion($updated->version->ref, true);
    storageCurrentReadExpect(
        $lockedProjection->ref->key() === $updated->version->ref->key(),
        'locked public projection returned the wrong record version',
    );
    foreach ([
        'larena_storage_record_versions',
        'larena_storage_schema_versions',
    ] as $lockedTable) {
        storageCurrentReadExpect(
            storageCurrentReadHasLockedSelect($connection->recordedSelects(), $lockedTable),
            'locked public projection missed current-read table ' . $lockedTable,
        );
    }

    $connection->clearRecordedSelects();
    $storage->projectPublicVersion($updated->version->ref);
    storageCurrentReadExpect(
        !storageCurrentReadHasAnyLock($connection->recordedSelects()),
        'default public projection unexpectedly acquired write locks',
    );

    $connection->clearRecordedSelects();
    $report = $evolution->analyze(
        $schema->ref,
        storageCurrentReadDefinition(true),
        'user:admin:1',
        'current-read-analyze-locked',
        true,
    );
    storageCurrentReadExpect($report->compatible, 'locked compatibility analysis rejected an optional field');
    foreach ([
        'larena_storage_schemas',
        'larena_storage_schema_versions',
        'larena_storage_records',
        'larena_storage_record_versions',
    ] as $lockedTable) {
        storageCurrentReadExpect(
            storageCurrentReadHasLockedSelect($connection->recordedSelects(), $lockedTable),
            'locked compatibility analysis missed current-read table ' . $lockedTable,
        );
    }

    $connection->clearRecordedSelects();
    $evolution->analyze(
        $schema->ref,
        storageCurrentReadDefinition(true),
        'user:admin:1',
        'current-read-analyze-default',
    );
    storageCurrentReadExpect(
        !storageCurrentReadHasAnyLock($connection->recordedSelects()),
        'default compatibility analysis unexpectedly acquired write locks',
    );

    storageCurrentReadExpect(
        $secondCreated->version->ref->revision === 1,
        'second record fixture was not persisted before schema migration',
    );
    $plan = $evolution->plan(
        $schema->ref,
        storageCurrentReadDefinition(true),
        'user:admin:1',
        'current-read-plan',
    );
    $connection->clearRecordedSelects();
    $evolution->apply(
        $plan->planRef,
        $plan->planHash,
        'user:admin:1',
        'current-read-apply',
    );
    storageCurrentReadExpect(
        storageCurrentReadHasLockedSelect($connection->recordedSelects(), 'larena_storage_records'),
        'schema apply did not lock record heads',
    );
    storageCurrentReadExpect(
        storageCurrentReadHasLockedSelect($connection->recordedSelects(), 'larena_storage_record_versions'),
        'schema apply did not lock the exact current record versions',
    );
} finally {
    Facade::clearResolvedInstances();
}

echo "StorageCurrentReadLockingTest passed.\n";
