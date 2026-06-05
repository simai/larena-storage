<?php

declare(strict_types=1);

use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\MutationType;
use Larena\Storage\Enums\StorageDecisionStatus;
use Larena\Storage\Runtime\ArrayStorageMutation;
use Larena\Storage\Runtime\ArrayStorageQuery;
use Larena\Storage\Runtime\ArrayStorageSchema;
use Larena\Storage\Runtime\AuditAwareStorageMutationRuntime;
use Larena\Storage\Runtime\InMemoryStorageRuntime;
use Larena\Storage\Runtime\LaravelDatabaseStorageAdapter;

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

$sink = new InMemoryAuditSink();
$runtime = new AuditAwareStorageMutationRuntime(
    $persistence,
    new AuditEventPipeline(new DefaultAuditRedactor(), [$sink]),
);
$decision = $runtime->mutate(new ArrayStorageMutation('articles', 'invalid', MutationType::Create, 'scope:articles', []), 'actor-1');

if ($decision !== StorageDecisionStatus::InvalidPayload) {
    fwrite(STDERR, "Invalid storage payload must fail before persistence.\n");
    exit(1);
}

if ($storageRuntime->records(new ArrayStorageQuery('articles', 'scope:articles')) !== []) {
    fwrite(STDERR, "Invalid storage payload must not persist records.\n");
    exit(1);
}

if ($sink->events() !== []) {
    fwrite(STDERR, "Invalid storage payload must not emit success audit event.\n");
    exit(1);
}

echo "StoragePersistenceFailClosedTest passed.\n";
