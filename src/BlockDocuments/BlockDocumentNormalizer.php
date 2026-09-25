<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

use Larena\Storage\BlockDocuments\BlockDocumentRejected;

final readonly class BlockDocumentNormalizer
{
    public const SCHEMA = 'larena.content.block_document';
    public const SCHEMA_VERSION = 1;
    public const MAX_BLOCKS = 100;
    public const MAX_BYTES = 262_144;
    public const MAX_DEPTH = 8;

    public function __construct(
        private BlockDocumentRegistry $registry = new BlockDocumentRegistry(),
        private BlockDocumentCanonicalJson $json = new BlockDocumentCanonicalJson(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $this->assertDepth($input, 0);
        $this->exactKeys($input, ['blocks', 'document_id', 'registry', 'schema', 'schema_version', 'scope_ref', 'time', 'version']);
        $documentId = $input['document_id'] ?? null;
        if (!is_string($documentId) || preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $documentId) !== 1) {
            throw $this->reject('content_document_id_invalid');
        }
        $scopeRef = $input['scope_ref'] ?? null;
        if (!is_string($scopeRef) || preg_match('/^scope:[a-z][a-z0-9_.:-]{1,119}$/', $scopeRef) !== 1) {
            throw $this->reject('content_document_scope_invalid');
        }
        if (($input['schema'] ?? null) !== self::SCHEMA
            || !in_array($input['schema_version'] ?? null, [0, self::SCHEMA_VERSION], true)
            || ($input['registry'] ?? null) !== BlockDocumentRegistry::ID
            || !is_int($input['time'] ?? null)
            || $input['time'] < 0
            || !is_string($input['version'] ?? null)
            || preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $input['version']) !== 1
            || !is_array($input['blocks'] ?? null)
            || !array_is_list($input['blocks'])) {
            throw $this->reject('content_document_shape_invalid');
        }
        if (count($input['blocks']) > self::MAX_BLOCKS) {
            throw $this->reject('content_document_block_limit_exceeded');
        }

        $blocks = [];
        $ids = [];
        foreach ($input['blocks'] as $block) {
            if (!is_array($block) || array_is_list($block)) {
                throw $this->reject('content_block_shape_invalid');
            }
            $this->exactKeys($block, ['data', 'data_version', 'id', 'type']);
            $id = $block['id'] ?? null;
            $type = $block['type'] ?? null;
            $version = $block['data_version'] ?? null;
            if (!is_string($id)
                || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1
                || isset($ids[$id])
                || !is_string($type)
                || !is_int($version)
                || !is_array($block['data'] ?? null)
                || array_is_list($block['data'])) {
                throw $this->reject('content_block_shape_invalid');
            }
            $ids[$id] = true;
            $blocks[] = [
                'id' => $id,
                'type' => $type,
                'data_version' => 1,
                'data' => $this->registry->migrateAndSanitize($type, $version, $block['data']),
            ];
        }

        $normalized = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'document_id' => $documentId,
            'scope_ref' => $scopeRef,
            'registry' => BlockDocumentRegistry::ID,
            'version' => $input['version'],
            'time' => $input['time'],
            'blocks' => $blocks,
        ];
        $this->json->assertMaximumBytes($normalized, self::MAX_BYTES, 'content_document_size_limit_exceeded');

        return $this->json->canonicalize($normalized);
    }

    /** @param array<string, mixed> $document */
    public function semanticHash(array $document): string
    {
        $document = $this->normalize($document);

        return hash('sha256', $this->json->encode([
            'schema' => $document['schema'],
            'schema_version' => $document['schema_version'],
            'registry' => $document['registry'],
            'scope_ref' => $document['scope_ref'],
            'blocks' => $document['blocks'],
        ]));
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function publicProjection(array $document): array
    {
        $document = $this->normalize($document);

        return [
            'document_id' => $document['document_id'],
            'schema' => $document['schema'],
            'schema_version' => $document['schema_version'],
            'registry' => $document['registry'],
            'scope_ref' => $document['scope_ref'],
            'blocks' => $document['blocks'],
            'semantic_hash' => $this->semanticHash($document),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw $this->reject('content_document_unknown_key');
        }
    }

    private function assertDepth(mixed $value, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->reject('content_document_depth_limit_exceeded');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->assertDepth($child, $depth + 1);
            }
        }
    }

    private function reject(string $reason): BlockDocumentRejected
    {
        return new BlockDocumentRejected($reason, 'The block document was rejected.');
    }
}
