<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * One lane of a board: the lane field's value (a choice's option value, a user reference, true or false; null for
 * the records without one), its key (the value as text: «true»/«false» for a yes/no field, an empty string for no
 * value) that asks this lane further, how many records of the list it holds, the sums of the asked fields over them
 * (decimal strings, null when no record has a value), its records in list order and the continuation to the next
 * ones (null at the end of the lane, and for a lane not read in this call).
 */
final readonly class StorageWorkbenchBoardLane
{
    public const EMPTY = '';

    /**
     * @param array<string, string|null> $sums
     * @param list<StorageWorkbenchRecord> $records
     */
    public function __construct(
        public string $key,
        public string|bool|null $value,
        public int $total,
        public array $sums,
        public array $records,
        public ?string $continuation,
    ) {
    }

    /** The key of a lane value. */
    public static function key(string|bool|null $value): string
    {
        return match (true) {
            $value === null => self::EMPTY,
            is_bool($value) => $value ? 'true' : 'false',
            default => $value,
        };
    }
}
