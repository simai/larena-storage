<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;
use Larena\Storage\Enums\MutationType;

final readonly class StorageAuditEventDescriptor implements AuditEventDescriptor
{
    public function __construct(private MutationType $mutationType)
    {
    }

    public function sourcePackage(): string
    {
        return 'larena/storage';
    }

    public function category(): string
    {
        return 'storage.record';
    }

    public function type(): string
    {
        return match ($this->mutationType) {
            MutationType::Create => 'storage.record_created',
            MutationType::Update => 'storage.record_updated',
            MutationType::Delete => 'storage.record_deleted',
            MutationType::Restore => 'storage.record_restored',
        };
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Notice;
    }

    public function retentionClass(): AuditRetentionClass
    {
        return AuditRetentionClass::Operational;
    }

    public function redactedPayloadFields(): array
    {
        return [];
    }

    public function forbiddenPayloadFields(): array
    {
        return StorageAuditPayload::forbiddenRawPayloadFields();
    }

    public function isExperimental(): bool
    {
        return false;
    }
}
