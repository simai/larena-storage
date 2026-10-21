<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * A page of a public list (PublishedListReads).
 *
 * - `records`: entries in the shape of `ReadContracts::publishedProjection` records
 *   (`record_id`, `revision`, `locale`, `projection_version`, `values`, `value_sources`).
 * - `continuation`: the opaque cursor of the next page, or null on the last one.
 * - `matchedCount`: how many records the list matches, or null when it was not
 *   counted (count mode `skip`, or a visibility filter applied).
 * - `countExact`: false when `matchedCount` is an estimate («~N») or absent.
 * - `filteredCount`: records of this page's scan the caller may not read — a number,
 *   never a list, as for the projection.
 */
final readonly class PublishedListPage
{
    /** @param list<array<string, mixed>> $records */
    public function __construct(
        public array $records,
        public ?string $continuation = null,
        public ?int $matchedCount = null,
        public bool $countExact = false,
        public int $filteredCount = 0,
    ) {
    }

    /** @return array{records: list<array<string, mixed>>, returned: int, continuation: ?string, matched_count: ?int, count_exact: bool, filtered_count: int} */
    public function toArray(): array
    {
        return [
            'records' => $this->records,
            'returned' => count($this->records),
            'continuation' => $this->continuation,
            'matched_count' => $this->matchedCount,
            'count_exact' => $this->countExact,
            'filtered_count' => $this->filteredCount,
        ];
    }
}
