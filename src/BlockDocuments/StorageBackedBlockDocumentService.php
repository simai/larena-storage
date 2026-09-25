<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

use JsonException;
use Larena\Storage\BlockDocuments\BlockDocumentRejected;
use Larena\Storage\Contracts\StorageRecordVersion;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * Versioned block documents, stored as Storage records.
 *
 * This moved from larena/content, and it always wrote only through Storage. The
 * schema id keeps its content.block_document spelling because every existing record
 * and every page descriptor that binds one refers to it; renaming would be a data
 * migration. The word is a naming legacy, not an owner.
 */
final readonly class StorageBackedBlockDocumentService implements BlockDocumentService
{
    public const STORAGE_SCHEMA_ID = 'content.block_document';

    public const OWNER_PACKAGE = 'larena/storage';

    /**
     * The owner label an installation that ran with larena/content recorded on
     * this schema. Schema versions are immutable, so the label stays; it is
     * accepted, never written.
     */
    public const LEGACY_OWNER_PACKAGE = 'larena/content';

    public function __construct(
        private VersionedStorage $storage,
        private BlockDocumentAuthorization $authorization,
        private BlockDocumentFileInspector $logicalFiles,
        private BlockDocumentNormalizer $normalizer = new BlockDocumentNormalizer(),
        private BlockDocumentCanonicalJson $json = new BlockDocumentCanonicalJson(),
    ) {
    }

    public function installSchema(string $actor): StorageSchemaVersionRef
    {
        $ref = new StorageSchemaVersionRef(self::STORAGE_SCHEMA_ID, 1);
        try {
            $schema = $this->storage->schemaVersion($ref);
        } catch (StorageRejected $exception) {
            if ($exception->reasonCode !== 'storage_schema_version_unknown') {
                throw $exception;
            }
            $schema = $this->storage->registerSchemaVersion($this->schemaDefinition(), null, $actor);
        }
        if (!in_array($schema->ownerPackage, [self::OWNER_PACKAGE, self::LEGACY_OWNER_PACKAGE], true)
            || $schema->ref->key() !== $ref->key()) {
            throw $this->reject('content_document_storage_schema_mismatch');
        }

        return $ref;
    }

    public function create(array $document, string $actor): BlockDocumentRevision
    {
        $document = $this->normalizer->normalize($document);
        $this->authorization->assertAllowed($actor, BlockDocumentAuthorization::CREATE, (string) $document['scope_ref']);
        $this->assertLogicalFiles($document, $actor);
        $schema = $this->installSchema($actor);
        $result = $this->storage->create(
            $this->ownerRef((string) $document['document_id'], (string) $document['scope_ref']),
            $schema,
            $this->values($document),
            $actor,
        );

        return $this->hydrate($result->version);
    }

    public function update(array $document, StorageRecordVersionRef $expected, string $actor): BlockDocumentRevision
    {
        $document = $this->normalizer->normalize($document);
        $this->authorization->assertAllowed($actor, BlockDocumentAuthorization::UPDATE, (string) $document['scope_ref']);
        $this->assertLogicalFiles($document, $actor);
        if ($expected->schemaId !== self::STORAGE_SCHEMA_ID) {
            throw $this->reject('content_document_revision_invalid');
        }
        $result = $this->storage->compareAndSwap(
            $this->ownerRef((string) $document['document_id'], (string) $document['scope_ref']),
            $expected,
            $this->installSchema($actor),
            $this->values($document),
            $actor,
        );

        return $this->hydrate($result->version);
    }

    public function read(string $documentId, string $scopeRef, string $actor): ?BlockDocumentRevision
    {
        $this->authorization->assertAllowed($actor, BlockDocumentAuthorization::READ, $scopeRef);
        $record = $this->storage->readAdminCurrentVersion(
            self::STORAGE_SCHEMA_ID,
            $this->ownerRef($documentId, $scopeRef),
            $actor,
        );

        return $record === null ? null : $this->hydrate($record);
    }

    public function project(string $documentId, string $scopeRef, string $actor): array
    {
        $this->authorization->assertAllowed($actor, BlockDocumentAuthorization::PROJECT, $scopeRef);
        $record = $this->storage->readAdminCurrentVersion(
            self::STORAGE_SCHEMA_ID,
            $this->ownerRef($documentId, $scopeRef),
            $actor,
        );
        $revision = $record === null ? null : $this->hydrate($record);
        if ($revision === null) {
            throw $this->reject('content_document_missing');
        }
        $this->assertLogicalFiles($revision->document, $actor);

        return $this->normalizer->publicProjection($revision->document);
    }

    /** @return array<string, mixed> */
    private function schemaDefinition(): array
    {
        return [
            'schema_id' => self::STORAGE_SCHEMA_ID,
            'owner_package' => self::OWNER_PACKAGE,
            'fields' => [
                ['key' => 'document_json', 'type' => 'text', 'type_version' => 1, 'required' => true, 'visibility' => 'protected', 'constraints' => ['max_length' => BlockDocumentNormalizer::MAX_BYTES]],
                ['key' => 'semantic_hash', 'type' => 'string', 'type_version' => 2, 'required' => true, 'visibility' => 'protected', 'constraints' => ['min_length' => 64, 'max_length' => 64]],
                ['key' => 'scope_ref', 'type' => 'string', 'type_version' => 2, 'required' => true, 'visibility' => 'protected', 'constraints' => ['min_length' => 7, 'max_length' => 126]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, string>
     */
    private function values(array $document): array
    {
        return [
            'document_json' => $this->json->encode($document),
            'semantic_hash' => $this->normalizer->semanticHash($document),
            'scope_ref' => (string) $document['scope_ref'],
        ];
    }

    private function hydrate(StorageRecordVersion $record): BlockDocumentRevision
    {
        try {
            $document = json_decode((string) ($record->values['document_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->reject('content_document_persisted_json_invalid');
        }
        if (!is_array($document) || array_is_list($document)) {
            throw $this->reject('content_document_persisted_json_invalid');
        }
        $document = $this->normalizer->normalize($document);
        $hash = $this->normalizer->semanticHash($document);
        if (!is_string($record->values['semantic_hash'] ?? null)
            || !hash_equals($hash, $record->values['semantic_hash'])
            || ($record->values['scope_ref'] ?? null) !== $document['scope_ref']) {
            throw $this->reject('content_document_persisted_hash_mismatch');
        }

        return new BlockDocumentRevision((string) $document['document_id'], $document, $hash, $record->ref);
    }

    private function ownerRef(string $documentId, string $scopeRef): string
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $documentId) !== 1) {
            throw $this->reject('content_document_id_invalid');
        }
        if (preg_match('/^scope:[a-z][a-z0-9_.:-]{1,119}$/', $scopeRef) !== 1) {
            throw $this->reject('content_document_scope_invalid');
        }

        return 'content.document:'.substr(hash('sha256', $scopeRef), 0, 16).':'.$documentId;
    }

    /** @param array<string, mixed> $document */
    private function assertLogicalFiles(array $document, string $actor): void
    {
        $scopeRef = (string) $document['scope_ref'];
        foreach ($document['blocks'] as $block) {
            if (($block['type'] ?? null) !== 'image') {
                continue;
            }
            $logicalFileRef = $block['data']['logical_file_ref'] ?? null;
            if (!is_string($logicalFileRef)) {
                throw $this->reject('content_block_file_ref_invalid');
            }
            $this->authorization->assertLogicalFileAllowed($actor, $scopeRef, $logicalFileRef);
            try {
                $inspection = $this->logicalFiles->inspect($logicalFileRef);
            } catch (Throwable) {
                throw $this->reject('content_block_file_unavailable');
            }
            if ($inspection->logicalFileRef !== $logicalFileRef || !$inspection->isAttachable()) {
                throw $this->reject('content_block_file_unavailable');
            }
        }
    }

    private function reject(string $reason): BlockDocumentRejected
    {
        return new BlockDocumentRejected($reason, 'The block document was rejected.');
    }
}
