<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageWorkbenchRecordPage
{
    /**
     * @param list<StorageWorkbenchRecord> $items
     * @param bool $countExact False when `matchedCount` is an estimate (a large list counted in
     *                         Auto mode) or, in Skip mode, only what the list has seen so far.
     * @param list<string> $sortUnindexed The sorted fields the витрина has no index for, in sort order:
     *                                    the database sorts every matched row for them. Empty when
     *                                    the sort is indexed, there is none, or the list was not
     *                                    read from the витрина.
     * @param bool $large True when the list matches at least StorageViewCounter::LARGE_LIST records (50 000),
     *                    by its count or the counter's estimate. Only worked out for a list sorted
     *                    by an unindexed field (no extra count otherwise); false for any other.
     */
    public function __construct(
        public array $items,
        public ?string $continuation,
        public int $matchedCount,
        public bool $countExact = true,
        public array $sortUnindexed = [],
        public bool $large = false,
    ) {
    }
}
