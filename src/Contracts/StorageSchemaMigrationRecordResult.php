<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageSchemaMigrationRecordResult
{
    public function __construct(
        public string $ownerRef,
        public StorageRecordVersionRef $before,
        public StorageRecordVersionRef $after,
        public string $contentHash,
    ) {
    }
}
