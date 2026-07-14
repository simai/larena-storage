<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageSchemaCompatibilityReport
{
    /** @param list<string> $reasonCodes */
    public function __construct(
        public StorageSchemaVersionRef $source,
        public string $sourceHash,
        public StorageSchemaVersionRef $target,
        public string $targetHash,
        public bool $compatible,
        public string $compatibilityClass,
        public int $addedOptionalFieldCount,
        public int $recordCount,
        public string $recordHeadsHash,
        public array $reasonCodes,
    ) {
    }
}
