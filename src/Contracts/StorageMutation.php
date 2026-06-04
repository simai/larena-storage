<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Enums\MutationType;

interface StorageMutation
{
    public function schemaId(): string;

    public function recordId(): ?string;

    public function type(): MutationType;

    public function accessScopeRef(): string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;
}
