<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

/**
 * Audit event names for localized value writes. The event carries the record, the
 * revision, the locale and which field keys were written — never the values, which
 * are content and in some schemas protected content.
 */
final class LocalizedValueAuditEventCatalog
{
    public const WRITTEN = 'storage.locale.written';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::WRITTEN];
    }
}
