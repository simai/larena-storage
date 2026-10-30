<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\PublicationGuard;

/** Every guard a revision must pass, in order: the first that refuses decides. */
final readonly class PublicationGuardChain implements PublicationGuard
{
    /** @param list<PublicationGuard> $guards */
    public function __construct(private array $guards)
    {
    }

    public function assertMayPublish(string $schemaId, string $recordId, string $scopeRef, string $locale, int $revision): void
    {
        foreach ($this->guards as $guard) {
            $guard->assertMayPublish($schemaId, $recordId, $scopeRef, $locale, $revision);
        }
    }
}
