<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageWorkbenchRecordPage
{
    /** @param list<StorageWorkbenchRecord> $items */
    public function __construct(
        public array $items,
        public ?string $continuation,
        public int $matchedCount,
    ) {
    }
}
