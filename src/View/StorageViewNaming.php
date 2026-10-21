<?php

declare(strict_types=1);

namespace Larena\Storage\View;

/**
 * Names of a storage's витрина: its table, the side tables for multi-values and
 * for the search text, its columns and its indexes. A name depends only on the schema id, so the same
 * storage always maps to the same table, and a column name only on an ordinal
 * that the layout never hands out twice.
 *
 * A published storage has a site витрина too (StorageViewTableShape::Site), named
 * the same way under its own prefix, with the same side-table suffixes.
 */
final class StorageViewNaming
{
    public const TABLE_PREFIX = 'larena_storage_view_';
    public const SITE_PREFIX = 'larena_storage_site_';
    public const MULTI_SUFFIX = '_m';
    public const SEARCH_SUFFIX = '_s';
    private const INDEX_PREFIX = 'lsv_';

    public static function table(string $schemaId): string
    {
        return self::TABLE_PREFIX . substr(hash('sha256', $schemaId), 0, 24);
    }

    /** The site витрина of a published storage: its public fields, one row per published record and slot. */
    public static function siteTable(string $schemaId): string
    {
        return self::SITE_PREFIX . substr(hash('sha256', $schemaId), 0, 24);
    }

    /** The table of a storage's витрина of the given shape. */
    public static function tableFor(string $schemaId, ?StorageViewTableShape $shape = null): string
    {
        return StorageViewTableShape::of($shape) === StorageViewTableShape::Site ? self::siteTable($schemaId) : self::table($schemaId);
    }

    public static function multiTable(string $table): string
    {
        return $table . self::MULTI_SUFFIX;
    }

    /** The side table of the search text: one row per record, with the FULLTEXT index on MySQL. */
    public static function searchTable(string $table): string
    {
        return $table . self::SEARCH_SUFFIX;
    }

    public static function column(int $ordinal): string
    {
        if ($ordinal < 1) {
            throw new \InvalidArgumentException('storage_view_column_ordinal_invalid');
        }

        return 'c' . $ordinal;
    }

    /**
     * Index names are global in SQLite and limited to 64 characters in MySQL, so
     * they are hashed from the table and what the index is for.
     */
    public static function index(string $table, string $purpose): string
    {
        return self::INDEX_PREFIX . substr(hash('sha256', $table . '|' . $purpose), 0, 24);
    }

    public static function isTable(string $name): bool
    {
        return self::matches(self::TABLE_PREFIX, $name);
    }

    /** A site витрина table or one of its side tables. */
    public static function isSiteTable(string $name): bool
    {
        return self::matches(self::SITE_PREFIX, $name);
    }

    /** A витрина table of the given shape (admin when none is given). */
    public static function isTableOf(string $name, ?StorageViewTableShape $shape = null): bool
    {
        return StorageViewTableShape::of($shape) === StorageViewTableShape::Site ? self::isSiteTable($name) : self::isTable($name);
    }

    /**
     * A LIKE pattern that matches the витрина tables of one shape only (admin when
     * none is given). The underscores of the prefix are escaped (with a backslash, to
     * be used with ESCAPE '\'), because an unescaped `_` matches any character. The
     * site prefix also matches the site registry tables; callers keep the names that
     * isTableOf() accepts.
     */
    public static function tableLikePattern(?StorageViewTableShape $shape = null): string
    {
        return self::escapeLike(StorageViewTableShape::of($shape) === StorageViewTableShape::Site ? self::SITE_PREFIX : self::TABLE_PREFIX) . '%';
    }

    private static function matches(string $prefix, string $name): bool
    {
        return preg_match('/\A' . preg_quote($prefix, '/') . '[0-9a-f]{24}(?:' . preg_quote(self::MULTI_SUFFIX, '/')
            . '|' . preg_quote(self::SEARCH_SUFFIX, '/') . ')?\z/D', $name) === 1;
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
