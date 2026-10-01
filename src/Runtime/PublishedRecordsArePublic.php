<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Closure;
use Larena\Storage\Contracts\PublishedReadVisibility;

/**
 * The default answer: every published record is readable by anyone.
 *
 * The read contracts return only the published head's public fields, which is what
 * an anonymous visitor of the site sees anyway. A composition with record-level
 * rules binds its own PublishedReadVisibility; Access has no row scope yet.
 */
final readonly class PublishedRecordsArePublic implements PublishedReadVisibility
{
    public function filterFor(string $actor, string $roleRefOrSchemaId, string $scopeRef): ?Closure
    {
        return null;
    }
}
