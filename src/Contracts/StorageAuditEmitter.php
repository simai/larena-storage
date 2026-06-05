<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Audit\Contracts\AuditEvent;
use Larena\Storage\Enums\StorageDecisionStatus;

interface StorageAuditEmitter
{
    /**
     * @param array<string, mixed> $context
     */
    public function emitMutation(StorageMutation $mutation, StorageDecisionStatus $decision, string $actor, array $context = []): ?AuditEvent;
}
