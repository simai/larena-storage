<?php

declare(strict_types=1);

namespace Larena\Storage\Board;

use Closure;
use Larena\Storage\Contracts\StorageWorkbenchBoard;
use Larena\Storage\Contracts\StorageWorkbenchBoardLane;
use Larena\Storage\Contracts\StorageWorkbenchBoardQuery;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The board window of simai.view-config/1 (contract §3, agreed by Larena on 2026-11-19): one request answers every lane
 * of a board — the first `per_lane` records of each and each lane's total — and «Показать ещё» of one lane asks that
 * lane alone from its cursor.
 *
 * Window: `{lanes: <lane field>, per_lane?: 1..100 (20), totals?: ["count", <sum field>...], lane?: <value>, cursor?}`.
 * Answer: `{lanes: [{value, total, sums?, limit?, over_limit?, records[], cursor?}]}`, at most 200 lanes: a board with more is
 * refused (`storage_board_window_limit:lanes_max`), never cut silently. A lane's sums are numbers; `limit` and `over_limit` are
 * there when the board's description gives the lane a soft limit. Built on StorageWorkbenchBoard::listBoard(): the
 * list's query (filters, search, sort, archived records) cuts every lane.
 */
final readonly class StorageBoardWindow
{
    public const PER_LANE_DEFAULT = StorageWorkbenchBoardQuery::PER_LANE;
    public const PER_LANE_MAX = StorageBoardDescription::MAX_PER_LANE;
    public const LANES_MAX = StorageBoardDescription::MAX_LANES;
    private const KEYS = ['lanes', 'per_lane', 'totals', 'lane', 'cursor'];

    public function __construct(private StorageWorkbenchBoard $board)
    {
    }

    /**
     * @param array<array-key, mixed> $window
     * @param StorageWorkbenchRecordQuery $list the list the board is cut from (its limit and continuation are the window's)
     * @param (Closure(StorageWorkbenchRecord): array<string, mixed>)|null $record what the answer says of a record beyond
     *                                                                           its id and revision
     * @param array<string, int> $limits soft limits by lane key (the board description's)
     * @return array{lanes: list<array<string, mixed>>}
     */
    public function answer(array $window, StorageWorkbenchRecordQuery $list, string $actor, ?Closure $record = null, array $limits = []): array
    {
        if ($window === [] || array_is_list($window) || array_diff(array_map('strval', array_keys($window)), self::KEYS) !== []) {
            self::reject('invalid');
        }
        $field = $window['lanes'] ?? null;
        $perLane = $window['per_lane'] ?? self::PER_LANE_DEFAULT;
        $totals = $window['totals'] ?? ['count'];
        $cursor = $window['cursor'] ?? null;
        if (!is_string($field) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $field) !== 1 || !is_int($perLane) || $perLane < 1
            || !is_array($totals) || !array_is_list($totals) || ($cursor !== null && (!is_string($cursor) || $cursor === '' || strlen($cursor) > 4096))) {
            self::reject('invalid');
        }
        if ($perLane > self::PER_LANE_MAX) {
            self::reject('limit:per_lane_max');
        }
        $sums = [];
        foreach ($totals as $total) {
            if (!is_string($total) || ($total !== 'count' && preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $total) !== 1)) {
                self::reject('invalid');
            }
            if ($total !== 'count' && !in_array($total, $sums, true)) {
                $sums[] = $total;
            }
        }
        $lane = null;
        if (array_key_exists('lane', $window)) {
            $value = $window['lane'];
            if ($value !== null && !is_string($value) && !is_bool($value)) {
                self::reject('invalid');
            }
            $lane = StorageWorkbenchBoardLane::key($value);
        } elseif ($cursor !== null) {
            // A cursor belongs to one lane: it is asked with that lane.
            self::reject('invalid');
        }
        $query = new StorageWorkbenchRecordQuery($list->scopeRef, $list->structureId, $list->filters, $list->search, $list->sort, $perLane,
            $lane !== null ? $cursor : null, $list->includeArchived);
        $page = $this->board->listBoard(new StorageWorkbenchBoardQuery($query, $field, $lane !== null ? [] : $sums, $lane), $actor);
        if (count($page->lanes) > self::LANES_MAX) {
            self::reject('limit:lanes_max');
        }
        $lanes = [];
        foreach ($page->lanes as $read) {
            $answer = ['value' => $read->value, 'total' => $read->total];
            $laneSums = [];
            foreach ($read->sums as $key => $sum) {
                if (is_string($sum) && is_numeric($sum)) {
                    $laneSums[(string) $key] = str_contains($sum, '.') || stripos($sum, 'e') !== false ? (float) $sum : (int) $sum;
                }
            }
            if ($laneSums !== []) {
                $answer['sums'] = $laneSums;
            }
            if (isset($limits[$read->key])) {
                // The limit itself too, so the lane header can read «N из M».
                $answer['limit'] = $limits[$read->key];
                $answer['over_limit'] = $read->total > $limits[$read->key];
            }
            $answer['records'] = array_map(static fn (StorageWorkbenchRecord $item): array => ['id' => $item->recordId, 'revision' => (string) $item->revision]
                + ($record !== null ? $record($item) : []), $read->records);
            if ($read->continuation !== null) {
                $answer['cursor'] = $read->continuation;
            }
            $lanes[] = $answer;
        }

        return ['lanes' => $lanes];
    }

    private static function reject(string $what): never
    {
        throw new StorageRejected('storage_board_window_'.$what);
    }
}
