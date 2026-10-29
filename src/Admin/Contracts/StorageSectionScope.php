<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Contracts;

/**
 * The Storage scope an administrator works in. The product composition owns this answer:
 * which scope an actor sees and which files belong to it are decided by the application
 * that composes Admin with Storage, never by Admin itself.
 */
interface StorageSectionScope
{
    /** The actor has no scope here, or asked for another one. */
    public const SCOPE_DENIED = 'storage_page_scope_denied';

    /** The composition cannot place this actor in any scope. */
    public const SCOPE_UNSUPPORTED = 'storage_page_scope_unsupported';

    /**
     * The one scope the actor works in.
     *
     * @throws \RuntimeException with message SCOPE_DENIED or SCOPE_UNSUPPORTED when the actor has none
     */
    public function scopeForActor(string $actor): string;

    /** Whether a logical file may be referenced from records of the given scope. */
    public function fileBelongsToScope(string $logicalRef, string $scopeRef): bool;
}
