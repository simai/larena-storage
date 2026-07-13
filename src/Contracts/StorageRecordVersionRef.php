<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use InvalidArgumentException;

final readonly class StorageRecordVersionRef
{
    public function __construct(
        public string $schemaId,
        public string $recordId,
        public int $revision,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $schemaId) !== 1
            || preg_match('/^record-[a-f0-9]{32}$/', $recordId) !== 1
            || $revision < 1) {
            throw new InvalidArgumentException('storage_record_version_ref_invalid');
        }
    }

    public function key(): string
    {
        return $this->schemaId . ':' . $this->recordId . '@' . $this->revision;
    }
}
