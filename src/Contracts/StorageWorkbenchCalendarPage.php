<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * A calendar read's answer: the records in start order (a range) or in the list's order (undated), the exact count of
 * every record the read matches, whether the range held more than it answers (`truncated`), and for undated records
 * whether a next page follows.
 */
final readonly class StorageWorkbenchCalendarPage
{
    /** @param list<StorageWorkbenchRecord> $records */
    public function __construct(
        public array $records,
        public int $total,
        public bool $truncated = false,
        public bool $hasNext = false,
    ) {
    }
}
