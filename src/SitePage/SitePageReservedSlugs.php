<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

/**
 * The first segments a top-level page may not take: what the application's routes and public folder already answer,
 * read from them, on top of the addresses always kept for it, whether or not their package is installed here.
 */
final class SitePageReservedSlugs
{
    public const ALWAYS = ['admin', 'api', 'up', 'storage', 'larena', 'search', 'sitemap', 'site', 'media', 'assets', 'vendor', 'build', 'robots', 'favicon', 'index'];

    /**
     * @param iterable<string> $routeUris the application's route addresses
     * @param iterable<string> $publicEntries the names in its public folder
     * @return list<string>
     */
    public static function from(iterable $routeUris, iterable $publicEntries = []): array
    {
        $reserved = array_fill_keys(self::ALWAYS, true);
        foreach ($routeUris as $uri) {
            $segment = self::segment(explode('/', trim($uri, '/'), 2)[0]);
            if ($segment !== '') {
                $reserved[$segment] = true;
            }
        }
        foreach ($publicEntries as $entry) {
            $segment = self::segment($entry);
            if ($segment !== '') {
                $reserved[$segment] = true;
            }
        }

        return array_map('strval', array_keys($reserved));
    }

    /**
     * The reserved segments and a site's languages, whose codes are its folders (sites plan, step 4.3).
     *
     * @param list<string> $reserved
     * @param list<string> $languages
     * @return list<string>
     */
    public static function withLanguages(array $reserved, array $languages): array
    {
        return array_values(array_unique([...$reserved, ...array_map('strtolower', $languages)]));
    }

    /** `sitemap-pages-{file}.xml` is `sitemap-pages`, `robots.txt` is `robots`, a segment all of a parameter is nothing. */
    private static function segment(string $segment): string
    {
        $static = strstr($segment, '{', true);
        $static = $static === false ? $segment : $static;
        $dot = strpos($static, '.');
        $stem = $dot === false ? $static : substr($static, 0, $dot);

        return strtolower(rtrim($stem, '-_'));
    }
}
