<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Larena\Storage\Runtime\DatabaseReadContracts;

/**
 * Reads pages' addresses from the database: a whole site's (build(), what SitePageAddressCache keeps) and one page's
 * (addressOf(), for a change being made, never a stale copy). A page is at an address in a language only when it and
 * every page above it are published there: below an unpublished page there is nothing to reach. Two pages at one
 * address are a data defect the checks prevent; neither is served, since either would depend on row order.
 */
final readonly class SitePageAddresses
{
    private const CHUNK = 1000;

    public function __construct(private SitePageTree $tree, private DatabaseReadContracts $read)
    {
    }

    /**
     * Every reachable page of a site in a language: address => record.
     *
     * @return array<string, string>
     */
    public function build(string $schemaId, string $site, string $locale): array
    {
        $slugs = [];
        $after = null;
        do {
            $heads = $this->read->publishedHeadPage($schemaId, $site, $locale, self::CHUNK, $after);
            foreach ($this->read->keyValuesAt($schemaId, $heads, $locale, SitePagePath::SLUG_FIELD) as $recordId => $slug) {
                if (SitePagePath::isSlug($slug)) {
                    $slugs[(string) $recordId] = $slug;
                }
            }
            $after = $heads === [] ? null : $heads[array_key_last($heads)]['record_id'];
        } while (count($heads) === self::CHUNK);

        $parents = $this->tree->parents($schemaId);
        /** @var array<string, string|false> $known by record; false: not reachable */
        $known = [];
        $held = [];
        foreach ($slugs as $recordId => $slug) {
            $address = self::address((string) $recordId, $slugs, $parents, $known);
            if ($address !== false) {
                $held[$address][] = (string) $recordId;
            }
        }
        $addresses = [];
        foreach ($held as $address => $records) {
            if (count($records) === 1) {
                $addresses[(string) $address] = $records[0];
            }
        }

        return $addresses;
    }

    /** One page's address in a language, read now; null when it is not reachable there. */
    public function addressOf(string $schemaId, string $site, string $locale, string $recordId): ?string
    {
        $chain = [...$this->tree->ancestors($schemaId, $recordId), $recordId];
        $revisions = $this->read->publishedRevisions($schemaId, $site, $locale, $chain);
        if (count($revisions) !== count($chain)) {
            return null;
        }
        $heads = array_map(static fn (string $id): array => ['record_id' => $id, 'revision' => $revisions[$id]], $chain);
        $slugs = $this->read->keyValuesAt($schemaId, $heads, $locale, SitePagePath::SLUG_FIELD);

        return SitePagePath::of(array_map(static fn (string $id): mixed => $slugs[$id] ?? null, $chain));
    }

    /**
     * Where a page with this slug under $parent would stand, by the pages' current slugs: what a person sees of a page
     * not out yet, and the `target` field keeps. Null when a page above has no slug.
     */
    public function currentAddress(string $schemaId, ?string $parent, string $slug): ?string
    {
        $chain = $parent === null ? [] : [...$this->tree->ancestors($schemaId, $parent), $parent];
        $slugs = $this->tree->currentSlugs($schemaId, $chain);

        return SitePagePath::of([...array_map(static fn (string $id): mixed => $slugs[$id] ?? null, $chain), $slug]);
    }

    /**
     * @param array<string, string> $slugs
     * @param array<string, string> $parents
     * @param array<string, string|false> $known
     */
    private static function address(string $recordId, array $slugs, array $parents, array &$known): string|false
    {
        // The chain up to a page already known, climbed without recursion: a tree may be deep.
        $chain = [];
        $current = $recordId;
        $seen = [];
        while (!isset($known[$current]) && !isset($seen[$current])) {
            $seen[$current] = true;
            $chain[] = $current;
            if (!isset($parents[$current])) {
                break;
            }
            $current = $parents[$current];
        }
        foreach (array_reverse($chain) as $id) {
            $slug = $slugs[$id] ?? null;
            $parent = $parents[$id] ?? null;
            if ($slug === null || ($parent !== null && !is_string($known[$parent] ?? false))) {
                $known[$id] = false;
                continue;
            }
            $above = $parent === null ? null : $known[$parent];
            $known[$id] = is_string($above) ? SitePagePath::under($above, $slug) : SitePagePath::top($slug);
        }

        return $known[$recordId];
    }
}
