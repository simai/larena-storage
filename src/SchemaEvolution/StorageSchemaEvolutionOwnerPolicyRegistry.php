<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Closure;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageSchemaEvolutionTransactionScope;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;
use WeakMap;

final class StorageSchemaEvolutionOwnerPolicyRegistry
{
    /** @var array<string, array{schema_prefix:?string,validator:Closure(StorageSchemaEvolutionOwnerContext, ?object):void}> */
    private array $policies = [];

    /** @var WeakMap<StorageSchemaEvolutionTransactionScope, ConnectionInterface> */
    private WeakMap $transactionScopes;

    private bool $sealed = false;

    public function __construct()
    {
        $this->transactionScopes = new WeakMap();
    }

    /** @param callable(StorageSchemaEvolutionOwnerContext, ?object):void $validator */
    public function protect(string $ownerPackage, callable $validator, ?string $schemaPrefix = null): void
    {
        if ($this->sealed) {
            throw new InvalidArgumentException('storage_schema_evolution_owner_policy_registry_sealed');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]*\/[a-z][a-z0-9_.-]*$/', $ownerPackage) !== 1
            || ($schemaPrefix !== null
                && preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $schemaPrefix) !== 1)) {
            throw new InvalidArgumentException('storage_schema_evolution_owner_policy_invalid');
        }
        if (isset($this->policies[$ownerPackage])) {
            throw new InvalidArgumentException('storage_schema_evolution_owner_policy_already_registered');
        }

        $this->policies[$ownerPackage] = [
            'schema_prefix' => $schemaPrefix,
            'validator' => Closure::fromCallable($validator),
        ];
    }

    public function seal(): void
    {
        $this->sealed = true;
    }

    public function isSealed(): bool
    {
        return $this->sealed;
    }

    /**
     * @template TResult
     * @param Closure(StorageSchemaEvolutionTransactionScope): TResult $operation
     * @return TResult
     */
    public function withinTransaction(ConnectionInterface $connection, Closure $operation): mixed
    {
        if (!$this->sealed || $connection->transactionLevel() < 1) {
            throw new StorageRejected('storage_schema_migration_owner_orchestration_required');
        }

        $scope = new StorageSchemaEvolutionTransactionScope();
        $this->transactionScopes[$scope] = $connection;

        try {
            return $operation($scope);
        } finally {
            unset($this->transactionScopes[$scope]);
        }
    }

    public function authorize(
        string $ownerPackage,
        StorageSchemaEvolutionOwnerContext $context,
        ?object $capability,
    ): void {
        if (!$this->sealed) {
            throw new StorageRejected('storage_schema_migration_owner_registry_unsealed');
        }

        $policy = $this->policies[$ownerPackage] ?? null;
        if ($policy === null
            || ($policy['schema_prefix'] !== null
                && !str_starts_with($context->source->schemaId, $policy['schema_prefix']))) {
            return;
        }

        $scope = $context->transactionScope;
        try {
            if (!$scope instanceof StorageSchemaEvolutionTransactionScope
                || !isset($this->transactionScopes[$scope])
                || $this->transactionScopes[$scope] !== $context->connection
                || $context->connection->transactionLevel() < 1) {
                throw new InvalidArgumentException('scope_invalid');
            }
            ($policy['validator'])($context, $capability);
        } catch (Throwable) {
            throw new StorageRejected('storage_schema_migration_owner_orchestration_required');
        }
    }
}
