<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Closure;

/**
 * Which published records one caller may read.
 *
 * Every read surface that does not hold the caller's own filter — the read operations
 * and the REST site boundary — asks this port and passes the answer to the read
 * contract, so a record hidden from a caller never reaches it by another door.
 */
interface PublishedReadVisibility
{
    /**
     * Null when the caller may read every published record of the target in the
     * scope; otherwise a predicate that receives a record id and answers true only
     * for a record the caller may read.
     *
     * @return (Closure(string): bool)|null
     */
    public function filterFor(string $actor, string $roleRefOrSchemaId, string $scopeRef): ?Closure;
}
