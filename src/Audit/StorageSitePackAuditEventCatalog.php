<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

/**
 * Audit event names for storage SitePack runs. Each event names the run, scope, site and structure and carries the
 * archive or plan digest, sizes and counts; never a field value or a file name.
 */
final class StorageSitePackAuditEventCatalog
{
    public const string EXPORTED = 'storage.sitepack.exported';

    public const string DOWNLOADED = 'storage.sitepack.downloaded';

    /** An import's plan was made: its digest and counts. */
    public const string IMPORT_PLANNED = 'storage.sitepack.import_planned';

    /** An import ended: the structure it created and what it wrote. */
    public const string IMPORTED = 'storage.sitepack.imported';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::EXPORTED, self::DOWNLOADED, self::IMPORT_PLANNED, self::IMPORTED];
    }
}
