<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use InvalidArgumentException;

final readonly class StorageMutationReceipt
{
    public function __construct(
        public string $actor,
        public string $occurredAt,
        public string $operation,
        public string $target,
        public int $revision,
        public string $result,
        public ?string $correlationId = null,
    ) {
        if (trim($actor) === ''
            || trim($occurredAt) === ''
            || !in_array($operation, ['create', 'update', 'delete', 'restore'], true)
            || preg_match('/^storage\.(?:record|structure):[a-z0-9][a-z0-9_.:-]{0,190}$/', $target) !== 1
            || $revision < 1
            || $result !== 'succeeded'
            || ($correlationId !== null && preg_match('/^storage-(?:record|schema|workbench)-[a-f0-9]{24,64}$/', $correlationId) !== 1)) {
            throw new InvalidArgumentException('storage_mutation_receipt_invalid');
        }
    }

    /** @return array{actor: string, time: string, operation: string, target: string, revision: int, result: string, correlation_id: ?string} */
    public function toArray(): array
    {
        return [
            'actor' => $this->actor,
            'time' => $this->occurredAt,
            'operation' => $this->operation,
            'target' => $this->target,
            'revision' => $this->revision,
            'result' => $this->result,
            'correlation_id' => $this->correlationId,
        ];
    }
}
