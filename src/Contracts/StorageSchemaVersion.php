<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageSchemaVersion
{
    /**
     * @param list<array<string, mixed>> $fields
     */
    public function __construct(
        public StorageSchemaVersionRef $ref,
        public string $ownerPackage,
        public array $fields,
        public string $definitionHash,
        public string $createdBy,
        public ?string $correlationId,
        public string $createdAt,
    ) {
    }
}
