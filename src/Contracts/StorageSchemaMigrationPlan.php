<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageSchemaMigrationPlan
{
    /** @param list<StorageSchemaMigrationRecordHead> $records */
    public function __construct(
        public string $planRef,
        public string $planHash,
        public StorageSchemaVersionRef $source,
        public string $sourceHash,
        public StorageSchemaVersionRef $target,
        public string $targetHash,
        public string $compatibilityClass,
        public int $addedOptionalFieldCount,
        public int $recordCount,
        public string $recordHeadsHash,
        public array $records,
        public string $createdAt,
    ) {
    }
}
