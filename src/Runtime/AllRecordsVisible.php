<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Closure;
use Larena\Storage\Contracts\RecordReadVisibility;

/**
 * The default: a caller with the relation read permission sees every record. A
 * composition with record scopes binds its own RecordReadVisibility.
 */
final readonly class AllRecordsVisible implements RecordReadVisibility
{
    public function filterFor(string $actor, string $relationKey): ?Closure
    {
        return null;
    }
}
