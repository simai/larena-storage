<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageWorkbenchRecord
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public string $structureId,
        public string $scopeRef,
        public string $recordId,
        public int $revision,
        public int $schemaVersion,
        public string $state,
        public array $values,
        public string $contentHash,
        public string $operation,
        public string $updatedAt,
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
            'storage.record:' . $this->recordId,
            $this->revision,
            'succeeded',
            $this->correlationId,
        );
    }
}
