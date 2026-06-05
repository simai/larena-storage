<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Storage\Contracts\StorageAuditEmitter;
use Larena\Storage\Contracts\StorageMutation;
use Larena\Storage\Contracts\StoragePersistenceAdapter;
use Larena\Storage\Enums\StorageDecisionStatus;

final readonly class AuditAwareStorageMutationRuntime implements StorageAuditEmitter
{
    public function __construct(
        private StoragePersistenceAdapter $persistence,
        private AuditEventPipeline $auditPipeline,
        private StorageTransactionBoundary $transactionBoundary = new StorageTransactionBoundary(),
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function mutate(StorageMutation $mutation, string $actor, array $context = []): StorageDecisionStatus
    {
        $validation = $this->persistence->validateMutation($mutation);
        $decision = $this->persistence->decideMutation($mutation, $validation);
        if (!$decision->permitsDataAccess()) {
            return $decision;
        }

        return $this->transactionBoundary->run(function () use ($mutation, $actor, $context): StorageDecisionStatus {
            $mutationDecision = $this->persistence->mutate($mutation);
            if ($mutationDecision->permitsDataAccess()) {
                $this->emitMutation($mutation, $mutationDecision, $actor, $context);
            }

            return $mutationDecision;
        });
    }

    public function emitMutation(StorageMutation $mutation, StorageDecisionStatus $decision, string $actor, array $context = []): ?AuditEvent
    {
        if (!$decision->permitsDataAccess()) {
            return null;
        }

        $descriptor = new StorageAuditEventDescriptor($mutation->type());
        $event = AuditEvent::create(
            sourcePackage: $descriptor->sourcePackage(),
            category: $descriptor->category(),
            type: $descriptor->type(),
            actor: $actor,
            subject: sprintf('storage:%s:%s', $mutation->schemaId(), $mutation->recordId() ?? 'new'),
            severity: $descriptor->severity(),
            retentionClass: $descriptor->retentionClass(),
            correlationId: (string) ($context['correlation_id'] ?? sprintf('storage:%s:%s', $mutation->schemaId(), $mutation->recordId() ?? 'new')),
            payload: StorageAuditPayload::fromMutation($mutation, $decision),
        );

        return $this->auditPipeline->route($descriptor, $event);
    }
}
