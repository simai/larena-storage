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
use Larena\Storage\Runtime\PdoLocalDevStorageAdapter;
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

$adapter = PdoLocalDevStorageAdapter::inMemorySqlite('testing');
$profile = PdoLocalDevStorageAdapter::localDevProfile();
$preflight = $adapter->preflight();

assert(($preflight['status'] ?? null) === 'passed');
assert(($preflight['driver'] ?? null) === 'sqlite');
assert(($preflight['database'] ?? null) === ':memory:');
assert(($preflight['disposable_database'] ?? false) === true);
assert(($preflight['write_allowed_after_preflight'] ?? false) === true);
assert(($preflight['filesystem_storage_write_attempted'] ?? true) === false);
assert($adapter->supports($profile));

$schema = new ArrayStorageSchema('cms.public_content_link', 'local-dev-test', 'cms.public_content_link.write', $profile->id(), [
    ['name' => 'title', 'type' => 'string', 'required' => true, 'visibility' => FieldVisibility::Public->value],
    ['name' => 'slug', 'type' => 'string', 'required' => true, 'visibility' => FieldVisibility::Public->value],
    ['name' => 'status', 'type' => 'string', 'required' => true, 'visibility' => FieldVisibility::Public->value],
    ['name' => 'operator_note_private', 'type' => 'string', 'required' => false, 'visibility' => FieldVisibility::Hidden->value],
    ['name' => 'hidden_secret_probe', 'type' => 'string', 'required' => false, 'visibility' => FieldVisibility::Encrypted->value],
]);

$schemaValidation = $adapter->registerSchema($schema);
assert($schemaValidation->isValid());

$create = new ArrayStorageMutation('cms.public_content_link', 'link-1', MutationType::Create, 'cms.public_content_link.create', [
    'title' => 'Local dev persisted link',
    'slug' => 'local-dev-persisted-link',
    'status' => 'Draft',
    'operator_note_private' => 'must not leak',
    'hidden_secret_probe' => 'secret probe',
]);
$update = new ArrayStorageMutation('cms.public_content_link', 'link-1', MutationType::Update, 'cms.public_content_link.update', [
    'title' => 'Local dev persisted link updated',
    'slug' => 'local-dev-persisted-link',
    'status' => 'Published',
    'operator_note_private' => 'must not leak update',
    'hidden_secret_probe' => 'secret probe update',
]);
$delete = new ArrayStorageMutation('cms.public_content_link', 'link-1', MutationType::Delete, 'cms.public_content_link.delete');

assert($adapter->mutate($create) === StorageDecisionStatus::Allowed);
$afterCreate = $adapter->records(new ArrayStorageQuery('cms.public_content_link', 'cms.public_content_link.read', ['slug' => 'local-dev-persisted-link']));
assert(count($afterCreate) === 1);
assert(($afterCreate[0]->projection()['title'] ?? null) === 'Local dev persisted link');
assert(($afterCreate[0]->projection()['operator_note_private'] ?? null) === '[redacted]');
assert(($afterCreate[0]->projection()['hidden_secret_probe'] ?? null) === '[redacted]');

assert($adapter->mutate($update) === StorageDecisionStatus::Allowed);
$afterUpdate = $adapter->records(new ArrayStorageQuery('cms.public_content_link', 'cms.public_content_link.read', ['slug' => 'local-dev-persisted-link']));
assert(count($afterUpdate) === 1);
assert(($afterUpdate[0]->projection()['title'] ?? null) === 'Local dev persisted link updated');
assert(($afterUpdate[0]->projection()['status'] ?? null) === 'Published');

assert($adapter->mutate($delete) === StorageDecisionStatus::Allowed);
assert($adapter->recordCount('cms.public_content_link') === 0);

$rollbackAdapter = PdoLocalDevStorageAdapter::inMemorySqlite('testing');
$rollbackAdapter->registerSchema($schema);
$trace = [];
$transaction = new StorageTransactionBoundary(function (Closure $operation) use ($rollbackAdapter, &$trace): mixed {
    $trace[] = 'begin';
    $rollbackAdapter->beginTransaction();
    try {
        $result = $operation();
        $rollbackAdapter->commit();
        $trace[] = 'commit';

        return $result;
    } catch (Throwable $throwable) {
        $rollbackAdapter->rollBack();
        $trace[] = 'rollback';

        throw $throwable;
    }
});
$runtime = new AuditAwareStorageMutationRuntime(
    $rollbackAdapter,
    new AuditEventPipeline(new DefaultAuditRedactor(), [new InMemoryAuditSink(false)]),
    $transaction,
);

try {
    $runtime->mutate($create, 'local-dev-admin-operator', ['correlation_id' => 'batch-13-audit-failure']);
    fwrite(STDERR, "Audit routing failure must throw and roll back the local-dev DB mutation.\n");
    exit(1);
} catch (Throwable) {
    // Expected.
}

assert($trace === ['begin', 'rollback']);
assert($rollbackAdapter->recordCount('cms.public_content_link') === 0);

$failureAdapter = PdoLocalDevStorageAdapter::inMemorySqlite('testing');
$failureAdapter->registerSchema($schema);
$failureAdapter->failNextMutation();
try {
    $failureAdapter->mutate($create);
    fwrite(STDERR, "Simulated adapter failure must throw before false success.\n");
    exit(1);
} catch (Throwable $throwable) {
    assert($throwable->getMessage() === 'local_dev_storage_adapter_failure_simulated');
}
assert($failureAdapter->recordCount('cms.public_content_link') === 0);

echo "PdoLocalDevStorageAdapterTest passed.\n";
