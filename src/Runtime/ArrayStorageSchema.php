<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StorageSchema;

final readonly class ArrayStorageSchema implements StorageSchema
{
    /**
     * @param list<array<string, scalar|null>> $fields
     */
    public function __construct(
        private string $id,
        private string $version,
        private string $accessPolicyRef,
        private string $persistenceProfile,
        private array $fields
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function accessPolicyRef(): string
    {
        return $this->accessPolicyRef;
    }

    public function persistenceProfile(): string
    {
        return $this->persistenceProfile;
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
