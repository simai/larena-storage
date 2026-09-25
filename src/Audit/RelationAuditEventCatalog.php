<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

/**
 * Audit event names for relation mutations. A move emits exactly one event
 * carrying the moved record set, never the field values of any record.
 */
final class RelationAuditEventCatalog
{
    public const DEFINED = 'storage.relation.defined';

    public const MOVED = 'storage.tree.moved';

    public const DELETED = 'storage.relation.deleted';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DEFINED, self::MOVED, self::DELETED];
    }
}
