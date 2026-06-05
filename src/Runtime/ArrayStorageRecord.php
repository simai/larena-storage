<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StorageRecord;

final readonly class ArrayStorageRecord implements StorageRecord
{
    /**
     * @param array<string, mixed> $projection
     */
    public function __construct(
        private string $id,
        private string $schemaId,
        private string $schemaVersion,
        private string $correlationId,
        private array $projection
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function schemaId(): string
    {
        return $this->schemaId;
    }

    public function schemaVersion(): string
    {
        return $this->schemaVersion;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * @return array<string, mixed>
     */
    public function projection(): array
    {
        return $this->projection;
    }
}
