<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * A page of the published projection.
 *
 * `filteredCount` is a number and never a list. A caller that may not read a record
 * must be able to learn that something is there without learning what — reporting
 * identifiers would make the filter pointless, and reporting nothing would make a
 * short page indistinguishable from a complete one.
 */
final readonly class PublishedProjectionPage
{
    /** @param list<array<string, mixed>> $records */
    public function __construct(
        public array $records,
        public int $filteredCount = 0,
        public bool $truncated = false,
        public int $budget = 0,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'records' => $this->records,
            'returned' => count($this->records),
            'filtered_count' => $this->filteredCount,
            'truncated' => $this->truncated,
            'budget' => $this->budget,
        ];
    }
}
