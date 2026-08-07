<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageRecordListItem
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public StorageRecordVersionRef $ref,
        public StorageSchemaVersionRef $schema,
        public array $values,
    ) {
    }
}
