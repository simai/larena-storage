<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * The check a structure change that touches data is shown before it is confirmed (owner decision 2026-10-22, settings
 * system stage 3): which fields every record must pass, how many records the storage holds, and how many of the
 * records checked would not take the new fields. Nothing is written and no lock is held; the change itself
 * (StorageWorkbench::migrateStructure, the `storage.schema.migrate` job) checks every record again before it switches.
 *
 * A large storage is checked up to $limit records, in record id order; `complete` says whether every record was.
 */
interface StorageWorkbenchFieldChangeCheck
{
    public const DEFAULT_LIMIT = 10_000;

    /**
     * @param array<string, mixed> $descriptor the structure descriptor the change would give (as migrateStructure takes it)
     * @return array{data_changing: bool, fields: list<string>, total: int, checked: int, invalid: int, complete: bool, samples: list<string>}
     *         `fields` are the keys every record must pass (none: the change touches no data); `samples` up to five
     *         ids of records that would not take it
     * @throws StorageRejected storage_workbench_structure_unknown, storage_workbench_structure_archived, a refusal of
     *                         the descriptor itself, storage_workbench_field_change_running
     */
    public function checkFieldChange(string $scopeRef, string $structureId, array $descriptor, string $actor, int $limit = self::DEFAULT_LIMIT): array;
}
