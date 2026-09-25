<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

/**
 * Where a record stands in one scope and one locale.
 *
 * `Scheduled` is deliberately not a kind of published: a schedule records an
 * intention, and the head stays what it was until the sweep runs. A reader that
 * treated the two alike would publish a page early.
 */
enum PublicationStateValue: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function isPublished(): bool
    {
        return $this === self::Published;
    }
}
