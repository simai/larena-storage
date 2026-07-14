<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;

require_once __DIR__ . '/StorageSchemaEvolutionTest.php';

function storageEvolutionConcurrencyConnection(string $path): Connection
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

function storageEvolutionForkApply(
    string $databasePath,
    string $barrierPath,
    string $outputPath,
    string $planRef,
    string $planHash,
    string $actor,
): int {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('storage_evolution_fork_failed');
    }
    if ($pid > 0) {
        return $pid;
    }

    try {
        $deadline = microtime(true) + 10.0;
        while (!is_file($barrierPath) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        if (!is_file($barrierPath)) {
            throw new RuntimeException('storage_evolution_barrier_timeout');
        }
        $connection = storageEvolutionConcurrencyConnection($databasePath);
        $authorizer = new StorageEvolutionRecordingAuthorizer();
        $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [new StorageEvolutionRecordingAuditSink()]);
        $evolution = new DatabaseStorageSchemaEvolution(
            $connection,
            PropertyTypeRegistry::builtIns(),
            $authorizer,
            $audit,
            storageEvolutionOwnerPolicies(),
        );
        try {
            $evolution->apply($planRef, $planHash, $actor, 'concurrent-apply');
            file_put_contents($outputPath, 'applied');
        } catch (StorageRejected $exception) {
            file_put_contents($outputPath, 'rejected:' . $exception->reasonCode);
        }
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($outputPath, 'error:' . $exception::class . ':' . $exception->getMessage());
        exit(2);
    }
}

/**
 * @param list<int> $pids
 * @return array<int, int>
 */
function storageEvolutionWaitForChildren(array $pids, float $timeoutSeconds): array
{
    if (!function_exists('posix_kill')) {
        throw new RuntimeException('storage_evolution_posix_kill_required');
    }

    $remaining = array_fill_keys($pids, true);
    $statuses = [];
    $failure = null;
    $deadline = microtime(true) + $timeoutSeconds;
    while ($remaining !== [] && microtime(true) < $deadline) {
        foreach (array_keys($remaining) as $pid) {
            $status = 0;
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                $statuses[$pid] = $status;
                unset($remaining[$pid]);
            } elseif ($waited === -1) {
                $error = pcntl_get_last_error();
                if (defined('PCNTL_EINTR') && $error === constant('PCNTL_EINTR')) {
                    continue;
                }
                if (defined('PCNTL_ECHILD') && $error === constant('PCNTL_ECHILD')) {
                    unset($remaining[$pid]);
                    continue;
                }
                $failure = 'storage_evolution_wait_failed';
                break 2;
            }
        }
        if ($remaining !== []) {
            usleep(20_000);
        }
    }

    if ($remaining !== [] && $failure === null) {
        $failure = 'storage_evolution_children_timeout';
    }

    foreach (array_keys($remaining) as $pid) {
        @posix_kill($pid, SIGTERM);
    }
    $terminateDeadline = microtime(true) + 1.0;
    while ($remaining !== [] && microtime(true) < $terminateDeadline) {
        foreach (array_keys($remaining) as $pid) {
            $status = 0;
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid) {
                $statuses[$pid] = $status;
                unset($remaining[$pid]);
            } elseif ($waited === -1
                && defined('PCNTL_ECHILD')
                && pcntl_get_last_error() === constant('PCNTL_ECHILD')) {
                unset($remaining[$pid]);
            }
        }
        if ($remaining !== []) {
            usleep(20_000);
        }
    }
    foreach (array_keys($remaining) as $pid) {
        @posix_kill($pid, SIGKILL);
    }
    foreach (array_keys($remaining) as $pid) {
        while (true) {
            $status = 0;
            $waited = pcntl_waitpid($pid, $status);
            if ($waited === $pid) {
                $statuses[$pid] = $status;
                unset($remaining[$pid]);
                break;
            }
            if ($waited === -1
                && defined('PCNTL_EINTR')
                && pcntl_get_last_error() === constant('PCNTL_EINTR')) {
                continue;
            }
            $failure ??= 'storage_evolution_reap_failed';
            unset($remaining[$pid]);
            break;
        }
    }

    if ($failure !== null) {
        throw new RuntimeException($failure);
    }

    return $statuses;
}

function storageEvolutionAssertTimeoutCleanup(): void
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('storage_evolution_timeout_probe_fork_failed');
    }
    if ($pid === 0) {
        usleep(5_000_000);
        exit(0);
    }

    $startedAt = microtime(true);
    $failure = null;
    try {
        storageEvolutionWaitForChildren([$pid], 0.05);
    } catch (RuntimeException $exception) {
        $failure = $exception->getMessage();
    }
    storageEvolutionExpect($failure === 'storage_evolution_children_timeout', 'timeout probe did not fail with the bounded reason');
    storageEvolutionExpect(microtime(true) - $startedAt < 3.0, 'timeout probe exceeded the parent hard deadline');
    $status = 0;
    do {
        $waited = pcntl_waitpid($pid, $status, WNOHANG);
    } while ($waited === -1
        && defined('PCNTL_EINTR')
        && pcntl_get_last_error() === constant('PCNTL_EINTR'));
    storageEvolutionExpect(
        $waited === -1
            && defined('PCNTL_ECHILD')
            && pcntl_get_last_error() === constant('PCNTL_ECHILD'),
        'timeout probe child was not reaped',
    );
}

storageEvolutionAssertTimeoutCleanup();

$opened = storageEvolutionOpen();
$barrier = tempnam(sys_get_temp_dir(), 'larena-storage-barrier-');
$outputA = tempnam(sys_get_temp_dir(), 'larena-storage-worker-a-');
$outputB = tempnam(sys_get_temp_dir(), 'larena-storage-worker-b-');
if (!is_string($barrier) || !is_string($outputA) || !is_string($outputB)) {
    throw new RuntimeException('storage_evolution_concurrency_tempfile_failed');
}
@unlink($barrier);

try {
    $authorizer = new StorageEvolutionRecordingAuthorizer();
    $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [new StorageEvolutionRecordingAuditSink()]);
    $propertyTypes = PropertyTypeRegistry::builtIns();
    $storage = new VersionedStorage($opened['connection'], $propertyTypes, $authorizer, $audit);
    $evolution = new DatabaseStorageSchemaEvolution(
        $opened['connection'],
        $propertyTypes,
        $authorizer,
        $audit,
        storageEvolutionOwnerPolicies(),
    );
    $v1 = $storage->registerSchemaVersion(storageEvolutionDefinition(), null, 'user:admin:1', 'concurrency-v1');
    $storage->create(
        'docara:page:concurrent-1',
        $v1->ref,
        ['title' => 'Concurrent', 'zero' => 0, 'flag' => false, 'empty' => ''],
        'user:admin:1',
        'concurrency-record',
    );
    $plan = $evolution->plan($v1->ref, storageEvolutionDefinition(true), 'user:admin:1', 'concurrency-plan');

    $pidA = storageEvolutionForkApply($opened['path'], $barrier, $outputA, $plan->planRef, $plan->planHash, 'user:worker:a');
    $pidB = storageEvolutionForkApply($opened['path'], $barrier, $outputB, $plan->planRef, $plan->planHash, 'user:worker:b');
    if (file_put_contents($barrier, 'go') === false) {
        throw new RuntimeException('storage_evolution_barrier_create_failed');
    }
    $statuses = storageEvolutionWaitForChildren([$pidA, $pidB], 20.0);
    $statusA = $statuses[$pidA];
    $statusB = $statuses[$pidB];
    storageEvolutionExpect(pcntl_wifexited($statusA) && pcntl_wexitstatus($statusA) === 0, 'worker A crashed');
    storageEvolutionExpect(pcntl_wifexited($statusB) && pcntl_wexitstatus($statusB) === 0, 'worker B crashed');
    $outcomes = [trim((string) file_get_contents($outputA)), trim((string) file_get_contents($outputB))];
    storageEvolutionExpect(count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'applied')) === 1, 'concurrent apply did not produce exactly one winner: ' . implode(',', $outcomes));
    storageEvolutionExpect(
        count(array_filter($outcomes, static fn (string $outcome): bool => str_starts_with($outcome, 'rejected:'))) === 1,
        'concurrent loser did not fail closed: ' . implode(',', $outcomes),
    );
    storageEvolutionExpect($opened['connection']->table('larena_storage_schema_migration_results')->count() === 1, 'concurrent apply persisted multiple results');
    storageEvolutionExpect($opened['connection']->table('larena_storage_schemas')->value('current_version') === 2, 'concurrent apply schema head mismatch');
    storageEvolutionExpect($opened['connection']->table('larena_storage_records')->value('current_revision') === 2, 'concurrent apply record head mismatch');
} finally {
    Facade::clearResolvedInstances();
    foreach ([$opened['path'], $opened['path'] . '-wal', $opened['path'] . '-shm', $opened['path'] . '-journal', $barrier, $outputA, $outputB] as $file) {
        @unlink($file);
    }
}

echo "StorageSchemaEvolutionConcurrencyTest passed.\n";
