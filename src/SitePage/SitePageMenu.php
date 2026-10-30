<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\FirstRun\SitePagesStorage;
use Throwable;

/**
 * The published pages of a site in a language as a menu reads them: their titles, order, place in the tree and whether
 * they show in menus (`visible_in_menu`; a page with no such value shows). Only pages reachable in that language, as the
 * address map has them, with what a language without its own text shows: the source language's.
 *
 * Kept under the addresses' generation, which every publication of a page moves, so a menu on every page reads nothing
 * but the cache. Without a cache it is read once per request.
 */
final class SitePageMenu
{
    public const TTL_SECONDS = 86_400;

    /** The most pages a menu reads: a menu of more is no menu. */
    public const LIMIT = 500;

    private const CHUNK = 200;

    /** @var array<string, list<array{record: string, parent: ?string, title: string, order: int}>> */
    private array $read = [];

    public function __construct(
        private readonly SitePageAddressCache $addresses,
        private readonly SitePageTree $tree,
        private readonly DatabaseReadContracts $contracts,
        private readonly SitePageAddresses $source,
        private readonly ?CacheRepository $cache = null,
    ) {
    }

    /**
     * The pages that show in menus, in their order among their siblings.
     *
     * @return list<array{record: string, parent: ?string, title: string, order: int}>
     */
    public function pages(string $site, string $locale): array
    {
        $key = 'larena-storage.site-menu.'.$this->addresses->generation($site).'.'.$site.'.'.$locale;
        if (isset($this->read[$key])) {
            return $this->read[$key];
        }
        try {
            $found = $this->cache?->get($key);
        } catch (Throwable) {
            $found = null;
        }
        if (is_array($found)) {
            /** @var list<array{record: string, parent: ?string, title: string, order: int}> $found */
            return $this->read[$key] = $found;
        }
        $pages = $this->build($site, $locale);
        try {
            $this->cache?->put($key, $pages, self::TTL_SECONDS);
        } catch (Throwable) {
        }

        return $this->read[$key] = $pages;
    }

    /** @return list<array{record: string, parent: ?string, title: string, order: int}> */
    private function build(string $site, string $locale): array
    {
        $schema = $this->tree->schemaOf($site);
        if ($schema === null) {
            return [];
        }
        $records = array_slice(array_map('strval', array_keys($this->addresses->all($site, $locale))), 0, self::LIMIT);
        $source = $this->source->sourceLanguage($site, $locale);
        $values = [];
        foreach (array_chunk($records, self::CHUNK) as $chunk) {
            foreach ($source === null ? [$locale] : [$locale, $source] as $language) {
                $left = array_values(array_diff($chunk, array_map('strval', array_keys($values))));
                $revisions = $left === [] ? [] : $this->contracts->publishedRevisions($schema, $site, $language, $left);
                $heads = array_map(static fn (string|int $id, int $revision): array => ['record_id' => (string) $id, 'revision' => $revision], array_keys($revisions), $revisions);
                foreach ($heads === [] ? [] : $this->contracts->entriesForHeads($schema, $site, $language, $heads) as $entry) {
                    $values[$entry['record_id']] = $entry['values'];
                }
            }
        }
        $parents = $this->tree->parents($schema);
        $pages = [];
        foreach ($values as $record => $page) {
            if (($page[SitePagesStorage::MENU_FIELD] ?? true) === false) {
                continue;
            }
            $pages[] = ['record' => (string) $record, 'parent' => $parents[$record] ?? null,
                'title' => is_scalar($page['title'] ?? null) ? (string) $page['title'] : (string) $record,
                'order' => is_int($page['order_index'] ?? null) ? $page['order_index'] : 0];
        }
        usort($pages, static fn (array $left, array $right): int => [$left['order'], $left['title'], $left['record']] <=> [$right['order'], $right['title'], $right['record']]);

        return $pages;
    }
}
