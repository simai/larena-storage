<?php

declare(strict_types=1);

namespace Larena\Storage\View;

/**
 * The text search of record lists, one rule for every path (the витрина on MySQL
 * and SQLite, and the PHP list):
 *
 * - a word is a run of letters, combining marks, digits and underscores; text is
 *   lowercased as mb_strtolower does;
 * - the search text of a record is the words of its searchable fields, each field
 *   a run of words separated by single spaces, the fields separated by the unit
 *   separator (so nothing matches across two fields), with a leading space;
 * - a query is split on whitespace into terms; each term is turned into its words
 *   the same way (so `sku-0001` is `sku 0001`), and a record matches when every
 *   term occurs in its search text at the start of a word: `chair` finds "Chair"
 *   and "chairs", not "armchair"; `oak ch` finds "Oak Chair". Terms match in any
 *   order and in any searchable field.
 *
 * In SQL a term is `search_text LIKE '% <words>%'` (binary on MySQL, so exact). On
 * MySQL the FULLTEXT index (InnoDB's default parser, which splits the stored text
 * at the same places) finds the candidate records first, with `+word*` for the
 * longest word of the query that has at least innodb_ft_min_token_size characters
 * (a longer word is usually the rarer one, and one word reads one posting list);
 * the LIKE then checks the candidates. Every record the LIKE accepts has that word
 * at the start of a word, so the index never finds fewer records than the LIKE,
 * and both ways of answering a list (StorageViewQuery) give the same records. A
 * query without a word that long has no index help and is checked on each row.
 */
final class StorageViewSearch
{
    public const MAX_TERMS = 16;
    // InnoDB's defaults, when the server cannot be asked.
    public const DEFAULT_MIN_TOKEN = 3;
    public const DEFAULT_MAX_TOKEN = 84;
    public const FIELD_SEPARATOR = "\x1F";

    private const WORD = '[\p{L}\p{M}\p{N}_]+';

    /**
     * The search text of the values of the searchable fields, or null when they hold
     * no word.
     *
     * @param list<string> $values
     */
    public static function text(array $values): ?string
    {
        $fields = [];
        foreach ($values as $value) {
            $words = self::words($value);
            if ($words !== []) {
                $fields[] = implode(' ', $words);
            }
        }

        return $fields === [] ? null : ' ' . implode(' ' . self::FIELD_SEPARATOR . ' ', $fields);
    }

    /** @return list<string> The lowercased words of a text. */
    public static function words(string $value): array
    {
        if (preg_match_all('/' . self::WORD . '/u', self::lower($value), $matches) === false) {
            return [];
        }

        return $matches[0];
    }

    /**
     * The terms of a query: each term's words joined by a space, distinct, at most
     * MAX_TERMS. A term without a word (punctuation alone) is dropped.
     *
     * @return list<string>
     */
    public static function terms(string $search): array
    {
        $parts = preg_split('/[\s\p{Z}]+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            $parts = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $terms = [];
        foreach ($parts as $part) {
            $words = self::words($part);
            if ($words !== []) {
                $terms[implode(' ', $words)] = true;
            }
        }

        return array_slice(array_map('strval', array_keys($terms)), 0, self::MAX_TERMS);
    }

    /** The LIKE pattern of a term: its words at the start of a word (escape character `!`). */
    public static function likePattern(string $term): string
    {
        return '% ' . StorageViewQueryCompiler::escapeLike($term) . '%';
    }

    /**
     * The boolean-mode query for the FULLTEXT index: `+word*` for the longest word
     * of the terms the index holds (between the server's minimum and maximum token
     * size; the first of equally long ones), or null when no word is long enough.
     *
     * @param list<string> $terms
     */
    public static function booleanQuery(array $terms, int $minToken = self::DEFAULT_MIN_TOKEN, int $maxToken = self::DEFAULT_MAX_TOKEN): ?string
    {
        $best = null;
        $bestLength = 0;
        foreach ($terms as $term) {
            foreach (explode(' ', $term) as $word) {
                $length = function_exists('mb_strlen') ? mb_strlen($word, 'UTF-8') : strlen($word);
                if ($word !== '' && $length >= max(1, $minToken) && $length <= $maxToken && $length > $bestLength) {
                    $best = $word;
                    $bestLength = $length;
                }
            }
        }

        return $best === null ? null : '+' . $best . '*';
    }

    /**
     * The PHP list's match: every term at the start of a word of the search text.
     *
     * @param list<string> $terms
     */
    public static function matches(?string $text, array $terms): bool
    {
        // A query of punctuation alone has no term and finds nothing.
        if ($terms === []) {
            return false;
        }
        foreach ($terms as $term) {
            if ($text === null || !str_contains($text, ' ' . $term)) {
                return false;
            }
        }

        return true;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
