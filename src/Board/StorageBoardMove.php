<?php

declare(strict_types=1);

namespace Larena\Storage\Board;

use Closure;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchBoardLane;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * Moving a card on a board (C304, board study BV-5): a move is a change of the lane field's value, written as any
 * update of the record (its update right, its revision, its checks). Before it is written the board's rules are
 * asked: the lanes a card may come from into the target lane, and the ability a person needs to move a card there.
 *
 * targets() answers, for a card, every lane it may go to and every lane it may not with the refusal's code and
 * reason, so the board greys a lane out during a drag and says why. move() writes the move or answers a refusal
 * with its code and reason; nothing is thrown for a refused move, so the board puts the card back and says why.
 *
 * Order inside a lane is the view's sort; a manual order (an order field) is placed by the record's own edit, so a
 * move carries no place.
 */
final readonly class StorageBoardMove
{
    /** Refusal codes of a move, besides the storage's own (a refused right, a stale revision, a value the field refuses). */
    public const LANE_UNKNOWN = 'storage_board_move_lane_unknown';
    public const NOT_ALLOWED = 'storage_board_move_not_allowed';
    public const DENIED = 'storage_board_move_denied';

    /**
     * @param (Closure(string, string): bool)|null $can whether the actor holds the ability (actor, ability); without
     *                                              it a lane that needs an ability refuses every move into it
     */
    public function __construct(
        private StorageWorkbench $workbench,
        private ?Closure $can = null,
    ) {
    }

    /**
     * Where a card may go: every lane of the list with null for an allowed move or the refusal.
     *
     * @param list<string> $lanes the board's lane keys
     * @return array<string, array{code: string, reason: string|null}|null>
     */
    public function targets(StorageBoardDescription $board, StorageWorkbenchRecord $record, array $lanes, string $actor): array
    {
        $from = $this->laneOf($board, $record);
        $targets = [];
        foreach ($lanes as $lane) {
            $targets[$lane] = $lane === $from ? null : $this->refusal($board, $from, $lane, $actor);
        }

        return $targets;
    }

    /**
     * Moves the card into the lane, from the revision the board showed.
     *
     * @return array{record: StorageWorkbenchRecord}|array{error: array{code: string, reason: string|null}}
     */
    public function move(StorageBoardDescription $board, string $scopeRef, string $structureId, string $recordId, string $to,
        int $expectedRevision, string $actor, ?string $correlationId = null): array
    {
        try {
            $record = $this->workbench->readRecord($scopeRef, $structureId, $recordId, $actor);
            $from = $this->laneOf($board, $record);
            if ($from === $to) {
                return ['record' => $record];
            }
            $refusal = $this->refusal($board, $from, $to, $actor);
            if ($refusal !== null) {
                return ['error' => $refusal];
            }
            $values = array_filter($record->values, static fn (string $key): bool => !str_starts_with($key, 'larena_'), ARRAY_FILTER_USE_KEY);
            $value = $this->value($board, $to);
            if ($value === null) {
                unset($values[$board->laneField]);
            } else {
                $values[$board->laneField] = $value;
            }

            return ['record' => $this->workbench->updateRecord($scopeRef, $structureId, $recordId, $expectedRevision, $values, $actor, $correlationId)];
        } catch (StorageRejected $exception) {
            // The storage refused (a right, a stale revision, a value the field refuses): the board shows its code.
            return ['error' => ['code' => $exception->getMessage(), 'reason' => null]];
        }
    }

    /** @return array{code: string, reason: string|null}|null */
    private function refusal(StorageBoardDescription $board, string $from, string $to, string $actor): ?array
    {
        if (!$board->knowsLane($to)) {
            return ['code' => self::LANE_UNKNOWN, 'reason' => null];
        }
        $refusal = $board->refusal($from, $to);
        if ($refusal !== null) {
            return $refusal;
        }
        $ability = $board->ability($to);
        if ($ability !== null && ($this->can === null || !($this->can)($actor, $ability))) {
            return ['code' => self::DENIED, 'reason' => $board->reason($to)];
        }

        return null;
    }

    private function laneOf(StorageBoardDescription $board, StorageWorkbenchRecord $record): string
    {
        $value = $record->values[$board->laneField] ?? null;

        return StorageWorkbenchBoardLane::key(is_string($value) || is_bool($value) ? $value : null);
    }

    private function value(StorageBoardDescription $board, string $lane): string|bool|null
    {
        if ($lane === StorageWorkbenchBoardLane::EMPTY) {
            return null;
        }

        return ($board->laneFieldDefinition()['type'] ?? null) === 'boolean' ? $lane === 'true' : $lane;
    }
}
