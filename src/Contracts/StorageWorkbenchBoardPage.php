<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * A board's answer: its lanes and how many records the whole list holds (the sum of the lane totals). A whole board
 * lists every lane that has a record, in the order of the lane field's values (a choice's options, no then yes, users
 * by reference), the lane without a value last; the board's own lane order and hiding are its description's
 * (StorageBoardDescription). A «more» call answers the one lane it asked for.
 */
final readonly class StorageWorkbenchBoardPage
{
    /** @param list<StorageWorkbenchBoardLane> $lanes */
    public function __construct(
        public string $laneField,
        public array $lanes,
        public int $total,
    ) {
    }

    public function lane(string $key): ?StorageWorkbenchBoardLane
    {
        foreach ($this->lanes as $lane) {
            if ($lane->key === $key) {
                return $lane;
            }
        }

        return null;
    }
}
