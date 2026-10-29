<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

/**
 * The order of a storage's records list when the person chose none: newest first, by when the record was created
 * (Storage's system sort key `_created_at`, backed by the витрина's (state, created_at, record_id) index). The first
 * page, the list's port, the quick CSV and the background export all read it, so a file holds the records in the
 * order the list shows them. It is the list's own order, never kept as the person's preference: a sort the person
 * picks wins, and clearing it returns here. Internal scans that only page through every record (an import's look-up,
 * «archive all matching») keep the record id order.
 */
final class StorageListOrder
{
    /** When the record was created (Storage's StorageViewQueryCompiler::SORT_CREATED_AT). */
    public const CREATED_AT = '_created_at';

    /** The system sort keys a list accepts besides its fields: the record id, the revision and the creation time. */
    public const SYSTEM_SORTS = ['_record_id', '_revision', self::CREATED_AT];

    /** Newest first. */
    public const NEWEST_FIRST = [['field' => self::CREATED_AT, 'direction' => 'desc']];

    public static function isSystem(string $key): bool
    {
        return in_array($key, self::SYSTEM_SORTS, true);
    }
}
