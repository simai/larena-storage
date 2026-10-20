<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageWorkbenchRecordPage
{
    /**
     * @param list<StorageWorkbenchRecord> $items
     * @param bool $countExact False when `matchedCount` is an estimate (a large list counted in
     *                         Auto mode) or, in Skip mode, only what the list has seen so far.
     */
    public function __construct(
        public array $items,
        public ?string $continuation,
        public int $matchedCount,
        public bool $countExact = true,
    ) {
    }
}
