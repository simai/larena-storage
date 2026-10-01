<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Closure;

/**
 * Which records one caller may see when walking a relation tree.
 *
 * The tree operations ask this port and pass the answer to RecordRelations, so a
 * record outside the caller's scope is counted as filtered rather than shown.
 */
interface RecordReadVisibility
{
    /**
     * Null when the caller may see every record; otherwise a predicate that receives
     * a record id and answers true only for a record the caller may see.
     *
     * @return (Closure(string): bool)|null
     */
    public function filterFor(string $actor, string $relationKey): ?Closure;
}
