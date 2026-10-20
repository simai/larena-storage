<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Runtime\QueueControlService;
use Larena\Queue\Storage\DatabaseQueueStore;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\StorageViewRebuildJob;
use Larena\Storage\View\StorageViewRebuildQueue;
use Larena\Storage\View\StorageViewSync;

/** A queue clock the test moves by hand. */
final class StorageViewTestClock implements QueueClock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->add(new DateInterval('PT' . $seconds . 'S'));
    }
}

/** @return array{connection: Connection, manager: DatabaseManager, path: string} */
function storageViewQueueOpen(): array
{
    $path = tempnam(sys_get_temp_dir(), 'larena-storage-view-queue-');
    if (!is_string($path)) {
        throw new RuntimeException('storage_view_tempfile_failed');
    }
    $container = new Container();
    $capsule = new Capsule($container);
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
    $capsule->setAsGlobal();
    $connection = $capsule->getConnection();
    $container->instance('db', $capsule->getDatabaseManager());
    $container->instance('db.schema', $connection->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Schema::swap($connection->getSchemaBuilder());

    return ['connection' => $connection, 'manager' => $capsule->getDatabaseManager(), 'path' => $path];
}

/** The storage migrations the витрина needs and the queue's tables. */
function storageViewQueueInstall(Connection $connection): void
{
    storageViewInstall($connection);
    (require __DIR__ . '/../../vendor/larena/queue/database/migrations/2026_07_26_000000_create_larena_queue_tables.php')->up();
}

/**
 * A durable queue on the connection with the storage.view.rebuild job registered.
 *
 * @return array{registry: JobTypeRegistry, store: DatabaseQueueStore, dispatcher: DurableQueueDispatcher, worker: DurableQueueWorker, control: QueueControlService, rebuilds: StorageViewRebuildQueue}
 */
function storageViewQueue(Connection $connection, StorageViewTestClock $clock, StorageViewSync $views, StorageMetrics $metrics): array
{
    $registry = new JobTypeRegistry();
    $registry->register(StorageViewRebuildJob::descriptor(), new StorageViewRebuildJob($views, $metrics));
    $store = new DatabaseQueueStore($connection);
    $dispatcher = new DurableQueueDispatcher($registry, $store, $clock);

    return [
        'registry' => $registry,
        'store' => $store,
        'dispatcher' => $dispatcher,
        'worker' => new DurableQueueWorker($registry, $store, $clock),
        'control' => new QueueControlService($store, $clock),
        'rebuilds' => new StorageViewRebuildQueue($views, $registry, $dispatcher, $clock),
    ];
}

/**
 * The витрина rows of a table, keyed by record id, as plain arrays, and its side rows sorted.
 *
 * @return array{rows: array<string, array<string, mixed>>, multi: list<string>}
 */
function storageViewSnapshot(Connection $connection, string $table): array
{
    $rows = [];
    foreach ($connection->table($table)->orderBy('record_id')->get() as $row) {
        $rows[(string) $row->record_id] = (array) $row;
    }
    $multi = [];
    foreach ($connection->table($table . '_m')->get() as $row) {
        $multi[] = $row->record_id . '|' . $row->field_key . '|' . $row->value;
    }
    sort($multi);

    return ['rows' => $rows, 'multi' => $multi];
}
