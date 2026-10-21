<?php

declare(strict_types=1);

namespace Larena\Storage\View\Site;

use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\View\StorageViewLayout;
use Larena\Storage\View\StorageViewNaming;

/**
 * One published storage's row in `larena_storage_site_views`: where its site
 * витрина is, the layout of its public fields, and the hash of those fields.
 *
 * States: `pending` (the table or its layout is not built, or the public fields
 * changed since: nothing writes it and no slot answers), `ready` (the table holds
 * the layout; each slot says whether its rows are complete), `failed` (a rebuild
 * found it unusable).
 */
final readonly class PublishedSiteViewRegistration
{
    public const STATE_PENDING = 'pending';
    public const STATE_READY = 'ready';
    public const STATE_FAILED = 'failed';
    public const STATES = [self::STATE_PENDING, self::STATE_READY, self::STATE_FAILED];

    public function __construct(
        public string $schemaId,
        public string $tableName,
        public StorageViewLayout $layout,
        public string $publicFieldsHash,
        public string $state,
        public ?int $builtSchemaVersion = null,
    ) {
        if (!in_array($state, self::STATES, true) || !StorageViewNaming::isSiteTable($tableName)
            || str_ends_with($tableName, StorageViewNaming::MULTI_SUFFIX) || str_ends_with($tableName, StorageViewNaming::SEARCH_SUFFIX)
            || preg_match('/\A[0-9a-f]{64}\z/D', $publicFieldsHash) !== 1) {
            throw new StorageRejected('storage_site_view_registration_invalid');
        }
    }

    public static function create(string $schemaId, StorageViewLayout $layout, string $publicFieldsHash, string $state): self
    {
        return new self(
            $schemaId,
            StorageViewNaming::siteTable($schemaId),
            $layout,
            $publicFieldsHash,
            $state,
            $state === self::STATE_READY ? $layout->schemaVersion : null,
        );
    }

    /** Writers keep the slots current only while the table holds the layout. */
    public function ready(): bool
    {
        return $this->state === self::STATE_READY;
    }

    public function withState(string $state, ?int $builtSchemaVersion = null): self
    {
        return new self($this->schemaId, $this->tableName, $this->layout, $this->publicFieldsHash, $state, $builtSchemaVersion ?? $this->builtSchemaVersion);
    }

    public function withLayout(StorageViewLayout $layout, string $publicFieldsHash): self
    {
        return new self($this->schemaId, $this->tableName, $layout, $publicFieldsHash, $this->state, $this->builtSchemaVersion);
    }
}
