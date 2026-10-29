<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Access;

use Larena\Access\Runtime\ScopeMembershipQueryScopeProvider;
use Larena\Storage\Admin\Contracts\StorageSectionScope;

/**
 * The Storage section's scope from access's scope-membership provider over core (scope move 2026-10-12): the
 * scope an administrator works in is their default site, and a file belongs to a site by its core binding. The
 * product composition decides whether to bind it.
 */
final readonly class ScopeMembershipStorageSectionScope implements StorageSectionScope
{
    public function __construct(private ScopeMembershipQueryScopeProvider $scopes) {}

    public function scopeForActor(string $actor): string
    {
        return $this->scopes->scopeForActor($actor);
    }

    public function fileBelongsToScope(string $logicalRef, string $scopeRef): bool
    {
        return $this->scopes->fileBelongsToScope($logicalRef, $scopeRef);
    }
}
