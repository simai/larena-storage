<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface StorageRecord
{
    public function id(): string;

    public function schemaId(): string;

    public function schemaVersion(): string;

    public function correlationId(): string;

    /**
     * @return array<string, mixed>
     */
    public function projection(): array;
}
