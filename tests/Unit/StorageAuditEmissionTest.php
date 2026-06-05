<?php

declare(strict_types=1);

use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Audit\Runtime\InMemoryAuditSink;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\MutationType;
use Larena\Storage\Enums\StorageDecisionStatus;
use Larena\Storage\Runtime\ArrayStorageMutation;
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

$persistence = new LaravelDatabaseStorageAdapter(new InMemoryStorageRuntime());
$persistence->registerSchema(new ArrayStorageSchema('articles', '1.0.0', 'access.storage.articles', 'laravel_database_default', [
    ['name' => 'title', 'type' => 'string', 'required' => true, 'visibility' => FieldVisibility::Public->value],
    ['name' => 'secret_note', 'type' => 'string', 'required' => false, 'visibility' => FieldVisibility::Hidden->value],
]));

$sink = new InMemoryAuditSink();
$runtime = new AuditAwareStorageMutationRuntime(
    $persistence,
    new AuditEventPipeline(new DefaultAuditRedactor(), [$sink]),
);
$decision = $runtime->mutate(new ArrayStorageMutation('articles', 'a-1', MutationType::Create, 'scope:articles', [
    'title' => 'Hello',
    'secret_note' => 'never leak',
]), 'actor-1', ['correlation_id' => 'corr-1']);

if ($decision !== StorageDecisionStatus::Allowed || count($sink->events()) !== 1) {
    fwrite(STDERR, "Successful storage mutation must emit one audit event.\n");
    exit(1);
}

$event = $sink->events()[0];
if ($event->type !== 'storage.record_created' || $event->correlationId !== 'corr-1') {
    fwrite(STDERR, "Storage audit event type and correlation id are invalid.\n");
    exit(1);
}

$payload = json_encode($event->payload, JSON_THROW_ON_ERROR);
if (str_contains($payload, 'never leak') || str_contains($payload, 'raw_payload')) {
    fwrite(STDERR, "Storage audit payload must not contain raw hidden/private values.\n");
    exit(1);
}

if (($event->payload['payload_redacted'] ?? null) !== true || !in_array('secret_note', $event->payload['payload_keys'] ?? [], true)) {
    fwrite(STDERR, "Storage audit payload must expose metadata only and mark payload as redacted.\n");
    exit(1);
}

echo "StorageAuditEmissionTest passed.\n";
