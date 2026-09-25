<?php

declare(strict_types=1);

namespace Larena\Storage\Audit;

/**
 * Audit event names for publication transitions. Each event carries actor, record,
 * scope, locale, revision and correlation id — never a field value, because a
 * publication event may be read by someone who cannot read the record.
 */
final class PublicationAuditEventCatalog
{
    public const PUBLISHED = 'storage.publication.published';

    public const UNPUBLISHED = 'storage.publication.unpublished';

    public const SCHEDULED = 'storage.publication.scheduled';

    public const ARCHIVED = 'storage.publication.archived';

    public const SWEPT = 'storage.publication.swept';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PUBLISHED, self::UNPUBLISHED, self::SCHEDULED, self::ARCHIVED, self::SWEPT];
    }
}
