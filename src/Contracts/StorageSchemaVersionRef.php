<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use InvalidArgumentException;

final readonly class StorageSchemaVersionRef
{
    public function __construct(
        public string $schemaId,
        public int $version,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $schemaId) !== 1 || $version < 1) {
            throw new InvalidArgumentException('storage_schema_version_ref_invalid');
        }
    }

    public function key(): string
    {
        return $this->schemaId . '@' . $this->version;
    }
}
