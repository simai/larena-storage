<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

use InvalidArgumentException;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;

final readonly class StorageSchemaMigrationAuditEventDescriptor implements AuditEventDescriptor
{
    public function __construct(private string $eventType)
    {
        if (!in_array($eventType, [
            'storage.schema_migration.analyzed',
            'storage.schema_migration.planned',
            'storage.schema_migration.applied',
            'storage.schema_migration.rejected',
        ], true)) {
            throw new InvalidArgumentException('storage_schema_migration_audit_event_type_invalid');
        }
    }

    public function sourcePackage(): string
    {
        return 'larena/storage';
    }

    public function category(): string
    {
        return 'storage_schema_migration';
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
        return [
            'definition', 'target_definition', 'fields', 'field', 'field_key', 'field_keys',
            'value', 'values', 'field_value', 'field_values', 'raw_value', 'raw_values',
            'payload', 'content', 'secret', 'token', 'credential', 'password',
        ];
    }

    public function isExperimental(): bool
    {
        return false;
    }
}
