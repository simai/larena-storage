<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Larena\Storage\Contracts\PublicationObserver;
use Larena\Storage\Contracts\PublicationState;
use Throwable;

/**
 * The publication lifecycle tells one observer; Storage hears the changes itself (the site redirect maps) next to the
 * application's (Search, the sitemap). Each hears every change, and one that fails does not silence the next.
 */
final readonly class PublicationObserverChain implements PublicationObserver
{
    /** @param list<PublicationObserver> $observers */
    public function __construct(private array $observers)
    {
    }

    public function publicationChanged(PublicationState $state, int $projectionVersion): void
    {
        foreach ($this->observers as $observer) {
            try {
                $observer->publicationChanged($state, $projectionVersion);
            } catch (Throwable) {
                // An observer decides nothing; what it keeps heals on its own rebuild.
            }
        }
    }
}
