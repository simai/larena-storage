<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

/**
 * A site page's address (larena-specs docs/architecture/2026-10-29-sites-pages-languages.md §4.3, owner decisions 12 and
 * 35): the path of its slugs from the site's root, without a prefix. A top-level page with the slug `home` is the site's
 * Home, at `/`, and the pages under it are top-level addresses: Home's children and the other top-level pages are one
 * section, so `/about` is one address whichever of the two holds it.
 */
final class SitePagePath
{
    public const ROLE = \Larena\Storage\FirstRun\StarterSite::ROLE_REF;

    public const RELATION = 'site_tree_parent';

    public const SLUG_FIELD = 'slug';

    public const ORDER_FIELD = 'order_index';

    public const HOME_SLUG = 'home';

    public const HOME_ADDRESS = '/';

    private const SLUG_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,119}\z/D';

    private const MAX_LENGTH = 2048;

    public static function isSlug(mixed $slug): bool
    {
        return is_string($slug) && preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /** A request path as an address (`/about/` is `/about`), or null when no page can have it, with no read at all. */
    public static function normalize(string $path): ?string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return self::HOME_ADDRESS;
        }
        if (strlen($path) > self::MAX_LENGTH) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if (!self::isSlug($segment)) {
                return null;
            }
        }

        return '/' . $path;
    }

    /** The address of the top-level page with this slug. */
    public static function top(string $slug): string
    {
        return $slug === self::HOME_SLUG ? self::HOME_ADDRESS : '/' . $slug;
    }

    /** The address of the page with this slug under the page at $parent. */
    public static function under(string $parent, string $slug): string
    {
        return ($parent === self::HOME_ADDRESS ? '' : $parent) . '/' . $slug;
    }

    /**
     * The address of a chain of slugs, root first; null when one is not a slug.
     *
     * @param list<mixed> $slugs
     */
    public static function of(array $slugs): ?string
    {
        $address = null;
        foreach ($slugs as $slug) {
            if (!self::isSlug($slug)) {
                return null;
            }
            $address = $address === null ? self::top($slug) : self::under($address, $slug);
        }

        return $address;
    }
}
