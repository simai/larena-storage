<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StorageQuery;

final readonly class ArrayStorageQuery implements StorageQuery
{
    /**
     * @param array<string, scalar|null> $filters
     */
    public function __construct(
        private string $schemaId,
        private string $accessScopeRef,
        private array $filters = [],
        private ?string $locale = null
    ) {
    }

    public function schemaId(): string
    {
        return $this->schemaId;
    }

    public function accessScopeRef(): string
    {
        return $this->accessScopeRef;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }
}
