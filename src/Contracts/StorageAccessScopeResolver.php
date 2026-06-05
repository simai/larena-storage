<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Enums\StorageDecisionStatus;

interface StorageAccessScopeResolver
{
    /**
     * @param array<string, mixed> $context
     */
    public function decideQuery(StorageQuery $query, string $actor, string $operation = 'list', array $context = []): StorageDecisionStatus;

    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar|null>
     */
    public function scopedFilters(StorageQuery $query, string $actor, string $operation = 'list', array $context = []): array;

    /**
     * @param array<string, mixed> $context
     */
    public function decideMutation(StorageMutation $mutation, string $actor, array $context = []): StorageDecisionStatus;
}
