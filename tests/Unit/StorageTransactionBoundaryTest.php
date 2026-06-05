<?php

declare(strict_types=1);

use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\MutationType;
use Larena\Storage\Runtime\ArrayStorageMutation;
use Larena\Storage\Runtime\ArrayStorageSchema;
use Larena\Storage\Runtime\AuditAwareStorageMutationRuntime;
use Larena\Storage\Runtime\InMemoryStorageRuntime;
use Larena\Storage\Runtime\LaravelDatabaseStorageAdapter;
use Larena\Storage\Runtime\StorageTransactionBoundary;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../../audit/src/Enums/AuditRetentionClass.php';
require_once __DIR__ . '/../../../audit/src/Enums/AuditSeverity.php';
require_once __DIR__ . '/../../../audit/src/Contracts/AuditEvent.php';
require_once __DIR__ . '/../../../audit/src/Contracts/AuditEventDescriptor.php';
require_once __DIR__ . '/../../../audit/src/Contracts/AuditRedactor.php';
require_once __DIR__ . '/../../../audit/src/Contracts/AuditSink.php';
require_once __DIR__ . '/../../../audit/src/Runtime/DefaultAuditRedactor.php';
require_once __DIR__ . '/../../../audit/src/Runtime/InMemoryAuditSink.php';
require_once __DIR__ . '/../../../audit/src/Runtime/AuditEventPipeline.php';

$storageRuntime = new InMemoryStorageRuntime();
$persistence = new LaravelDatabaseStorageAdapter($storageRuntime);
$persistence->registerSchema(new ArrayStorageSchema('articles', '1.0.0', 'access.storage.articles', 'laravel_database_default', [
    ['name' => 'title', 'type' => 'string', 'required' => true, 'visibility' => FieldVisibility::Public->value],
]));

$trace = [];
$transaction = new StorageTransactionBoundary(function (Closure $operation) use (&$trace): mixed {
    $trace[] = 'begin';
    try {
        $result = $operation();
        $trace[] = 'commit';
        return $result;
    } catch (Throwable $throwable) {
        $trace[] = 'rollback';
        throw $throwable;
    }
});
$runtime = new AuditAwareStorageMutationRuntime(
    $persistence,
    new AuditEventPipeline(new DefaultAuditRedactor(), [new InMemoryAuditSink(false)]),
    $transaction,
);

try {
    $runtime->mutate(new ArrayStorageMutation('articles', 'a-1', MutationType::Create, 'scope:articles', [
        'title' => 'Hello',
    ]), 'actor-1');
    fwrite(STDERR, "Required audit routing failure must reject the storage mutation transaction.\n");
    exit(1);
} catch (Throwable) {
    // Expected: no sink accepts the descriptor, so the transaction runner records rollback.
}

if ($trace !== ['begin', 'rollback']) {
    fwrite(STDERR, "Storage transaction boundary must observe rollback on required audit failure.\n");
    exit(1);
}

echo "StorageTransactionBoundaryTest passed.\n";
