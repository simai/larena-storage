<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface StoragePersistenceProfile
{
    public function id(): string;

    public function driver(): string;

    public function isBaseline(): bool;

    /**
     * @return array<string, scalar|null>
     */
    public function options(): array;
}
