<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Illuminate\Database\ConnectionInterface;

interface StorageSchemaEvolution
{
    /**
     * A locking analysis is effective only inside an ambient database transaction.
     *
     * @param array<string, mixed> $candidateDefinition
     */
    public function analyze(
        StorageSchemaVersionRef $source,
        array $candidateDefinition,
        string $actor,
        ?string $correlationId = null,
        bool $forUpdate = false,
    ): StorageSchemaCompatibilityReport;

    /** @param array<string, mixed> $candidateDefinition */
    public function plan(
        StorageSchemaVersionRef $source,
        array $candidateDefinition,
        string $actor,
        ?string $correlationId = null,
        ?StorageSchemaEvolutionTransactionScope $transactionScope = null,
        ?object $orchestrationCapability = null,
    ): StorageSchemaMigrationPlan;

    public function explain(string $planRef, string $actor): StorageSchemaMigrationPlan;

    public function apply(
        string $planRef,
        string $expectedPlanHash,
        string $actor,
        ?string $correlationId = null,
        ?StorageSchemaEvolutionTransactionScope $transactionScope = null,
        ?object $orchestrationCapability = null,
    ): StorageSchemaMigrationResult;

    public function connection(): ConnectionInterface;
}
