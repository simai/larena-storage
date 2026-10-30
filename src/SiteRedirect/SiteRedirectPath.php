<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * The addresses of a site redirect rule, in one form for writing and matching.
 *
 * A site path starts with `/`, has no empty segment (`//`), no `.` or `..` segment and no control character; it is
 * kept decoded (`/%D0%BE` and `/о` are one path) and without a trailing slash (`/about/` is `/about`; `/` stays). The
 * rule's `from_path` is such a path, without a query or fragment. Its `to_path` is a site path that may end with a
 * query and a fragment (only on an exact rule: a prefix swap appends the rest of the path), or an absolute https URL.
 */
final class SiteRedirectPath
{
    public const MATCH_EXACT = 'exact';

    public const MATCH_PREFIX = 'prefix';

    public const MAX_LENGTH = 512;

    /** The rule's `from_path`, normalized; refused with `site_redirect_from_invalid`. */
    public static function from(string $path): string
    {
        $path = trim($path);
        if ($path === '' || strpbrk($path, '?#') !== false) {
            throw new StorageRejected('site_redirect_from_invalid');
        }

        return self::path($path) ?? throw new StorageRejected('site_redirect_from_invalid');
    }

    /** The rule's `to_path`, normalized; refused with `site_redirect_to_invalid`. */
    public static function to(string $target, string $match): string
    {
        $target = trim($target);
        if (self::isAbsolute($target)) {
            if (strlen($target) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F]/', $target) === 1
                || filter_var($target, FILTER_VALIDATE_URL) === false || !is_string(parse_url($target, PHP_URL_HOST))
                || ($match === self::MATCH_PREFIX && strpbrk($target, '?#') !== false)) {
                throw new StorageRejected('site_redirect_to_invalid');
            }

            return $target;
        }
        [$path, $suffix] = self::split($target);
        if ($match === self::MATCH_PREFIX && $suffix !== '') {
            throw new StorageRejected('site_redirect_to_invalid');
        }
        $path = $target === '' ? null : self::path($path);
        if ($path === null || preg_match('/[\x00-\x20\x7F]/', $suffix) === 1 || strlen($path . $suffix) > self::MAX_LENGTH) {
            throw new StorageRejected('site_redirect_to_invalid');
        }

        return $path . $suffix;
    }

    /** Whether a target leaves the site: an absolute https URL. */
    public static function isAbsolute(string $target): bool
    {
        return str_starts_with(strtolower($target), 'https://');
    }

    /**
     * A target's path and what follows it (`?query#fragment`, or empty).
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $target): array
    {
        $at = strcspn($target, '?#');

        return [substr($target, 0, $at), substr($target, $at)];
    }

    /** A request path in the rules' form, or null when it is not one a rule can match. */
    public static function request(string $pathInfo): ?string
    {
        return self::path(rawurldecode($pathInfo === '' ? '/' : $pathInfo));
    }

    /** Whether a rule from $from with $match answers $path (both normalized). */
    public static function matches(string $from, string $match, string $path): bool
    {
        if ($path === $from) {
            return true;
        }

        return $match === self::MATCH_PREFIX && ($from === '/' || str_starts_with($path, $from . '/'));
    }

    /**
     * Where a rule sends $path, which it matches: an exact rule its target, a prefix rule its target with the rest of
     * the path after the prefix (`/old` → `/new` sends `/old/a/b` to `/new/a/b`).
     */
    public static function apply(string $from, string $to, string $match, string $path): string
    {
        if ($match !== self::MATCH_PREFIX || $path === $from) {
            return $to;
        }
        $rest = $from === '/' ? $path : substr($path, strlen($from));
        $base = rtrim($to, '/');

        return $base . $rest;
    }

    /** The Location of a site target: each path segment encoded, the query and fragment as written. */
    public static function location(string $target): string
    {
        if (self::isAbsolute($target)) {
            return $target;
        }
        [$path, $suffix] = self::split($target);

        return implode('/', array_map('rawurlencode', explode('/', $path))) . $suffix;
    }

    private static function path(string $path): ?string
    {
        $path = rawurldecode($path);
        if (!str_starts_with($path, '/') || str_contains($path, '//') || strlen($path) > self::MAX_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || preg_match('//u', $path) !== 1) {
            return null;
        }
        $path = $path === '/' ? '/' : rtrim($path, '/');
        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }
}
