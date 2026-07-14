<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageSchemaMigrationRecordHead
{
    public function __construct(
        public string $ownerRef,
        public StorageRecordVersionRef $before,
        public int $schemaVersion,
        public string $contentHash,
    ) {
    }
}
