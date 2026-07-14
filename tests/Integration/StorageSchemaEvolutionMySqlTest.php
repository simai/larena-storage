<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Database\StorageSchemaMigrationTableShapeGuard;
use Larena\Storage\Exceptions\StorageOwnedTableShapeRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/StorageSchemaMigrationTableShapeTestSupport.php';

final class StorageEvolutionMySqlAuthorizer implements ActorOperationAuthorizer
{
    public function assertAllowed(string $actor, string $operation): void
    {
    }
}

final class StorageEvolutionMySqlAuditSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
    }
}

function storageEvolutionMySqlExpect(bool $condition, string $reason): void
{
    if (!$condition) {
        throw new RuntimeException($reason);
    }
}

/** @param callable(): mixed $callback */
function storageEvolutionMySqlExpectRejected(callable $callback, string $reason): void
{
    try {
        $callback();
    } catch (StorageRejected $exception) {
        storageEvolutionMySqlExpect($exception->reasonCode === $reason, 'storage_mysql_rejection_reason_mismatch');
        storageEvolutionMySqlExpect($exception->getMessage() === $reason, 'storage_mysql_rejection_surface_not_sanitized');

        return;
    }

    throw new RuntimeException('storage_mysql_expected_rejection_missing');
}

/** @return array<string, string> */
function storageEvolutionMySqlParseEnv(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('storage_mysql_env_unreadable');
    }

    $values = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (str_starts_with($trimmed, 'export ')) {
            $trimmed = ltrim(substr($trimmed, 7));
        }
        if (preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $trimmed, $matches) !== 1) {
            continue;
        }
        $value = trim($matches[2]);
        if (strlen($value) >= 2 && $value[0] === "'" && $value[strlen($value) - 1] === "'") {
            $value = substr($value, 1, -1);
        } elseif (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $value = stripcslashes(substr($value, 1, -1));
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
        }
        $values[$matches[1]] = $value;
    }

    return $values;
}

/** @param list<string> $command */
function storageEvolutionMySqlCommandStatus(array $command, string $cwd): int
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('storage_mysql_git_preflight_failed');
    }
    foreach ([1, 2] as $index) {
        if (isset($pipes[$index]) && is_resource($pipes[$index])) {
            stream_get_contents($pipes[$index]);
            fclose($pipes[$index]);
        }
    }

    return proc_close($process);
}

/**
 * @return array{host: string, port: int, username: string, password: string}
 */
function storageEvolutionMySqlCredentials(): array
{
    $root = dirname(__DIR__, 5) . '/larena';
    $expectedPath = $root . '/.env.auth-mfa-mysql-test';
    $realPath = realpath($expectedPath);
    storageEvolutionMySqlExpect(is_string($realPath) && $realPath === $expectedPath, 'storage_mysql_env_path_invalid');
    storageEvolutionMySqlExpect(basename($realPath) === '.env.auth-mfa-mysql-test', 'storage_mysql_env_name_invalid');
    storageEvolutionMySqlExpect(
        storageEvolutionMySqlCommandStatus(['git', 'check-ignore', '--quiet', '--', '.env.auth-mfa-mysql-test'], $root) === 0,
        'storage_mysql_env_not_ignored',
    );
    storageEvolutionMySqlExpect(
        storageEvolutionMySqlCommandStatus(['git', 'ls-files', '--error-unmatch', '--', '.env.auth-mfa-mysql-test'], $root) !== 0,
        'storage_mysql_env_tracked',
    );
    $permissions = fileperms($realPath);
    storageEvolutionMySqlExpect(is_int($permissions) && ($permissions & 0o077) === 0, 'storage_mysql_env_permissions_unsafe');

    $values = storageEvolutionMySqlParseEnv($realPath);
    foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $required) {
        storageEvolutionMySqlExpect(array_key_exists($required, $values), 'storage_mysql_env_incomplete');
    }
    $host = trim($values['DB_HOST']);
    storageEvolutionMySqlExpect(in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true), 'storage_mysql_host_not_local');
    storageEvolutionMySqlExpect(ctype_digit($values['DB_PORT']), 'storage_mysql_port_invalid');
    $port = (int) $values['DB_PORT'];
    storageEvolutionMySqlExpect($port >= 1 && $port <= 65535, 'storage_mysql_port_invalid');
    storageEvolutionMySqlExpect($values['DB_USERNAME'] !== '', 'storage_mysql_username_invalid');

    return [
        'host' => $host,
        'port' => $port,
        'username' => $values['DB_USERNAME'],
        'password' => $values['DB_PASSWORD'],
    ];
}

/** @param array{host: string, port: int, username: string, password: string} $credentials */
function storageEvolutionMySqlServer(array $credentials): PDO
{
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $credentials['host'], $credentials['port']),
        $credentials['username'],
        $credentials['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $server->exec('SET SESSION lock_wait_timeout = 10');

    return $server;
}

/**
 * @param array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string, collation: string, prefix: string, strict: bool} $config
 */
function storageEvolutionMySqlConnection(array $config): Connection
{
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection($config);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    $connection = $capsule->getConnection();
    $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');
    $connection->statement('SET SESSION lock_wait_timeout = 10');
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.connection', $connection);
    $container->instance('db.schema', $connection->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Schema::swap($connection->getSchemaBuilder());

    return $connection;
}

/** @return array{storage: VersionedStorage, evolution: DatabaseStorageSchemaEvolution} */
function storageEvolutionMySqlRuntime(Connection $connection): array
{
    $authorizer = new StorageEvolutionMySqlAuthorizer();
    $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [new StorageEvolutionMySqlAuditSink()]);
    $propertyTypes = PropertyTypeRegistry::builtIns();
    $ownerPolicies = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $ownerPolicies->seal();

    return [
        'storage' => new VersionedStorage($connection, $propertyTypes, $authorizer, $audit),
        'evolution' => new DatabaseStorageSchemaEvolution($connection, $propertyTypes, $authorizer, $audit, $ownerPolicies),
    ];
}

function storageEvolutionMySqlMigration(): object
{
    return require __DIR__ . '/../../database/migrations/2026_07_14_000002_create_larena_storage_schema_migration_tables.php';
}

/** @return list<string> */
function storageEvolutionMySqlExistingMigrationTables(Connection $connection): array
{
    return array_values(array_filter(
        StorageSchemaMigrationTableShapeGuard::tableNames(),
        static fn (string $table): bool => $connection->getSchemaBuilder()->hasTable($table),
    ));
}

function storageEvolutionMySqlProveShapeMatrix(Connection $connection): void
{
    foreach (storageMigrationShapeTableMap() as $tableKey => $table) {
        $variants = array_merge(
            storageMigrationShapeContractVariants($tableKey),
            storageMigrationShapeMySqlContractVariants($tableKey),
        );
        foreach ($variants as $variant) {
            storageMigrationShapeCreateContractVariant($connection, $tableKey, $variant);
            $before = storageMigrationShapeContractSnapshot($connection, $table);
            try {
                storageEvolutionMySqlMigration()->up();
                throw new RuntimeException('storage_mysql_shape_variant_accepted');
            } catch (StorageOwnedTableShapeRejected $exception) {
                storageEvolutionMySqlExpect(
                    $exception->reasonCode === storageMigrationShapeExpectedContractReason($tableKey, $variant),
                    'storage_mysql_shape_reason_mismatch',
                );
                storageEvolutionMySqlExpect($exception->getMessage() === $exception->reasonCode, 'storage_mysql_shape_surface_not_sanitized');
                storageEvolutionMySqlExpect($exception->tableKey === $tableKey, 'storage_mysql_shape_table_key_mismatch');
            }
            storageEvolutionMySqlExpect(
                storageEvolutionMySqlExistingMigrationTables($connection) === [$table],
                'storage_mysql_shape_preflight_executed_partial_ddl',
            );
            storageEvolutionMySqlExpect(
                storageMigrationShapeContractSnapshot($connection, $table) === $before,
                'storage_mysql_shape_rejection_mutated_table',
            );
            $connection->getSchemaBuilder()->drop($table);
        }
    }

    storageEvolutionMySqlMigration()->up();
    $connection->getSchemaBuilder()->drop('larena_storage_schema_migration_result_records');
    $existingBefore = storageEvolutionMySqlExistingMigrationTables($connection);
    $snapshots = [];
    foreach ($existingBefore as $table) {
        $snapshots[$table] = storageMigrationShapeContractSnapshot($connection, $table);
    }
    try {
        storageEvolutionMySqlMigration()->down();
        throw new RuntimeException('storage_mysql_partial_down_accepted');
    } catch (StorageOwnedTableShapeRejected $exception) {
        storageEvolutionMySqlExpect($exception->reasonCode === 'storage_schema_migration_topology_incompatible', 'storage_mysql_partial_down_reason_mismatch');
    }
    storageEvolutionMySqlExpect(storageEvolutionMySqlExistingMigrationTables($connection) === $existingBefore, 'storage_mysql_partial_down_changed_topology');
    foreach ($snapshots as $table => $snapshot) {
        storageEvolutionMySqlExpect(storageMigrationShapeContractSnapshot($connection, $table) === $snapshot, 'storage_mysql_partial_down_mutated_table');
    }
    foreach (array_reverse($existingBefore) as $table) {
        $connection->getSchemaBuilder()->drop($table);
    }
}

/**
 * @return array{schema_id: string, owner_package: string, fields: list<array<string, mixed>>}
 */
function storageEvolutionMySqlDefinition(?string $addedField = null, bool $required = false, array $constraints = []): array
{
    $fields = [
        ['key' => 'title', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => []],
        ['key' => 'zero', 'type' => 'integer', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
        ['key' => 'flag', 'type' => 'boolean', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
        ['key' => 'empty', 'type' => 'string', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
        ['key' => 'nullable', 'type' => 'string', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => []],
    ];
    if ($addedField !== null) {
        $fields[] = [
            'key' => $addedField,
            'type' => 'string',
            'type_version' => 1,
            'required' => $required,
            'visibility' => 'public',
            'constraints' => $constraints,
        ];
    }

    return ['schema_id' => 'storage.mysql.evolution', 'owner_package' => 'larena/storage-acceptance', 'fields' => $fields];
}

/**
 * @param array{driver: string, host: string, port: int, database: string, username: string, password: string, charset: string, collation: string, prefix: string, strict: bool} $config
 */
function storageEvolutionMySqlForkApply(
    array $config,
    string $barrier,
    string $output,
    string $planRef,
    string $planHash,
    string $actor,
): int {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('storage_mysql_fork_failed');
    }
    if ($pid > 0) {
        return $pid;
    }

    $connection = null;
    try {
        $deadline = microtime(true) + 20.0;
        while (!is_file($barrier) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        if (!is_file($barrier)) {
            throw new RuntimeException('storage_mysql_barrier_timeout');
        }
        $connection = storageEvolutionMySqlConnection($config);
        $runtime = storageEvolutionMySqlRuntime($connection);
        try {
            $runtime['evolution']->apply($planRef, $planHash, $actor, 'mysql-concurrent-apply');
            file_put_contents($output, 'applied');
        } catch (StorageRejected $exception) {
            file_put_contents($output, 'rejected:' . $exception->reasonCode);
        }
        $connection->disconnect();
        exit(0);
    } catch (Throwable) {
        if ($connection instanceof Connection) {
            $connection->disconnect();
        }
        file_put_contents($output, 'error');
        exit(2);
    }
}

/** @param array{host: string, port: int, username: string, password: string} $credentials */
function storageEvolutionMySqlRegisterCleanup(
    bool &$cleanupPending,
    bool &$created,
    int $ownerPid,
    string $database,
    string $databaseAllowlist,
    array $credentials,
): void {
    register_shutdown_function(static function () use (
        &$cleanupPending,
        &$created,
        $ownerPid,
        $database,
        $databaseAllowlist,
        $credentials,
    ): void {
        if (getmypid() !== $ownerPid
            || !$cleanupPending
            || !$created
            || preg_match($databaseAllowlist, $database) !== 1) {
            return;
        }
        try {
            $cleanup = storageEvolutionMySqlServer($credentials);
            $cleanup->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        } catch (Throwable) {
            // Synchronous finally cleanup remains authoritative; this is a last-resort fallback.
        }
    });
}

$optIn = getenv('LARENA_STORAGE_SCHEMA_EVOLUTION_MYSQL_TEST');
if (!is_string($optIn) || !filter_var($optIn, FILTER_VALIDATE_BOOL)) {
    echo "StorageSchemaEvolutionMySqlTest skipped (explicit opt-in required).\n";
    exit(0);
}
storageEvolutionMySqlExpect(extension_loaded('pdo_mysql'), 'storage_mysql_pdo_extension_missing');
storageEvolutionMySqlExpect(extension_loaded('pcntl'), 'storage_mysql_pcntl_extension_missing');

$credentials = storageEvolutionMySqlCredentials();
$database = 'larena_storage_evolution_test_' . strtolower(bin2hex(random_bytes(6)));
$databaseAllowlist = '/^larena_storage_evolution_test_[a-f0-9]{12}$/';
storageEvolutionMySqlExpect(preg_match($databaseAllowlist, $database) === 1, 'storage_mysql_database_allowlist_failed');
$config = [
    'driver' => 'mysql',
    'host' => $credentials['host'],
    'port' => $credentials['port'],
    'database' => $database,
    'username' => $credentials['username'],
    'password' => $credentials['password'],
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => true,
];
$ownerPid = getmypid();
$created = false;
$cleanupPending = true;
$connection = null;
$server = storageEvolutionMySqlServer($credentials);
$existingStatement = $server->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
$existingStatement->execute([$database]);
storageEvolutionMySqlExpect((int) $existingStatement->fetchColumn() === 0, 'storage_mysql_refusing_existing_database');

storageEvolutionMySqlRegisterCleanup(
    $cleanupPending,
    $created,
    $ownerPid,
    $database,
    $databaseAllowlist,
    $credentials,
);

$barrier = tempnam(sys_get_temp_dir(), 'larena-storage-mysql-barrier-');
$outputA = tempnam(sys_get_temp_dir(), 'larena-storage-mysql-worker-a-');
$outputB = tempnam(sys_get_temp_dir(), 'larena-storage-mysql-worker-b-');
storageEvolutionMySqlExpect(is_string($barrier) && is_string($outputA) && is_string($outputB), 'storage_mysql_tempfile_failed');
@unlink($barrier);

try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $connection = storageEvolutionMySqlConnection($config);

    storageEvolutionMySqlProveShapeMatrix($connection);

    (require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/2026_07_14_000001_validate_larena_storage_version_table_shapes.php')->up();
    storageEvolutionMySqlMigration()->up();
    storageEvolutionMySqlExpect(
        storageEvolutionMySqlExistingMigrationTables($connection) === StorageSchemaMigrationTableShapeGuard::tableNames(),
        'storage_mysql_clean_install_topology_incomplete',
    );

    storageEvolutionMySqlMigration()->down();
    storageEvolutionMySqlExpect(storageEvolutionMySqlExistingMigrationTables($connection) === [], 'storage_mysql_unused_down_left_tables');
    storageEvolutionMySqlMigration()->up();

    $runtime = storageEvolutionMySqlRuntime($connection);
    $storage = $runtime['storage'];
    $evolution = $runtime['evolution'];
    $v1 = $storage->registerSchemaVersion(storageEvolutionMySqlDefinition(), null, 'user:admin:1', 'mysql-v1');
    storageEvolutionMySqlExpectRejected(
        static fn () => $storage->create(
            'storage:mysql:null',
            $v1->ref,
            ['title' => null],
            'user:admin:1',
            'mysql-null-rejected',
        ),
        'storage_record_field_invalid',
    );
    storageEvolutionMySqlExpect($connection->table('larena_storage_records')->count() === 0, 'storage_mysql_null_rejection_mutated_records');

    $exactValues = ['title' => 'Привет 🌍', 'zero' => 0, 'flag' => false, 'empty' => ''];
    $recordV1 = $storage->create('storage:mysql:evolution-1', $v1->ref, $exactValues, 'user:admin:1', 'mysql-record-v1')->version;
    storageEvolutionMySqlExpect($recordV1->values === $exactValues, 'storage_mysql_v1_values_not_strict');

    $requiredDefinition = storageEvolutionMySqlDefinition('required_new', true);
    $requiredReport = $evolution->analyze($v1->ref, $requiredDefinition, 'user:admin:1', 'mysql-required-analyze');
    storageEvolutionMySqlExpect(!$requiredReport->compatible, 'storage_mysql_required_addition_accepted');
    storageEvolutionMySqlExpect(
        in_array('storage_schema_migration_required_field_added', $requiredReport->reasonCodes, true),
        'storage_mysql_required_addition_reason_missing',
    );
    storageEvolutionMySqlExpectRejected(
        static fn () => $evolution->plan($v1->ref, $requiredDefinition, 'user:admin:1', 'mysql-required-plan'),
        'storage_schema_migration_required_field_added',
    );

    $constrainedDefinition = storageEvolutionMySqlDefinition('constrained_new', false, ['max_length' => 10]);
    $constrainedReport = $evolution->analyze($v1->ref, $constrainedDefinition, 'user:admin:1', 'mysql-constrained-analyze');
    storageEvolutionMySqlExpect(!$constrainedReport->compatible, 'storage_mysql_constrained_addition_accepted');
    storageEvolutionMySqlExpect(
        in_array('storage_schema_migration_added_field_constraints_unsupported', $constrainedReport->reasonCodes, true),
        'storage_mysql_constrained_addition_reason_missing',
    );
    storageEvolutionMySqlExpectRejected(
        static fn () => $evolution->plan($v1->ref, $constrainedDefinition, 'user:admin:1', 'mysql-constrained-plan'),
        'storage_schema_migration_added_field_constraints_unsupported',
    );
    storageEvolutionMySqlExpect($connection->table('larena_storage_schema_migration_plans')->count() === 0, 'storage_mysql_incompatible_plan_persisted');

    $targetDefinition = storageEvolutionMySqlDefinition('subtitle');
    $plan = $evolution->plan($v1->ref, $targetDefinition, 'user:admin:1', 'mysql-plan-v2');
    $v1StoredJson = (string) $connection->table('larena_storage_record_versions')
        ->where('record_id', $recordV1->ref->recordId)
        ->where('revision', 1)
        ->value('values_json');
    $v1ContentHash = $recordV1->contentHash;

    $connection->disconnect();
    $connection = storageEvolutionMySqlConnection($config);
    $restarted = storageEvolutionMySqlRuntime($connection);
    storageEvolutionMySqlExpect(
        $restarted['evolution']->explain($plan->planRef, 'user:admin:1')->planHash === $plan->planHash,
        'storage_mysql_restart_plan_not_recoverable',
    );
    $connection->disconnect();
    $connection = null;
    $server = null;
    Facade::clearResolvedInstances();
    gc_collect_cycles();

    $pidA = storageEvolutionMySqlForkApply($config, $barrier, $outputA, $plan->planRef, $plan->planHash, 'user:worker:a');
    $pidB = storageEvolutionMySqlForkApply($config, $barrier, $outputB, $plan->planRef, $plan->planHash, 'user:worker:b');
    storageEvolutionMySqlExpect(file_put_contents($barrier, 'go') !== false, 'storage_mysql_barrier_create_failed');
    pcntl_waitpid($pidA, $statusA);
    pcntl_waitpid($pidB, $statusB);
    storageEvolutionMySqlExpect(pcntl_wifexited($statusA) && pcntl_wexitstatus($statusA) === 0, 'storage_mysql_worker_a_failed');
    storageEvolutionMySqlExpect(pcntl_wifexited($statusB) && pcntl_wexitstatus($statusB) === 0, 'storage_mysql_worker_b_failed');
    $outcomes = [trim((string) file_get_contents($outputA)), trim((string) file_get_contents($outputB))];
    storageEvolutionMySqlExpect(
        count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'applied')) === 1,
        'storage_mysql_concurrent_winner_count_invalid',
    );
    $losers = array_values(array_filter($outcomes, static fn (string $outcome): bool => str_starts_with($outcome, 'rejected:')));
    storageEvolutionMySqlExpect(count($losers) === 1, 'storage_mysql_concurrent_loser_not_rejected');
    storageEvolutionMySqlExpect(
        in_array(substr($losers[0], strlen('rejected:')), [
            'storage_schema_migration_plan_already_applied',
            'storage_schema_migration_schema_head_stale',
            'storage_schema_migration_conflict',
        ], true),
        'storage_mysql_concurrent_loser_reason_unexpected',
    );

    $server = storageEvolutionMySqlServer($credentials);
    $connection = storageEvolutionMySqlConnection($config);
    $restarted = storageEvolutionMySqlRuntime($connection);
    $recordV2 = $restarted['storage']->readAdminCurrentVersion(
        'storage.mysql.evolution',
        'storage:mysql:evolution-1',
        'user:admin:1',
    );
    storageEvolutionMySqlExpect($recordV2 !== null, 'storage_mysql_record_missing_after_restart');
    if ($recordV2 === null) {
        throw new RuntimeException('storage_mysql_record_missing_after_restart');
    }
    $canonicalV1Values = json_decode($v1StoredJson, true, 512, JSON_THROW_ON_ERROR);
    storageEvolutionMySqlExpect(is_array($canonicalV1Values), 'storage_mysql_v1_json_invalid');
    storageEvolutionMySqlExpect($recordV2->ref->revision === 2, 'storage_mysql_record_revision_not_advanced');
    storageEvolutionMySqlExpect($recordV2->schema->version === 2, 'storage_mysql_schema_version_not_advanced');
    storageEvolutionMySqlExpect($recordV2->operation === 'schema_migration', 'storage_mysql_record_operation_invalid');
    storageEvolutionMySqlExpect($recordV2->values === $canonicalV1Values, 'storage_mysql_values_not_strictly_preserved');
    storageEvolutionMySqlExpect(($recordV2->values['zero'] ?? null) === 0, 'storage_mysql_zero_changed');
    storageEvolutionMySqlExpect(array_key_exists('flag', $recordV2->values) && $recordV2->values['flag'] === false, 'storage_mysql_false_changed');
    storageEvolutionMySqlExpect(($recordV2->values['empty'] ?? null) === '', 'storage_mysql_empty_string_changed');
    storageEvolutionMySqlExpect(($recordV2->values['title'] ?? null) === 'Привет 🌍', 'storage_mysql_unicode_changed');
    storageEvolutionMySqlExpect(!array_key_exists('nullable', $recordV2->values), 'storage_mysql_absent_source_optional_materialized');
    storageEvolutionMySqlExpect(!array_key_exists('subtitle', $recordV2->values), 'storage_mysql_new_optional_materialized');
    storageEvolutionMySqlExpect($recordV2->contentHash === $v1ContentHash, 'storage_mysql_content_hash_changed');
    $v2StoredJson = (string) $connection->table('larena_storage_record_versions')
        ->where('record_id', $recordV2->ref->recordId)
        ->where('revision', 2)
        ->value('values_json');
    storageEvolutionMySqlExpect($v2StoredJson === $v1StoredJson, 'storage_mysql_canonical_json_changed');
    storageEvolutionMySqlExpect($connection->table('larena_storage_schema_migration_results')->count() === 1, 'storage_mysql_result_count_invalid');
    storageEvolutionMySqlExpect($connection->table('larena_storage_schema_versions')->where('schema_id', 'storage.mysql.evolution')->count() === 2, 'storage_mysql_schema_version_count_invalid');

    $snapshots = [];
    foreach (StorageSchemaMigrationTableShapeGuard::tableNames() as $table) {
        $snapshots[$table] = storageMigrationShapeContractSnapshot($connection, $table);
    }
    try {
        storageEvolutionMySqlMigration()->down();
        throw new RuntimeException('storage_mysql_used_down_accepted');
    } catch (StorageOwnedTableShapeRejected $exception) {
        storageEvolutionMySqlExpect($exception->reasonCode === 'storage_schema_migration_rollback_would_lose_data', 'storage_mysql_used_down_reason_mismatch');
    }
    storageEvolutionMySqlExpect(
        storageEvolutionMySqlExistingMigrationTables($connection) === StorageSchemaMigrationTableShapeGuard::tableNames(),
        'storage_mysql_used_down_changed_topology',
    );
    foreach ($snapshots as $table => $snapshot) {
        storageEvolutionMySqlExpect(storageMigrationShapeContractSnapshot($connection, $table) === $snapshot, 'storage_mysql_used_down_mutated_table');
    }
} finally {
    if ($connection instanceof Connection) {
        $connection->disconnect();
    }
    Facade::clearResolvedInstances();
    if (!$server instanceof PDO) {
        $server = storageEvolutionMySqlServer($credentials);
    }
    if ($created) {
        storageEvolutionMySqlExpect(preg_match($databaseAllowlist, $database) === 1, 'storage_mysql_cleanup_allowlist_failed');
        $server->exec('DROP DATABASE `' . $database . '`');
        $remainingStatement = $server->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $remainingStatement->execute([$database]);
        $remaining = (int) $remainingStatement->fetchColumn();
        $cleanupPending = $remaining !== 0;
        storageEvolutionMySqlExpect($remaining === 0, 'storage_mysql_cleanup_remaining_nonzero');
    } else {
        $cleanupPending = false;
    }
    foreach ([$barrier, $outputA, $outputB] as $file) {
        @unlink($file);
    }
}

echo "StorageSchemaEvolutionMySqlTest passed.\n";
