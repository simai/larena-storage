<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

use InvalidArgumentException;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;

/**
 * One structure role change: a role version registered, a structure bound, or a
 * binding moved to a newer version. The payload names roles, structures and scopes.
 */
final readonly class StructureRoleAuditEventDescriptor implements AuditEventDescriptor
{
    public function __construct(private string $eventType)
    {
        if (!in_array($eventType, StructureRoleAuditEventCatalog::all(), true)) {
            throw new InvalidArgumentException('storage_audit_event_type_invalid');
        }
    }

    public function sourcePackage(): string
    {
        return 'larena/storage';
    }

    public function category(): string
    {
        return 'storage_structure_role';
    }

    public function type(): string
    {
        return $this->eventType;
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Security;
    }

    public function retentionClass(): AuditRetentionClass
    {
        return AuditRetentionClass::Security;
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
