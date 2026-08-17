<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageWorkbenchStructure
{
    /** @param list<array<string, mixed>> $fields */
    public function __construct(
        public string $structureId,
        public string $scopeRef,
        public int $version,
        public StorageSchemaVersionRef $schema,
        public string $label,
        public array $fields,
        public string $descriptorHash,
        public string $updatedAt,
        public string $operation = 'create',
        public string $actor = '',
        public ?string $correlationId = null,
    ) {
    }

    public function receipt(): StorageMutationReceipt
    {
        return new StorageMutationReceipt(
            $this->actor,
            $this->updatedAt,
            $this->operation,
            'storage.structure:' . $this->structureId,
            $this->version,
            'succeeded',
            $this->correlationId,
        );
    }
}
