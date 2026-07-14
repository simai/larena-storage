<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageSchemaMigrationResult
{
    /** @param list<StorageSchemaMigrationRecordResult> $records */
    public function __construct(
        public string $resultRef,
        public string $resultHash,
        public string $planRef,
        public StorageSchemaVersionRef $target,
        public string $targetHash,
        public int $migratedRecordCount,
        public string $migratedRecordsHash,
        public array $records,
        public string $appliedAt,
    ) {
    }
}
