<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

/**
 * Audit event names for storage SitePack runs. Each event names the run, scope, site and structure and carries the
 * archive digest, its size and the counts; never a field value or a file name.
 */
final class StorageSitePackAuditEventCatalog
{
    public const string EXPORTED = 'storage.sitepack.exported';

    public const string DOWNLOADED = 'storage.sitepack.downloaded';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EXPORTED, self::DOWNLOADED];
    }
}
