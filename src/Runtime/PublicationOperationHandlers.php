<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Storage\Audit\PublicationAuditEventCatalog;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\PublicationTransition;
use Larena\Storage\Exceptions\PublicationRejected;

/**
 * The eight publication operations.
 *
 * Publish, unpublish, schedule and archive are four operations with four access
 * codes, not one with a mode argument. That is what lets an editor save and schedule
 * without holding the right to publish, which is the entire reason the accepted
 * target state asks for them separately.
 *
 * The sweep is `bulk`, so the confirmation policy asks before a human runs it by
 * hand; a worker calling it runs with its own approval.
 */
final readonly class PublicationOperationHandlers implements OperationProposalHandler
{
    public function __construct(private PublicationLifecycle $publication)
    {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $read = 'storage.publication.read';

        $descriptors = [
            new OperationDescriptor(
                name: 'storage.publication.publish',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.publication.publish',
                auditEvent: PublicationAuditEventCatalog::PUBLISHED,
                idempotencyKey: 'publication_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.publication.unpublish',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.publication.unpublish',
                auditEvent: PublicationAuditEventCatalog::UNPUBLISHED,
                idempotencyKey: 'publication_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.publication.schedule',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.publication.schedule',
                auditEvent: PublicationAuditEventCatalog::SCHEDULED,
                idempotencyKey: 'publication_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.publication.archive',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.publication.archive',
                auditEvent: PublicationAuditEventCatalog::ARCHIVED,
                idempotencyKey: 'publication_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.publication.sweep',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.publication.publish',
                auditEvent: PublicationAuditEventCatalog::SWEPT,
                idempotencyKey: 'correlation_id',
                transactional: true,
                riskClass: OperationRiskClass::Bulk,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.publication.head',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.publication.history',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.publication.explain',
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
            'storage.publication.publish' => ['state' => $this->publication->publish(
                ...[...$this->target($context), $this->int($context, 'revision'), $context->actorId, $context->correlationId],
            )->toArray()],
            'storage.publication.unpublish' => ['state' => $this->publication->unpublish(
                ...[...$this->target($context), $context->actorId, $context->correlationId],
            )->toArray()],
            'storage.publication.schedule' => ['state' => $this->publication->schedule(
                ...[
                    ...$this->target($context),
                    $this->int($context, 'revision'),
                    $this->string($context, 'scheduled_at'),
                    $context->actorId,
                    $context->correlationId,
                ],
            )->toArray()],
            'storage.publication.archive' => ['state' => $this->publication->archive(
                ...[...$this->target($context), $context->actorId, $context->correlationId],
            )->toArray()],
            'storage.publication.sweep' => $this->publication->sweep(
                $context->actorId,
                $this->optionalString($context, 'now'),
                $this->optionalInt($context, 'limit') ?? DatabasePublicationLifecycle::SWEEP_LIMIT,
                $context->correlationId,
            ),
            'storage.publication.head' => ['state' => $this->publication->head(...$this->target($context))?->toArray()],
            'storage.publication.history' => ['transitions' => array_map(
                static fn (PublicationTransition $transition): array => $transition->toArray(),
                $this->publication->history(
                    ...[...$this->target($context), $this->optionalInt($context, 'limit') ?? 100],
                ),
            )],
            'storage.publication.explain' => $this->publication->explain(...$this->target($context)),
            default => throw new PublicationRejected(
                'unknown_operation',
                sprintf('Operation "%s" is not a publication operation.', $descriptor->name),
            ),
        };
    }

    /**
     * Describe the transition without making it.
     *
     * The useful thing to see before publishing is what is live right now and which
     * transitions are legal from here, so the proposal carries the explain payload
     * rather than a restatement of the request.
     *
     * @return array<string, mixed>
     */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $transitions = [
            'storage.publication.publish' => 'publish',
            'storage.publication.unpublish' => 'unpublish',
            'storage.publication.schedule' => 'schedule',
            'storage.publication.archive' => 'archive',
        ];

        if ($descriptor->name === 'storage.publication.sweep') {
            // A sweep proposal is a dry run: which schedules are due right now. It
            // reads the same rows the sweep would write and writes none of them.
            $due = $this->publication->dueSchedules(
                $this->optionalString($context, 'now'),
                $this->optionalInt($context, 'limit') ?? DatabasePublicationLifecycle::SWEEP_LIMIT,
            );

            return [
                'kind' => 'sweep',
                'would_publish' => array_map(
                    static fn ($state): array => [
                        'publication_id' => $state->publicationId,
                        'scheduled_at' => $state->scheduledAt,
                    ],
                    $due,
                ),
                'would_publish_count' => count($due),
                'note' => 'a proposal never publishes; run the operation to apply it',
            ];
        }

        if (!isset($transitions[$descriptor->name])) {
            throw new PublicationRejected(
                'proposal_unsupported',
                sprintf('Operation "%s" has nothing to propose.', $descriptor->name),
            );
        }

        return [
            'kind' => $transitions[$descriptor->name],
            'current' => $this->publication->explain(...$this->target($context)),
            'revision' => $this->optionalInt($context, 'revision'),
            'scheduled_at' => $this->optionalString($context, 'scheduled_at'),
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function target(OperationContext $context): array
    {
        return [
            $this->string($context, 'schema_id'),
            $this->string($context, 'record_id'),
            $this->string($context, 'scope_ref'),
            $this->string($context, 'locale'),
        ];
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new PublicationRejected('invalid_input', sprintf('Publication operations require a non-empty "%s".', $key));
        }

        return $value;
    }

    private function optionalString(OperationContext $context, string $key): ?string
    {
        $value = $context->metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function int(OperationContext $context, string $key): int
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_int($value)) {
            throw new PublicationRejected('invalid_input', sprintf('Publication operations require an integer "%s".', $key));
        }

        return $value;
    }

    private function optionalInt(OperationContext $context, string $key): ?int
    {
        $value = $context->metadata[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
