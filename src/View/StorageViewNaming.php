<?php

declare(strict_types=1);

namespace Larena\Storage\View;

/**
 * Names of a storage's витрина: its table, the side tables for multi-values and
 * for the search text, its columns and its indexes. A name depends only on the schema id, so the same
 * storage always maps to the same table, and a column name only on an ordinal
 * that the layout never hands out twice.
 */
final class StorageViewNaming
{
    public const TABLE_PREFIX = 'larena_storage_view_';
    public const MULTI_SUFFIX = '_m';
    public const SEARCH_SUFFIX = '_s';
    private const INDEX_PREFIX = 'lsv_';

    public static function table(string $schemaId): string
    {
        return self::TABLE_PREFIX . substr(hash('sha256', $schemaId), 0, 24);
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
        return preg_match('/\A' . preg_quote(self::TABLE_PREFIX, '/') . '[0-9a-f]{24}(?:' . preg_quote(self::MULTI_SUFFIX, '/')
            . '|' . preg_quote(self::SEARCH_SUFFIX, '/') . ')?\z/D', $name) === 1;
    }

    /**
     * A LIKE pattern that matches the витрина tables only. The underscores of the
     * prefix are escaped (with a backslash, to be used with ESCAPE '\'), because an
     * unescaped `_` matches any character.
     */
    public static function tableLikePattern(): string
    {
        return self::escapeLike(self::TABLE_PREFIX) . '%';
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
