<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\PublishedKeyPolicy;

/** The default: no structure declares a key until the application says so. */
final readonly class NoPublishedKeys implements PublishedKeyPolicy
{
    public function keyFieldFor(string $schemaId, string $scopeRef): ?string
    {
        return null;
    }
}
