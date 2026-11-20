<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

use InvalidArgumentException;
use Larena\Property\Runtime\CompositionDocument;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;

/**
 * Which record field a document port stands on, as the host binds it: the record of a storage structure in a
 * workbench scope, its `composition@1` field, the site scope its publications go to (null: the element is not
 * published, so the port does not announce `publish`), and what the editor is told about the element.
 */
final readonly class DocumentPortBinding
{
    /**
     * @param array<string, mixed> $target {level, ref, title?, parents?}
     * @param list<string> $languages the element's languages, the main one first
     * @param array{max_nodes: int, max_bytes: int, max_total_bytes?: int} $limits at most the type's (CompositionDocument); the
     *        element's languages together at most a record's cap (DatabaseStorageWorkbench::MAX_VALUES_BYTES)
     */
    public function __construct(
        public string $scopeRef,
        public string $structureId,
        public string $recordId,
        public string $fieldKey,
        public ?string $siteScopeRef,
        public array $target,
        public array $languages,
        public array $limits = ['max_nodes' => CompositionDocument::MAX_NODES, 'max_bytes' => CompositionDocument::MAX_BYTES,
            'max_total_bytes' => DatabaseStorageWorkbench::MAX_VALUES_BYTES],
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $fieldKey) !== 1 || $recordId === '' || $structureId === '') {
            throw new InvalidArgumentException('document_port_binding_invalid');
        }
    }

    /** The capabilities a database element announces: history always, publish when it has a site. @return list<string> */
    public function capabilities(): array
    {
        return $this->siteScopeRef === null ? ['history'] : ['history', 'publish'];
    }
}
