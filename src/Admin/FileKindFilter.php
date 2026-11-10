<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * A list filter on a file field by the kind of file (Framework file filter, owner-approved 2026-10-15): the
 * table sends image | document | archive | other with the operator «in». Storage filters a file field by the
 * files it names, so the kinds become the logical refs of the files of those kinds (by their mime type).
 */
final readonly class FileKindFilter
{
    public const KINDS = ['image', 'document', 'archive', 'other'];

    private const DOCUMENT = ['application/pdf', 'application/rtf', 'application/msword', 'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint', 'application/json', 'application/xml'];
    private const DOCUMENT_PREFIXES = ['text/', 'application/vnd.openxmlformats-officedocument.', 'application/vnd.oasis.opendocument.'];
    private const ARCHIVE = ['application/zip', 'application/x-zip-compressed', 'application/x-7z-compressed', 'application/x-rar-compressed',
        'application/vnd.rar', 'application/gzip', 'application/x-gzip', 'application/x-tar', 'application/x-bzip2', 'application/x-xz'];

    public function __construct(private ConnectionInterface $connection) {}

    /** The kind of a file by its mime type. */
    public static function kindOf(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            in_array($mime, self::ARCHIVE, true) => 'archive',
            in_array($mime, self::DOCUMENT, true) || array_filter(self::DOCUMENT_PREFIXES, static fn (string $prefix): bool => str_starts_with($mime, $prefix)) !== [] => 'document',
            default => 'other',
        };
    }

    /** Whether a chosen filter value names kinds of files (and not files). @param mixed $values */
    public static function names(mixed $values): bool
    {
        return is_array($values) && $values !== [] && array_diff($values, self::KINDS) === [];
    }

    /**
     * The filters with every file field filtered by kinds turned into a filter by those files, and the kinds
     * chosen per field (to echo them as chosen).
     *
     * @param array<string,array<string,mixed>> $filters
     * @param list<string> $fileFields
     * @return array{filters:array<string,array<string,mixed>>,kinds:array<string,list<string>>}
     */
    public function translate(array $filters, array $fileFields): array
    {
        $kinds = [];
        foreach ($filters as $key => $filter) {
            $values = $filter['values'] ?? null;
            if (in_array($key, $fileFields, true) && ($filter['operator'] ?? null) === 'in' && is_array($values) && self::names($values)) {
                $kinds[$key] = array_values(array_map('strval', $values));
                $filters[$key]['values'] = $this->refs($kinds[$key]);
            }
        }

        return ['filters' => $filters, 'kinds' => $kinds];
    }

    /**
     * The applied filters as echoed, with the kinds shown as chosen rather than as the files they stood for.
     *
     * @param array<string,mixed> $echo
     * @param array<string,list<string>> $kinds
     * @return array<string,mixed>
     */
    public static function echoKinds(array $echo, array $kinds): array
    {
        foreach ($kinds as $key => $chosen) {
            if (is_array($echo[$key] ?? null) && array_key_exists('values', $echo[$key])) {
                $echo[$key]['values'] = $chosen;
            } elseif (array_key_exists($key, $echo)) {
                $echo[$key] = $chosen;
            }
        }

        return $echo;
    }

    /**
     * The logical refs of the files of these kinds; a kind no file has gives a ref no record holds, so the list
     * comes back empty rather than unfiltered.
     *
     * @param list<string> $kinds
     * @return list<string>
     */
    public function refs(array $kinds): array
    {
        try {
            $rows = $this->connection->table('larena_files')->whereNull('deleted_at')->get(['logical_ref', 'mime_type']);
        } catch (Throwable) {
            return ['00000000-0000-4000-8000-000000000000'];
        }
        $refs = [];
        foreach ($rows as $row) {
            if (in_array(self::kindOf((string) $row->mime_type), $kinds, true)) {
                $refs[] = (string) $row->logical_ref;
            }
        }

        return $refs !== [] ? $refs : ['00000000-0000-4000-8000-000000000000'];
    }
}
