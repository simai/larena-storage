<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * A slice of a traversal.
 *
 * Two counts matter and neither is optional. `truncated` says the budget stopped
 * the walk, because a silently short answer is worse than an error. `filteredCount`
 * says how many records the caller may not read, as a number and never as
 * identifiers — a hidden ancestor must be reported as filtered rather than absent,
 * and naming it would defeat the filter.
 */
final readonly class RelationTraversalPage
{
    /** @param list<RelationRecord> $records */
    public function __construct(
        public array $records,
        public bool $truncated,
        public int $filteredCount = 0,
        public int $budget = 0,
    ) {
    }

    public function count(): int
    {
        return count($this->records);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'records' => array_map(static fn (RelationRecord $r): array => $r->toArray(), $this->records),
            'returned' => $this->count(),
            'truncated' => $this->truncated,
            'filtered_count' => $this->filteredCount,
            'budget' => $this->budget,
        ];
    }
}
