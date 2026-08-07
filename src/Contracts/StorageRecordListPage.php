<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageRecordListPage
{
    /** @param list<StorageRecordListItem> $items */
    public function __construct(
        public array $items,
        public ?string $continuation,
    ) {
    }
}
