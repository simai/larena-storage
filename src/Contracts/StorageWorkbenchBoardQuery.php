<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * One board read: the list it is cut from (filters, search, sort, archived records; its `limit` is how many records
 * each lane shows, 20 by default, at most 100), the field whose values are the lanes, and the number fields whose
 * sums each lane's header shows.
 *
 * Without `lane` the call answers every lane: its first records and the totals of all lanes. With `lane` (a lane
 * key, see StorageWorkbenchBoardLane) it answers that lane alone, from the list's `continuation` that lane gave:
 * «Показать ещё».
 */
final readonly class StorageWorkbenchBoardQuery
{
    public const PER_LANE = 20;

    /** @param list<string> $sums */
    public function __construct(
        public StorageWorkbenchRecordQuery $records,
        public string $laneField,
        public array $sums = [],
        public ?string $lane = null,
    ) {
    }
}
