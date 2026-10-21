<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

use InvalidArgumentException;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;

/**
 * One storage encryption event: `storage.field.revealed`, an encrypted value shown in plaintext. The payload names
 * the storage, the record and the revealed field keys (`revealed_fields`); a value, a ciphertext, a nonce or a key is
 * a forbidden field.
 */
final readonly class StorageEncryptionAuditEventDescriptor implements AuditEventDescriptor
{
    public const REVEALED = 'storage.field.revealed';

    public function __construct(private string $eventType)
    {
        if (!in_array($eventType, self::all(), true)) {
            throw new InvalidArgumentException('storage_audit_event_type_invalid');
        }
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::REVEALED];
    }

    public function sourcePackage(): string
    {
        return 'larena/storage';
    }

    public function category(): string
    {
        return 'storage_encryption';
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
            'value', 'values', 'field_value', 'field_values', 'raw_value', 'raw_values', 'plaintext', 'ciphertext',
            'envelope', 'envelopes', 'nonce', 'key', 'data_key', 'wrapped_key', 'payload', 'content', 'secret', 'token',
            'credential', 'password',
        ];
    }

    public function isExperimental(): bool
    {
        return false;
    }
}
