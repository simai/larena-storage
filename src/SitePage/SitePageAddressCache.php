<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Larena\Storage\Contracts\StructureRoleChoice;
use Throwable;

/**
 * Each site's addresses per language in the configured cache, so a public request finds its page without a database
 * read and without loading the site's whole map: the map is kept in SHARDS parts both ways (address => record, record
 * => address), and a request reads the site's generation and the one part its key falls in. A site of tens of thousands
 * of pages stays at some hundreds of entries a read; the part is kept for the process too (the container keeps one
 * object per request).
 *
 * Any change that can move an address (a publication of a page, a move, a translated slug) starts a new generation
 * (forget()), now and again after the transaction around it commits; a map being built meanwhile lands under the
 * old generation, so nobody reads it. Parts of old generations expire by TTL. A map is not stored from inside a
 * transaction, which could roll back. The first request after a change rebuilds the map (one walk of the published
 * pages), and nothing else is needed: an upgraded installation just builds it on its first request.
 *
 * With the database cache store a cache read is itself a query; those are the cache's, as for the redirect and site
 * maps. A cache that fails is skipped: the map is read for the request and not stored.
 */
final class SitePageAddressCache
{
    public const SHARDS = 64;

    public const TTL_SECONDS = 2_592_000;

    private const PREFIX = 'larena-storage.site-pages.';

    private const ADDRESSES = 'a';

    private const RECORDS = 'r';

    /** @var array<string, string> by site */
    private array $generations = [];

    /** @var array<string, array<string, string>> by part */
    private array $parts = [];

    /** @var array<string, array{0: array<string, string>, 1: array<string, string>}> whole maps read here, by site and language */
    private array $maps = [];

    public function __construct(
        private readonly SitePageTree $tree,
        private readonly SitePageAddresses $addresses,
        private readonly ?CacheRepository $cache = null,
        private readonly ?StructureRoleChoice $choice = null,
    ) {
    }

    /** The page at an address in a language, or null. */
    public function resolve(string $site, string $locale, string $address): ?string
    {
        return $this->entry($site, $locale, self::ADDRESSES, $address);
    }

    /** A page's address in a language, or null when it is not reachable there. */
    public function addressOf(string $site, string $locale, string $recordId): ?string
    {
        return $this->entry($site, $locale, self::RECORDS, $recordId);
    }

    /**
     * Every reachable page in a language: record => address. For what lists them all (the sitemap, an old address).
     *
     * @return array<string, string>
     */
    public function all(string $site, string $locale): array
    {
        $scope = $this->generation($site) . '.' . $site . '.' . $locale;
        if ($this->cache !== null && !isset($this->maps[$site . "\n" . $locale])) {
            try {
                $keys = array_map(fn (int $shard): string => self::PREFIX . $scope . '.' . self::RECORDS . $shard, range(0, self::SHARDS - 1));
                $found = $this->fetched([...$keys, self::PREFIX . $scope . '.built']);
                if (is_array($found[self::PREFIX . $scope . '.built'] ?? null)) {
                    $all = [];
                    foreach ($keys as $key) {
                        $all += is_array($found[$key] ?? null) ? $found[$key] : [];
                    }

                    return $all;
                }
            } catch (Throwable) {
            }
        }

        return $this->map($site, $locale)[1];
    }

    /** What changes whenever a site's addresses may have: what derived lists (the sitemap) key on. */
    public function generation(string $site): string
    {
        if (isset($this->generations[$site])) {
            return $this->generations[$site];
        }
        $generation = '0';
        if ($this->cache !== null) {
            try {
                $stored = $this->cache->get(self::PREFIX . 'generation.' . $site);
                $generation = is_string($stored) && $stored !== '' ? $stored : '0';
            } catch (Throwable) {
            }
        }
        // The site's «Дерево страниц» is read from the settings' cached entry, and only with several trees bound: it
        // has no event, so another choice simply finds another map.
        try {
            $chosen = $this->choice?->chosenSchema(SitePagePath::ROLE, $site);
        } catch (Throwable) {
            $chosen = null;
        }
        $generation .= $chosen === null ? '' : '-' . substr(hash('sha256', $chosen), 0, 12);

        return $this->generations[$site] = $generation;
    }

    /** A site's addresses may have changed: the next read builds them again. */
    public function forget(string $site): void
    {
        unset($this->generations[$site]);
        foreach (array_keys($this->parts) as $part) {
            if (str_contains($part, "\n" . $site . "\n")) {
                unset($this->parts[$part]);
            }
        }
        foreach (array_keys($this->maps) as $map) {
            if (str_starts_with($map, $site . "\n")) {
                unset($this->maps[$map]);
            }
        }
        if ($this->cache !== null) {
            try {
                $this->cache->forever(self::PREFIX . 'generation.' . $site, bin2hex(random_bytes(8)));
            } catch (Throwable) {
            }
        }
    }

    private function entry(string $site, string $locale, string $kind, string $key): ?string
    {
        $shard = crc32($key) % self::SHARDS;
        $part = $kind . "\n" . $site . "\n" . $locale . "\n" . $shard;
        if (!isset($this->parts[$part])) {
            $stored = $this->stored($site, $locale, $kind, $shard);
            if ($stored === null) {
                [$addresses, $records] = $this->map($site, $locale);
                $whole = $kind === self::ADDRESSES ? $addresses : $records;

                return $whole[$key] ?? null;
            }
            $this->parts[$part] = $stored;
        }

        return $this->parts[$part][$key] ?? null;
    }

    /** @return array<string, string>|null a part; empty when the map holds nothing there, null when it is not built */
    private function stored(string $site, string $locale, string $kind, int $shard): ?array
    {
        if ($this->cache === null) {
            return null;
        }
        $scope = self::PREFIX . $this->generation($site) . '.' . $site . '.' . $locale;
        try {
            $found = $this->fetched([$scope . '.' . $kind . $shard, $scope . '.built']);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($found[$scope . '.built'] ?? null)) {
            return null;
        }
        $part = $found[$scope . '.' . $kind . $shard] ?? null;

        return is_array($part) ? $part : [];
    }

    /**
     * One read of the cache, for a store that can do it at once (the database and Redis stores).
     *
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function fetched(array $keys): array
    {
        $found = [];
        foreach ($this->cache?->getMultiple($keys) ?? [] as $key => $value) {
            $found[(string) $key] = $value;
        }

        return $found;
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} address => record, record => address */
    private function map(string $site, string $locale): array
    {
        $key = $site . "\n" . $locale;
        if (isset($this->maps[$key])) {
            return $this->maps[$key];
        }
        // The generation first: a change during the walk leaves this map under the generation it replaced.
        $generation = $this->generation($site);
        $schemaId = $this->tree->schemaOf($site);
        $addresses = $schemaId === null ? [] : $this->addresses->build($schemaId, $site, $locale);
        $records = array_flip($addresses);
        if ($this->cache !== null && $this->tree->connection()->transactionLevel() === 0) {
            $this->store(self::PREFIX . $generation . '.' . $site . '.' . $locale, $addresses, $records);
        }

        return $this->maps[$key] = [$addresses, $records];
    }

    /**
     * @param array<string, string> $addresses
     * @param array<string, string> $records
     */
    private function store(string $scope, array $addresses, array $records): void
    {
        $entries = [];
        foreach ([self::ADDRESSES => $addresses, self::RECORDS => $records] as $kind => $map) {
            foreach ($map as $key => $value) {
                $entries[$scope . '.' . $kind . (crc32((string) $key) % self::SHARDS)][(string) $key] = $value;
            }
        }
        try {
            if ($entries !== []) {
                $this->cache?->setMultiple($entries, self::TTL_SECONDS);
            }
            // Last: the map answers only once all of it is there.
            $this->cache?->put($scope . '.built', ['pages' => count($addresses)], self::TTL_SECONDS);
        } catch (Throwable) {
        }
    }
}
