<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageRecordListQuery
{
    /**
     * Each filter is keyed by a schema field and has the exact shape
     * `['operator' => 'eq', 'value' => scalar|null]`.
     *
     * @param array<string, array{operator: string, value: mixed}> $filters
     */
    public function __construct(
        public string $schemaId,
        public array $filters = [],
        public int $limit = 50,
        public ?string $continuation = null,
    ) {
    }
}
