<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * A storage's records as a board (C298, C304, board study BV-3): lanes are the values of one field (a choice, a user
 * or a yes/no field), and one call answers the whole board: the first `perLane` records of every lane in list order
 * and the totals of every lane (the count under the query and the sums asked for), counted together in one grouped
 * read of the витрина. «Показать ещё» asks one lane further with that lane's continuation.
 *
 * Read from the storage's витрина only, under the same right as its list (`storage.workbench.record.list`); nothing
 * is written. The query's filters, search and sort are the list's: the lanes are cut from the list as it stands.
 */
interface StorageWorkbenchBoard
{
    /** The field types a board takes its lanes from (as dataview's `kanban` role `lane`). */
    public const LANE_TYPES = ['choice', 'user', 'boolean'];

    /** The field types a lane total can sum. */
    public const SUM_TYPES = ['integer', 'number', 'percent'];

    /**
     * @throws StorageRejected storage_workbench_board_lane_field_invalid, storage_workbench_board_sum_field_invalid,
     *         storage_workbench_board_lane_invalid, the list's own refusals (an unknown structure, a refused scope or
     *         right, an invalid filter or continuation, storage_view_not_ready while the витрина is built)
     */
    public function listBoard(StorageWorkbenchBoardQuery $query, string $actor): StorageWorkbenchBoardPage;
}
