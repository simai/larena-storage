<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Each site's map of published redirect rules, kept twice: in the configured cache, across requests and processes,
 * and in this object for the process (the container keeps one per request), so a request answered from either reads
 * no database. A miss reads the rules once and stores the map.
 *
 * forget() drops a site's map from both, and is called on every change that can alter it: a publication transition
 * of a rule (SiteRedirectInvalidation) and every write of a rule (SiteRedirectValidator), after the transaction
 * commits, so a concurrent request cannot store the map of before. A cache that fails is skipped: the rules are read
 * again, and the map is not stored.
 */
final class SiteRedirectCache
{
    public const KEY_PREFIX = 'larena-storage.site-redirects.';

    /** @var array<string, SiteRedirectMap> */
    private array $maps = [];

    public function __construct(
        private readonly SiteRedirectRules $rules,
        private readonly ?CacheRepository $cache = null,
    ) {
    }

    public function map(string $siteRef): SiteRedirectMap
    {
        if (isset($this->maps[$siteRef])) {
            return $this->maps[$siteRef];
        }
        $key = self::KEY_PREFIX . $siteRef;
        if ($this->cache !== null) {
            try {
                $stored = $this->cache->get($key);
                $map = is_array($stored) ? SiteRedirectMap::fromArray($stored) : null;
                if ($map !== null) {
                    return $this->maps[$siteRef] = $map;
                }
            } catch (Throwable) {
            }
        }
        $map = SiteRedirectMap::of($this->rules->published($siteRef));
        if ($this->cache !== null) {
            try {
                $this->cache->forever($key, $map->toArray());
            } catch (Throwable) {
            }
        }

        return $this->maps[$siteRef] = $map;
    }

    public function forget(string $siteRef): void
    {
        unset($this->maps[$siteRef]);
        if ($this->cache !== null) {
            try {
                $this->cache->forget(self::KEY_PREFIX . $siteRef);
            } catch (Throwable) {
            }
        }
    }
}
