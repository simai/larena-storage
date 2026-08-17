<?php

declare(strict_types=1);

namespace Larena\Storage\Compatibility\Audit;

use InvalidArgumentException;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Storage\Audit\StorageSchemaMigrationAuditEventDescriptor;
use Larena\Storage\Audit\StorageVersionAuditEventDescriptor;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;

final readonly class AuditStorageSecurityEventSink implements StorageSecurityEventSink
{
    private function __construct(private AuditEventPipeline $audit)
    {
    }

    public static function fromObject(object $audit): self
    {
        if (!$audit instanceof AuditEventPipeline) {
            throw new InvalidArgumentException('storage_security_event_sink_invalid');
        }

        return new self($audit);
    }

    public function emit(StorageSecurityEvent $event): void
    {
        $descriptor = $event->stream === 'schema_migration'
            ? new StorageSchemaMigrationAuditEventDescriptor($event->type)
            : new StorageVersionAuditEventDescriptor($event->type);

        $this->audit->route($descriptor, AuditEvent::create(
            sourcePackage: $descriptor->sourcePackage(),
            category: $descriptor->category(),
            type: $descriptor->type(),
            actor: $event->actor,
            subject: $event->subject,
            severity: AuditSeverity::Security,
            retentionClass: AuditRetentionClass::Security,
            correlationId: $event->correlationId,
            payload: $event->payload,
        ));
    }
}
