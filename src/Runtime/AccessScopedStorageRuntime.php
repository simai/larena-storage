<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Storage\Contracts\StorageAccessScopeResolver;
use Larena\Storage\Contracts\StorageMutation;
use Larena\Storage\Contracts\StorageQuery;
use Larena\Storage\Contracts\StorageRecord;
use Larena\Storage\Enums\StorageDecisionStatus;

final readonly class AccessScopedStorageRuntime implements StorageAccessScopeResolver
{
    public function __construct(
        private InMemoryStorageRuntime $runtime,
        private ?QueryScopeProvider $queryScopeProvider,
    ) {
    }

    public function decideQuery(StorageQuery $query, string $actor, string $operation = 'list', array $context = []): StorageDecisionStatus
    {
        $runtimeDecision = $this->runtime->decideQuery($query);
        if (!$runtimeDecision->permitsDataAccess()) {
            return $runtimeDecision;
        }

        if ($this->queryScopeProvider === null) {
            return StorageDecisionStatus::MissingAccessScope;
        }

        $resourceType = $this->resourceType($query->schemaId());
        if (!$this->queryScopeProvider->supports($resourceType, $operation)) {
            return StorageDecisionStatus::MissingAccessScope;
        }

        $decision = $this->queryScopeProvider->explain($resourceType, $actor, $operation, [
            ...$context,
            'schema_id' => $query->schemaId(),
            'access_scope_ref' => $query->accessScopeRef(),
        ]);

        return $decision->isAllowed() ? StorageDecisionStatus::Allowed : StorageDecisionStatus::Denied;
    }

    public function scopedFilters(StorageQuery $query, string $actor, string $operation = 'list', array $context = []): array
    {
        if ($this->decideQuery($query, $actor, $operation, $context) !== StorageDecisionStatus::Allowed || $this->queryScopeProvider === null) {
            return [];
        }

        $scopedQuery = $this->queryScopeProvider->scope(
            [
                'schema_id' => $query->schemaId(),
                'filters' => $query->filters(),
            ],
            $actor,
            $operation,
            [
                ...$context,
                'resource_type' => $this->resourceType($query->schemaId()),
                'access_scope_ref' => $query->accessScopeRef(),
            ],
        );

        $filters = $scopedQuery['filters'] ?? [];

        return is_array($filters) ? $this->scalarFilters($filters) : [];
    }

    /**
     * @return list<StorageRecord>
     */
    public function records(StorageQuery $query, string $actor, string $operation = 'list', array $context = []): array
    {
        if ($this->decideQuery($query, $actor, $operation, $context) !== StorageDecisionStatus::Allowed) {
            return [];
        }

        return $this->runtime->records(new ArrayStorageQuery(
            $query->schemaId(),
            $query->accessScopeRef(),
            $this->scopedFilters($query, $actor, $operation, $context),
            $query->locale(),
        ));
    }

    public function decideMutation(StorageMutation $mutation, string $actor, array $context = []): StorageDecisionStatus
    {
        if (trim($mutation->accessScopeRef()) === '') {
            return StorageDecisionStatus::MissingAccessScope;
        }

        if ($this->queryScopeProvider === null) {
            return StorageDecisionStatus::MissingAccessScope;
        }

        $operation = $mutation->type()->value;
        $resourceType = $this->resourceType($mutation->schemaId());
        if (!$this->queryScopeProvider->supports($resourceType, $operation)) {
            return StorageDecisionStatus::MissingAccessScope;
        }

        $decision = $this->queryScopeProvider->explain($resourceType, $actor, $operation, [
            ...$context,
            'schema_id' => $mutation->schemaId(),
            'record_id' => $mutation->recordId(),
            'access_scope_ref' => $mutation->accessScopeRef(),
        ]);

        return $decision->isAllowed() ? StorageDecisionStatus::Allowed : StorageDecisionStatus::Denied;
    }

    public function mutate(StorageMutation $mutation, string $actor, array $context = []): StorageDecisionStatus
    {
        $accessDecision = $this->decideMutation($mutation, $actor, $context);
        if (!$accessDecision->permitsDataAccess()) {
            return $accessDecision;
        }

        return $this->runtime->mutate($mutation);
    }

    private function resourceType(string $schemaId): string
    {
        return 'storage.record:' . $schemaId;
    }

    /**
     * @param array<array-key, mixed> $filters
     * @return array<string, scalar|null>
     */
    private function scalarFilters(array $filters): array
    {
        $result = [];
        foreach ($filters as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
