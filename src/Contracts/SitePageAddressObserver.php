<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Hears that site pages' addresses changed without a publication of their own: the pages under one that was renamed,
 * moved, published or taken back, and the moved page itself. What keeps an address elsewhere (a search index) follows.
 */
interface SitePageAddressObserver
{
    /** @param list<string> $recordIds at most SitePageAddressChanges::NOTIFY_LIMIT; a rebuild heals what a larger subtree leaves */
    public function addressesChanged(string $site, string $locale, array $recordIds): void;
}
