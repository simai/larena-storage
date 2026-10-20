<?php

declare(strict_types=1);

namespace Larena\Storage\View;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * One storage's row in `larena_storage_views`: where its витрина is, its layout,
 * and whether it is in sync.
 *
 * States: `pending` (the table or its layout is not built; nothing writes it),
 * `building` (a rebuild is filling it; writers keep it current), `ready` (in sync
 * with the versions), `failed` (a rebuild found it unusable).
 */
final readonly class StorageViewRegistration
{
    public const STATE_PENDING = 'pending';
    public const STATE_BUILDING = 'building';
    public const STATE_READY = 'ready';
    public const STATE_FAILED = 'failed';
    public const STATES = [self::STATE_PENDING, self::STATE_BUILDING, self::STATE_READY, self::STATE_FAILED];

    public function __construct(
        public string $schemaId,
        public string $tableName,
        public StorageViewLayout $layout,
        public string $state,
        public ?int $builtSchemaVersion = null,
        public ?string $buildCursor = null,
        public ?string $buildRunRef = null,
    ) {
        if (!in_array($state, self::STATES, true) || !StorageViewNaming::isTable($tableName)
            || str_ends_with($tableName, StorageViewNaming::MULTI_SUFFIX)) {
            throw new StorageRejected('storage_view_registration_invalid');
        }
    }

    public static function create(string $schemaId, StorageViewLayout $layout, string $state): self
    {
        return new self(
            $schemaId,
            StorageViewNaming::table($schemaId),
            $layout,
            $state,
            $state === self::STATE_READY ? $layout->schemaVersion : null,
        );
    }

    /** Writers keep the витрина current while it is ready or being built. */
    public function written(): bool
    {
        return $this->state === self::STATE_READY || $this->state === self::STATE_BUILDING;
    }

    public function withLayout(StorageViewLayout $layout): self
    {
        return new self($this->schemaId, $this->tableName, $layout, $this->state, $this->builtSchemaVersion, $this->buildCursor, $this->buildRunRef);
    }

    public function withState(string $state, ?int $builtSchemaVersion = null, ?string $buildCursor = null, ?string $buildRunRef = null): self
    {
        return new self($this->schemaId, $this->tableName, $this->layout, $state, $builtSchemaVersion, $buildCursor, $buildRunRef);
    }

    public function withCursor(?string $buildCursor): self
    {
        return new self($this->schemaId, $this->tableName, $this->layout, $this->state, $this->builtSchemaVersion, $buildCursor, $this->buildRunRef);
    }

    public function withBuiltVersion(int $builtSchemaVersion): self
    {
        return new self($this->schemaId, $this->tableName, $this->layout, $this->state, $builtSchemaVersion, $this->buildCursor, $this->buildRunRef);
    }
}
