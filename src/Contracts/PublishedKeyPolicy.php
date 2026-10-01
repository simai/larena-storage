<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Which field of a structure is a key that `resolveKey` routes by.
 *
 * The key belongs to whoever routes by it — a site route resolves pages by slug —
 * so the application declares it. Storage then keeps it unique where it must be:
 * the structure, the scope and the locale.
 */
interface PublishedKeyPolicy
{
    /** The key field of a structure in a scope, or null when it has none. */
    public function keyFieldFor(string $schemaId, string $scopeRef): ?string;
}
