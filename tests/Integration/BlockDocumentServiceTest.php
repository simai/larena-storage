<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\BlockDocuments\BlockDocumentAuthorization;
use Larena\Storage\BlockDocuments\BlockDocumentFileInspection;
use Larena\Storage\BlockDocuments\BlockDocumentFileInspector;
use Larena\Storage\BlockDocuments\BlockDocumentNormalizer;
use Larena\Storage\BlockDocuments\BlockDocumentRejected;
use Larena\Storage\BlockDocuments\StorageBackedBlockDocumentService;
use Larena\Storage\BlockDocuments\UnavailableBlockDocumentFileInspector;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\VersionedStorage;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Block documents after the move from larena/content.
 *
 * The behaviour is the one Content shipped — versioned, sanitized, compare-and-swap
 * on update, a deterministic projection — and the two things the move changed are
 * asserted on purpose: a fresh schema is owned by larena/storage, and a schema an
 * earlier installation recorded as larena/content still opens.
 */
function block_document_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return string the reason code, or '' when nothing was refused */
function block_document_refusal(callable $call): string
{
    try {
        $call();
    } catch (BlockDocumentRejected $rejected) {
        return $rejected->reasonCode();
    } catch (StorageRejected $rejected) {
        return $rejected->reasonCode;
    }

    return '';
}

/** @return array<string, mixed> */
function block_document_fixture(): array
{
    return [
        'schema' => 'larena.content.block_document',
        'schema_version' => 0,
        'document_id' => 'page.home',
        'scope_ref' => 'scope:global',
        'registry' => 'larena.content.blocks',
        'version' => '2.28.2',
        'time' => 1_786_147_200_000,
        'blocks' => [
            ['id' => 'paragraph_1', 'type' => 'paragraph', 'data_version' => 0, 'data' => ['body' => 'Hello <b>world</b>']],
            ['id' => 'image_1', 'type' => 'image', 'data_version' => 0, 'data' => ['file_ref' => '550e8400-e29b-41d4-a716-446655440000', 'caption' => 'Image', 'alt' => 'Safe']],
        ],
    ];
}

/** @return array<string, mixed> */
function block_document_text_only(): array
{
    $document = block_document_fixture();
    array_pop($document['blocks']);

    return $document;
}

final class BlockDocumentAllowAll implements ActorOperationAuthorizer, BlockDocumentAuthorization
{
    public function assertAllowed(string $actor, string $operation, string $scopeRef = ''): void
    {
    }

    public function assertLogicalFileAllowed(string $actor, string $scopeRef, string $logicalFileRef): void
    {
    }
}

final readonly class BlockDocumentDenyAll implements BlockDocumentAuthorization
{
    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        throw new BlockDocumentRejected('content_document_scope_denied');
    }

    public function assertLogicalFileAllowed(string $actor, string $scopeRef, string $logicalFileRef): void
    {
        throw new BlockDocumentRejected('content_document_scope_denied');
    }
}

final readonly class BlockDocumentAttachableFiles implements BlockDocumentFileInspector
{
    public function inspect(string $logicalFileRef): BlockDocumentFileInspection
    {
        return new BlockDocumentFileInspection($logicalFileRef, true);
    }
}

final class BlockDocumentNullAuditSink implements AuditSink
{
    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
    }
}

$path = tempnam(sys_get_temp_dir(), 'block-documents-') . '.sqlite';
file_put_contents($path, '');
$container = new Container();
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.connection', $connection);
$container->instance('db.schema', $connection->getSchemaBuilder());
Facade::clearResolvedInstances();
Schema::swap($connection->getSchemaBuilder());
(require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();

$allow = new BlockDocumentAllowAll();
$storage = new VersionedStorage(
    $connection,
    PropertyTypeRegistry::builtIns(),
    $allow,
    new AuditEventPipeline(new DefaultAuditRedactor(), [new BlockDocumentNullAuditSink()]),
);
$service = new StorageBackedBlockDocumentService($storage, $allow, new BlockDocumentAttachableFiles());

// A fresh installation owns the schema as larena/storage.
$service->installSchema('actor:installer');
$owner = $connection->table('larena_storage_schema_versions')
    ->where('schema_id', StorageBackedBlockDocumentService::STORAGE_SCHEMA_ID)->value('owner_package');
block_document_expect($owner === 'larena/storage', 'a fresh schema must be owned by larena/storage, got ' . var_export($owner, true));

// The Content behaviour survives the move: sanitized, versioned, projected.
$created = $service->create(block_document_fixture(), 'actor:admin');
block_document_expect($created->storageRef->revision === 1, 'first revision');
block_document_expect($created->document['blocks'][0]['data']['text'] === 'Hello world', 'markup is stripped');
block_document_expect($created->document['blocks'][0]['data_version'] === 1, 'blocks migrate to data version 1');
$projection = $service->project('page.home', 'scope:global', 'actor:admin');
block_document_expect($projection['semantic_hash'] === $created->semanticHash, 'projection carries the semantic hash');
block_document_expect(!str_contains(json_encode($projection, JSON_THROW_ON_ERROR), '<b>'), 'no markup in a projection');

$next = block_document_fixture();
$next['blocks'][0]['data']['body'] = 'Updated';
$updated = $service->update($next, $created->storageRef, 'actor:admin');
block_document_expect($updated->storageRef->revision === 2, 'second revision');

// Compare-and-swap: a write against a stale revision is refused, not merged.
block_document_expect(
    block_document_refusal(static fn () => $service->update(block_document_fixture(), $created->storageRef, 'actor:admin')) === 'storage_record_revision_conflict',
    'a stale write must be refused',
);
block_document_expect($service->read('page.home', 'scope:global', 'actor:admin')?->storageRef->revision === 2, 'the head stays at revision 2');

// Key order is not meaning; block order is.
$normalizer = new BlockDocumentNormalizer();
$reordered = block_document_text_only();
$reordered = array_reverse($reordered, true);
block_document_expect($normalizer->semanticHash($reordered) === $normalizer->semanticHash(block_document_text_only()), 'object key order is not semantic');

// Without a file owner composed, an image block fails closed.
$noFiles = new StorageBackedBlockDocumentService($storage, $allow, new UnavailableBlockDocumentFileInspector());
$other = block_document_fixture();
$other['document_id'] = 'page.other';
block_document_expect(
    block_document_refusal(static fn () => $noFiles->create($other, 'actor:admin')) === 'content_block_file_unavailable',
    'an image block must fail closed when no file owner is composed',
);
$textOnly = block_document_text_only();
$textOnly['document_id'] = 'page.text';
block_document_expect($noFiles->create($textOnly, 'actor:admin')->storageRef->revision === 1, 'a text-only document needs no file owner');

// Authorization is enforced before anything is written.
$denied = new StorageBackedBlockDocumentService($storage, new BlockDocumentDenyAll(), new BlockDocumentAttachableFiles());
$deniedDocument = block_document_text_only();
$deniedDocument['document_id'] = 'page.denied';
block_document_expect(
    block_document_refusal(static fn () => $denied->create($deniedDocument, 'actor:intruder')) === 'content_document_scope_denied',
    'a denied actor must be refused',
);
block_document_expect(
    $connection->table('larena_storage_records')->where('owner_ref', 'like', '%page.denied')->doesntExist(),
    'a refused create writes nothing',
);

// An installation that ran with larena/content recorded that owner. It still opens.
$legacyPath = tempnam(sys_get_temp_dir(), 'block-documents-legacy-') . '.sqlite';
file_put_contents($legacyPath, '');
$capsule->addConnection(['driver' => 'sqlite', 'database' => $legacyPath, 'prefix' => '', 'foreign_key_constraints' => true], 'legacy');
$legacyConnection = $capsule->getConnection('legacy');
Schema::swap($legacyConnection->getSchemaBuilder());
$container->instance('db.schema', $legacyConnection->getSchemaBuilder());
$migration = require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php';
$migration->up();
$legacyStorage = new VersionedStorage(
    $legacyConnection,
    PropertyTypeRegistry::builtIns(),
    $allow,
    new AuditEventPipeline(new DefaultAuditRedactor(), [new BlockDocumentNullAuditSink()]),
);
$legacyStorage->registerSchemaVersion([
    'schema_id' => StorageBackedBlockDocumentService::STORAGE_SCHEMA_ID,
    'owner_package' => 'larena/content',
    'fields' => [
        ['key' => 'document_json', 'type' => 'text', 'type_version' => 1, 'required' => true, 'visibility' => 'protected', 'constraints' => ['max_length' => BlockDocumentNormalizer::MAX_BYTES]],
        ['key' => 'semantic_hash', 'type' => 'string', 'type_version' => 2, 'required' => true, 'visibility' => 'protected', 'constraints' => ['min_length' => 64, 'max_length' => 64]],
        ['key' => 'scope_ref', 'type' => 'string', 'type_version' => 2, 'required' => true, 'visibility' => 'protected', 'constraints' => ['min_length' => 7, 'max_length' => 126]],
    ],
], null, 'actor:legacy');
$legacy = new StorageBackedBlockDocumentService($legacyStorage, $allow, new BlockDocumentAttachableFiles());
$legacyCreated = $legacy->create(block_document_text_only(), 'actor:admin');
block_document_expect($legacyCreated->storageRef->revision === 1, 'a larena/content-owned schema still accepts documents');
block_document_expect(
    $legacyConnection->table('larena_storage_schema_versions')->value('owner_package') === 'larena/content',
    'the legacy owner label is read, never rewritten',
);

@unlink($path);
@unlink($legacyPath);

echo "Block document service passed.\n";
