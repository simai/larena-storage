<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Enums\StorageWorkbenchCountMode;

final readonly class StorageWorkbenchRecordQuery
{
    /**
     * Runtime validation narrows these arrays to the documented filter and sort
     * shapes before any query is issued.
     *
     * @param array<array-key, mixed> $filters
     * @param array<array-key, mixed> $sort
     * @param StorageWorkbenchCountMode $countMode How `matchedCount` is counted (default: exact under a
     *                                             threshold, an estimate above it).
     */
    public function __construct(
        public string $scopeRef,
        public string $structureId,
        public array $filters = [],
        public ?string $search = null,
        public array $sort = [],
        public int $limit = 50,
        public ?string $continuation = null,
        public bool $includeArchived = false,
        public ?int $page = null,
        public StorageWorkbenchCountMode $countMode = StorageWorkbenchCountMode::Auto,
    ) {
    }
}
