<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageRecordVersion
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public StorageRecordVersionRef $ref,
        public string $ownerRef,
        public StorageSchemaVersionRef $schema,
        public array $values,
        public string $contentHash,
        public string $operation,
        public string $createdBy,
        public ?string $correlationId,
        public string $createdAt,
    ) {
    }
}
