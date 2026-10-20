<?php

declare(strict_types=1);

namespace Larena\Storage\Benchmark;

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * Access for the benchmark's own runtime: the benchmark actor may do anything,
 * but only inside the benchmark's own scope. Nobody else is allowed, so the
 * benchmark runtime cannot be used to reach other data.
 */
final readonly class StorageBenchmarkAccess implements ActorOperationAuthorizer, QueryScopeProvider
{
    public function __construct(private string $actor, private string $scopeRef)
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        if ($actor !== $this->actor) {
            throw new StorageRejected('storage_benchmark_actor_denied');
        }
    }

    public function supports(string $resourceType, string $operation): bool
    {
        return in_array($resourceType, ['storage.workbench.structure', 'storage.workbench.record'], true)
            && str_starts_with($operation, 'storage.workbench.');
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        return ['scope_ref' => $actor === $this->actor ? $this->scopeRef : 'scope:denied'];
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        if ($actor !== $this->actor || ($context['requested_scope_ref'] ?? null) !== $this->scopeRef) {
            return AccessDecision::deny($operation, $actor, $resourceType . ':denied', 'storage_benchmark_scope_denied');
        }

        return AccessDecision::allow($operation, $actor, $resourceType . ':' . $this->scopeRef, 'storage_benchmark_scope_allowed');
    }
}
