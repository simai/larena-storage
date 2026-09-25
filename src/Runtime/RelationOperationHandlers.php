<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Storage\Audit\RelationAuditEventCatalog;
use Larena\Storage\Contracts\RecordRelations;
use Larena\Storage\Contracts\RelationDescriptor;
use Larena\Storage\Enums\RelationDeletePolicy;
use Larena\Storage\Enums\RelationKind;
use Larena\Storage\Exceptions\RelationRejected;

/**
 * The six relation operations.
 *
 * A subtree move is declared `bulk`, so the confirmation policy always asks
 * before it runs: it rewrites the path of every descendant, and an editor who
 * dragged the wrong branch should find out before the write rather than after.
 */
final readonly class RelationOperationHandlers implements OperationProposalHandler
{
    public function __construct(private RecordRelations $relations)
    {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $manage = 'storage.relation.manage';
        $read = 'storage.relation.read';

        $descriptors = [
            new OperationDescriptor(
                name: 'storage.relation.define',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $manage,
                auditEvent: RelationAuditEventCatalog::DEFINED,
                idempotencyKey: 'relation_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.relation.resolve',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.tree.children',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.tree.ancestors',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.tree.move',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $manage,
                auditEvent: RelationAuditEventCatalog::MOVED,
                idempotencyKey: 'relation_id',
                transactional: true,
                riskClass: OperationRiskClass::Bulk,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.relation.explain',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
        ];

        $indexed = [];
        foreach ($descriptors as $descriptor) {
            $indexed[$descriptor->name] = $descriptor;
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'storage.relation.define' => ['relation' => $this->define($context)->toArray()],
            'storage.relation.resolve' => [
                'relations' => array_map(
                    static fn ($relation): array => $relation->toArray(),
                    $this->relations->resolve($this->string($context, 'relation_key'), $this->string($context, 'from_record_id')),
                ),
            ],
            'storage.tree.children' => $this->relations->children(
                $this->string($context, 'relation_key'),
                $this->string($context, 'parent_record_id'),
                $this->optionalInt($context, 'budget'),
            )->toArray(),
            'storage.tree.ancestors' => $this->relations->ancestors(
                $this->string($context, 'relation_key'),
                $this->string($context, 'record_id'),
                $this->optionalInt($context, 'budget'),
            )->toArray(),
            'storage.tree.move' => $this->relations->move(
                $this->string($context, 'relation_key'),
                $this->string($context, 'record_id'),
                $this->optionalString($context, 'new_parent_record_id'),
                $context->actorId,
                $this->optionalInt($context, 'order_index'),
                $context->correlationId,
            )->toArray(),
            'storage.relation.explain' => $this->relations->explain(
                $this->string($context, 'relation_key'),
                $this->string($context, 'record_id'),
            ),
            default => throw new RelationRejected(
                'unknown_operation',
                sprintf('Operation "%s" is not a relation operation.', $descriptor->name),
            ),
        };
    }

    /**
     * Describe the change without making it.
     *
     * For a move this is the current subtree: the set of records whose path the
     * move would rewrite, which is exactly what an editor needs to see before
     * dragging a branch.
     *
     * @return array<string, mixed>
     */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'storage.relation.define' => [
                'kind' => 'define_relation',
                'relation_key' => $this->string($context, 'relation_key'),
                'from_record_id' => $this->string($context, 'from_record_id'),
                'to_record_id' => $this->string($context, 'to_record_id'),
                'existing' => array_map(
                    static fn ($relation): array => $relation->toArray(),
                    $this->relations->resolve($this->string($context, 'relation_key'), $this->string($context, 'from_record_id')),
                ),
            ],
            'storage.tree.move' => [
                'kind' => 'move_subtree',
                'record_id' => $this->string($context, 'record_id'),
                'new_parent_record_id' => $this->optionalString($context, 'new_parent_record_id'),
                'current' => $this->relations->explain(
                    $this->string($context, 'relation_key'),
                    $this->string($context, 'record_id'),
                ),
                'subtree' => $this->relations->children(
                    $this->string($context, 'relation_key'),
                    $this->string($context, 'record_id'),
                )->toArray(),
            ],
            default => throw new RelationRejected(
                'proposal_unsupported',
                sprintf('Operation "%s" has nothing to propose.', $descriptor->name),
            ),
        };
    }

    private function define(OperationContext $context): \Larena\Storage\Contracts\RelationRecord
    {
        $kind = RelationKind::tryFrom($this->string($context, 'kind'))
            ?? throw new RelationRejected('invalid_input', 'Unknown relation kind.');
        $policy = RelationDeletePolicy::tryFrom($this->string($context, 'delete_policy'))
            ?? throw new RelationRejected('invalid_input', 'Unknown delete policy.');

        $descriptor = new RelationDescriptor(
            relationKey: $this->string($context, 'relation_key'),
            kind: $kind,
            deletePolicy: $policy,
            targetRoleCode: $this->optionalString($context, 'target_role_code'),
        );

        return $this->relations->define(
            $descriptor,
            $this->string($context, 'schema_id'),
            $this->string($context, 'from_record_id'),
            $this->string($context, 'to_record_id'),
            $context->actorId,
            $this->optionalInt($context, 'order_index'),
            $context->correlationId,
        );
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RelationRejected('invalid_input', sprintf('Relation operations require a non-empty "%s".', $key));
        }

        return $value;
    }

    private function optionalString(OperationContext $context, string $key): ?string
    {
        $value = $context->metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function optionalInt(OperationContext $context, string $key): ?int
    {
        $value = $context->metadata[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
