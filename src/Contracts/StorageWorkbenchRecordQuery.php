<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageWorkbenchRecordQuery
{
    /**
     * Runtime validation narrows these arrays to the documented filter and sort
     * shapes before any query is issued.
     *
     * @param array<array-key, mixed> $filters
     * @param array<array-key, mixed> $sort
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
    ) {
    }
}
