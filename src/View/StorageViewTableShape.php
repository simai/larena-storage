<?php

declare(strict_types=1);

namespace Larena\Storage\View;

/**
 * The two kinds of витрина table the same layout planner, DDL, writer and query
 * compiler serve.
 *
 * - `admin`: one table per storage, one row per current record (`record_id`), the
 *   rows partitioned by `state` (active, archived) for the workbench lists.
 * - `site`: one table per published storage, one row per published record in each
 *   slot (`slot_id`, `record_id`), a slot being one scope and one locale. `slot_id`
 *   plays the part `state` plays in the admin indexes. A row holds the public fields
 *   of the published revision and the exact contract entry.
 *
 * Code that takes no shape works on the admin table, exactly as before the site shape.
 */
enum StorageViewTableShape: string
{
    case Admin = 'admin';
    case Site = 'site';

    /** @return list<string> The columns that identify a row (the primary key and the upsert key). */
    public function keyColumns(): array
    {
        return $this === self::Site ? ['slot_id', 'record_id'] : ['record_id'];
    }

    /** The column every field index starts with: the partition a list reads. */
    public function partitionColumn(): string
    {
        return $this === self::Site ? 'slot_id' : 'state';
    }

    /** @return list<string> The main table's own columns, before the field columns. */
    public function systemColumns(): array
    {
        return $this === self::Site
            ? ['slot_id', 'record_id', 'revision', 'schema_version', 'projection_version', 'entry_hash', 'entry_json', 'title', 'updated_at']
            : StorageViewShapeVerifier::SYSTEM_COLUMNS;
    }

    /** @return list<string> */
    public function multiColumns(): array
    {
        return $this === self::Site ? ['slot_id', 'record_id', 'field_key', 'value'] : StorageViewShapeVerifier::MULTI_COLUMNS;
    }

    public static function of(?self $shape): self
    {
        return $shape ?? self::Admin;
    }
}
