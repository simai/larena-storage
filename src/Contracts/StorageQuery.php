<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface StorageQuery
{
    public function schemaId(): string;

    public function accessScopeRef(): string;

    /**
     * @return array<string, scalar|null>
     */
    public function filters(): array;

    public function locale(): ?string;
}
