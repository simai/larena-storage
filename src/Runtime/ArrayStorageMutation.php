<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StorageMutation;
use Larena\Storage\Enums\MutationType;

final readonly class ArrayStorageMutation implements StorageMutation
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $schemaId,
        private ?string $recordId,
        private MutationType $type,
        private string $accessScopeRef,
        private array $payload = []
    ) {
    }

    public function schemaId(): string
    {
        return $this->schemaId;
    }

    public function recordId(): ?string
    {
        return $this->recordId;
    }

    public function type(): MutationType
    {
        return $this->type;
    }

    public function accessScopeRef(): string
    {
        return $this->accessScopeRef;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
