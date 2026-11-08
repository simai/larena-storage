<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

/**
 * Reads how many records of a storage were created on each of the last days, for the records page's chart.
 * A host that wraps the section gateway implements it by passing the call to the section.
 */
interface StorageRecordsCreatedReader
{
    /**
     * @return list<array{day: string, created: int}>|null null when the days cannot be read (no right, no vitrina)
     */
    public function recordsCreatedPerDay(string $actor, string $structureId): ?array;
}
