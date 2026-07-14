<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Illuminate\Database\ConnectionInterface;

final readonly class StorageSchemaEvolutionOwnerContext
{
    public function __construct(
        public string $operation,
        public string $actor,
        public StorageSchemaVersionRef $source,
        public string $sourceHash,
        public string $targetHash,
        public ?string $planRef,
        public ?string $planHash,
        public ConnectionInterface $connection,
        public ?StorageSchemaEvolutionTransactionScope $transactionScope,
    ) {
    }
}
