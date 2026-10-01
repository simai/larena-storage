<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

/**
 * Audit event names for structure role mutations. Sanitized: actor, role, schema,
 * scope and correlation id only — never a field value.
 */
final class StructureRoleAuditEventCatalog
{
    public const REGISTERED = 'storage.role.registered';

    public const BOUND = 'storage.role.bound';

    public const MIGRATED = 'storage.role.migrated';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::REGISTERED, self::BOUND, self::MIGRATED];
    }
}
