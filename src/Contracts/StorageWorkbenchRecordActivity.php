<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * How many records of a storage were created on each of the last days: the Storage section's chart «Новые записи по
 * дням». Read from the storage's витрина only (StorageViewCreatedPerDay), under the same right as its list
 * (`storage.workbench.record.list`); nothing is written. Days are UTC.
 */
interface StorageWorkbenchRecordActivity
{
    public const DAYS = 30;

    /**
     * @param ?string $today the last day, YYYY-MM-DD (UTC); today when null
     * @return list<array{day: string, created: int}>|null each of the $days days up to $today, a day without new
     *         records as 0; null while the storage's витрина is not ready, or when the read ran past its time budget
     * @throws StorageRejected storage_workbench_structure_unknown, a refused scope or right
     */
    public function recordsCreatedPerDay(string $scopeRef, string $structureId, string $actor, int $days = self::DAYS, ?string $today = null): ?array;
}
