<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

use InvalidArgumentException;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;

/**
 * One localized value write. The payload names the record, revision, locale and
 * the field keys written; it never carries a value, which is content.
 */
final readonly class LocalizedValueAuditEventDescriptor implements AuditEventDescriptor
{
    public function __construct(private string $eventType)
    {
        if (!in_array($eventType, LocalizedValueAuditEventCatalog::all(), true)) {
            throw new InvalidArgumentException('storage_audit_event_type_invalid');
        }
    }

    public function sourcePackage(): string
    {
        return 'larena/storage';
    }

    public function category(): string
    {
        return 'storage_locale';
    }

    public function type(): string
    {
        return $this->eventType;
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Info;
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
        return ['fields', 'field', 'value', 'values', 'field_values', 'raw_value', 'payload', 'content', 'secret', 'token', 'credential', 'password'];
    }

    public function isExperimental(): bool
    {
        return false;
    }
}
