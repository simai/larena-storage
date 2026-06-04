<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface StorageSchema
{
    public function id(): string;

    public function version(): string;

    public function accessPolicyRef(): string;

    public function persistenceProfile(): string;

    /**
     * @return list<array<string, scalar|null>>
     */
    public function fields(): array;
}
