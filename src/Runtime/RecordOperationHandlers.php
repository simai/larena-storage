<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * Record create and update as registry operations, over the versioned storage
 * contract. An update is a compare-and-swap on the revision the caller read.
 */
final readonly class RecordOperationHandlers implements OperationProposalHandler
{
    public function __construct(private VersionedStorage $storage)
    {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        return [
            'storage.record.create' => new OperationDescriptor(
                name: 'storage.record.create',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.record.create',
                auditEvent: 'storage.record.created',
                idempotencyKey: 'owner_ref',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            'storage.record.update' => new OperationDescriptor(
                name: 'storage.record.update',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.record.update',
                auditEvent: 'storage.record.updated',
                idempotencyKey: 'record_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $schema = new StorageSchemaVersionRef($this->string($context, 'schema_id'), $this->positive($context, 'schema_version'));

        $written = match ($descriptor->name) {
            'storage.record.create' => $this->storage->create(
                $this->string($context, 'owner_ref'),
                $schema,
                $this->values($context),
                $context->actorId,
                $context->correlationId,
            ),
            'storage.record.update' => $this->storage->compareAndSwap(
                $this->string($context, 'owner_ref'),
                new StorageRecordVersionRef($schema->schemaId, $this->string($context, 'record_id'), $this->positive($context, 'expected_revision')),
                $schema,
                $this->values($context),
                $context->actorId,
                $context->correlationId,
            ),
            default => throw new StorageRejected('unknown_operation'),
        };

        return ['record' => [
            'schema_id' => $written->version->ref->schemaId,
            'record_id' => $written->version->ref->recordId,
            'revision' => $written->version->ref->revision,
            'owner_ref' => $this->string($context, 'owner_ref'),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return [
            'kind' => $descriptor->name === 'storage.record.create' ? 'create_record' : 'update_record',
            'schema_id' => $this->string($context, 'schema_id'),
            'owner_ref' => $this->string($context, 'owner_ref'),
            'fields' => array_keys($this->values($context)),
        ];
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new StorageRejected('invalid_input');
        }

        return $value;
    }

    private function positive(OperationContext $context, string $key): int
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new StorageRejected('invalid_input');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(OperationContext $context): array
    {
        $values = $context->metadata['values'] ?? null;
        if (!is_array($values) || $values === [] || array_is_list($values)) {
            throw new StorageRejected('invalid_input');
        }

        /** @var array<string, mixed> $values */
        return $values;
    }
}
