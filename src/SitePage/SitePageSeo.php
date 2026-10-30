<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

/**
 * The fields for search engines of a page or a record with an address (sites plan, stage 7): a window title, a description,
 * a canonical address and «не индексировать», each per language. A canonical address is a path of the site or an https
 * address; anything else is refused when it is written (`site_page_canonical_invalid`).
 */
final class SitePageSeo
{
    public const TITLE = 'seo_title';

    public const DESCRIPTION = 'seo_description';

    public const CANONICAL = 'canonical';

    public const NOINDEX = 'noindex';

    public const CANONICAL_INVALID = 'site_page_canonical_invalid';

    public const FIELDS = [self::TITLE, self::DESCRIPTION, self::CANONICAL, self::NOINDEX];

    public static function validCanonical(string $value): bool
    {
        if ($value === '' || preg_match('/[\s<>"\\\\]/', $value) === 1) {
            return $value === '';
        }
        if (str_starts_with($value, '/')) {
            return !str_starts_with($value, '//');
        }

        return str_starts_with($value, 'https://') && is_string(parse_url($value, PHP_URL_HOST)) && parse_url($value, PHP_URL_HOST) !== '';
    }

    /**
     * Whether a page with these values stays out of a sitemap: closed to indexing, or pointing at another address.
     *
     * @param array<string, mixed> $values
     */
    public static function outOfSitemap(array $values, string $address): bool
    {
        $canonical = $values[self::CANONICAL] ?? null;

        return ($values[self::NOINDEX] ?? false) === true || (is_string($canonical) && $canonical !== '' && $canonical !== $address);
    }

    /**
     * Refuses a canonical address that is neither a site path nor https.
     *
     * @param array<string, mixed> $values
     */
    public static function whyNot(array $values): ?string
    {
        $canonical = $values[self::CANONICAL] ?? null;

        return is_string($canonical) && !self::validCanonical($canonical) ? self::CANONICAL_INVALID : null;
    }
}
