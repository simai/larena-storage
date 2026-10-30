<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Closure;

/**
 * The parent a page is being made under. A page is written first and placed in the tree after (the tree's edge names
 * its record), so the check of its write is told where it is going: its siblings there, not at the top level.
 */
final class SitePagePlacement
{
    private ?string $parent = null;

    /**
     * @template T
     * @param Closure(): T $write
     * @return T
     */
    public function within(?string $parent, Closure $write): mixed
    {
        $before = $this->parent;
        $this->parent = $parent;
        try {
            return $write();
        } finally {
            $this->parent = $before;
        }
    }

    public function parent(): ?string
    {
        return $this->parent;
    }
}
