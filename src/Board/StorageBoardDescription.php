<?php

declare(strict_types=1);

namespace Larena\Storage\Board;

use Larena\Property\Contracts\PropertyField;
use Larena\Property\Runtime\PropertyFieldDescriber;
use Larena\Storage\Contracts\StorageWorkbenchBoard;
use Larena\Storage\Contracts\StorageWorkbenchBoardLane;
use Larena\Storage\Contracts\StorageWorkbenchBoardPage;
use Larena\Storage\Contracts\StorageWorkbenchBoardQuery;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * A board's description (C304, board study BV-4): the options of `layouts.board` in a view of `simai.view-config/1`,
 * checked against the storage's fields.
 *
 * - `lane_field`: a choice, user or yes/no field; its values are the lanes;
 * - `lanes`: `order` (lane keys first in this order, the others after in the field's order), `hidden` (shown again
 *   through «Настроить вид»), `collapsed` (a narrow strip that still takes a card);
 * - `empty_lanes`: `show` (default: every option of a choice, both values of a yes/no field) or `hide`;
 * - `card_fields`: up to four fields under the card's title;
 * - `totals`: `count` and `{sum: field}` over integer, number and percent fields, worked out by the server;
 * - `limits`: soft limits per lane (q-limits Б): a lane over its limit is marked, a move into it is not refused;
 * - `order`: `sort` (the view's sort) or `manual`, only with an `order_field` (an integer or number field) the host
 *   keeps the order in (q-order А);
 * - `per_lane`: records per lane per request, 20 by default;
 * - `swimlane_field`: reserved, always null (q-swimlanes А: no swimlanes);
 * - `moves`: the host's rules (BV-5) — for a target lane, the lanes a card may come `from` and the `reason` a refused
 *   move gives, and the `ability` a person needs to move into it. Rules are the host's, not a person's: the levels
 *   below «для всех» may not change them (the view settings keep `layouts.board.moves` locked).
 *
 * describe() is the board's part of the data adapter's describe(): the fields in the Framework catalogue's words
 * (PropertyFieldDescriber), the board's options and its move rules. arrange() lays a read board out by the
 * description: lane order, empty, hidden and collapsed lanes, lanes over their limit.
 */
final readonly class StorageBoardDescription
{
    public const MAX_CARD_FIELDS = 4;
    public const MAX_PER_LANE = 100;
    public const MAX_LANES = 200;
    public const MAX_REASON = 200;
    private const KEYS = ['lane_field', 'lanes', 'empty_lanes', 'card_fields', 'totals', 'limits', 'order', 'order_field', 'per_lane', 'swimlane_field', 'moves'];
    private const ABILITY_PATTERN = '/\A[a-z][a-z0-9_.:-]{0,127}\z/D';

    /**
     * @param array{order: list<string>, hidden: list<string>, collapsed: list<string>} $lanes
     * @param list<string> $cardFields
     * @param list<string> $sums the fields whose sums the lane headers show
     * @param array<string, int> $limits
     * @param array<string, array{from: list<string>|null, reason: string|null, ability: string|null}> $moves
     * @param array<string, array<string, mixed>> $fields the storage's fields by key
     */
    private function __construct(
        public string $laneField,
        public array $lanes,
        public bool $emptyLanes,
        public array $cardFields,
        public bool $count,
        public array $sums,
        public array $limits,
        public bool $manual,
        public ?string $orderField,
        public int $perLane,
        public array $moves,
        private array $fields,
    ) {
    }

    /**
     * @param array<array-key, mixed> $board the `layouts.board` options
     * @param list<array<string, mixed>> $fields the storage's fields (a structure's `fields`)
     */
    public static function fromArray(array $board, array $fields): self
    {
        $byKey = [];
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $byKey[$field['key']] = $field;
            }
        }
        if (array_diff(array_map('strval', array_keys($board)), self::KEYS) !== []) {
            self::reject('unknown_key');
        }
        $laneField = $board['lane_field'] ?? null;
        if (!is_string($laneField) || !in_array($byKey[$laneField]['type'] ?? null, StorageWorkbenchBoard::LANE_TYPES, true)) {
            self::reject('lane_field');
        }
        $lanes = is_array($board['lanes'] ?? []) ? ($board['lanes'] ?? []) : self::reject('lanes');
        if (array_diff(array_map('strval', array_keys($lanes)), ['order', 'hidden', 'collapsed']) !== []) {
            self::reject('lanes');
        }
        $laneLists = [];
        foreach (['order', 'hidden', 'collapsed'] as $list) {
            $laneLists[$list] = self::laneKeys($lanes[$list] ?? []);
        }
        $empty = $board['empty_lanes'] ?? 'show';
        if ($empty !== 'show' && $empty !== 'hide') {
            self::reject('empty_lanes');
        }
        $cardFields = $board['card_fields'] ?? [];
        if (!is_array($cardFields) || !array_is_list($cardFields) || count($cardFields) > self::MAX_CARD_FIELDS
            || count(array_unique($cardFields, SORT_REGULAR)) !== count($cardFields)) {
            self::reject('card_fields');
        }
        foreach ($cardFields as $key) {
            if (!is_string($key) || !array_key_exists($key, $byKey)) {
                self::reject('card_fields');
            }
        }
        [$count, $sums] = self::totals($board['totals'] ?? ['count'], $byKey);
        $limits = [];
        foreach (is_array($board['limits'] ?? []) ? ($board['limits'] ?? []) : self::reject('limits') as $lane => $limit) {
            if (!is_int($limit) || $limit < 1 || $limit > 100_000 || strlen((string) $lane) > 255) {
                self::reject('limits');
            }
            $limits[(string) $lane] = $limit;
        }
        $order = $board['order'] ?? 'sort';
        $orderField = $board['order_field'] ?? null;
        if (($order !== 'sort' && $order !== 'manual')
            || ($orderField !== null && (!is_string($orderField) || !in_array($byKey[$orderField]['type'] ?? null, ['integer', 'number'], true)))
            || ($order === 'manual' && $orderField === null)) {
            self::reject('order');
        }
        $perLane = $board['per_lane'] ?? StorageWorkbenchBoardQuery::PER_LANE;
        if (!is_int($perLane) || $perLane < 1 || $perLane > self::MAX_PER_LANE) {
            self::reject('per_lane');
        }
        if (($board['swimlane_field'] ?? null) !== null) {
            self::reject('swimlane_field');
        }

        return new self($laneField, $laneLists, $empty === 'show', array_map('strval', $cardFields), $count, $sums, $limits,
            $order === 'manual', is_string($orderField) ? $orderField : null, $perLane, self::moves($board['moves'] ?? []), $byKey);
    }

    /** @return array<string, mixed> the normalized `layouts.board` options */
    public function toArray(): array
    {
        $totals = $this->count ? ['count'] : [];
        foreach ($this->sums as $sum) {
            $totals[] = ['sum' => $sum];
        }
        $moves = [];
        foreach ($this->moves as $lane => $rule) {
            $moves[$lane] = array_filter($rule, static fn (mixed $value): bool => $value !== null);
        }

        return ['lane_field' => $this->laneField, 'lanes' => $this->lanes, 'empty_lanes' => $this->emptyLanes ? 'show' : 'hide',
            'card_fields' => $this->cardFields, 'totals' => $totals, 'limits' => (object) $this->limits,
            'order' => $this->manual ? 'manual' : 'sort', 'order_field' => $this->orderField, 'per_lane' => $this->perLane,
            'swimlane_field' => null, 'moves' => (object) $moves];
    }

    /** The board read this description asks for, from the list's query. */
    public function query(StorageWorkbenchRecordQuery $records, ?string $lane = null): StorageWorkbenchBoardQuery
    {
        return new StorageWorkbenchBoardQuery($records, $this->laneField, $this->sums, $lane);
    }

    /**
     * The board's part of describe(): its fields in the Framework catalogue's words, the lane values the field
     * allows, the options and the move rules.
     *
     * @return array{fields: list<array<string, mixed>>, lane_values: list<array{key: string, value: string|bool|null, label: string|null}>, board: array<string, mixed>}
     */
    public function describe(PropertyFieldDescriber $describer = new PropertyFieldDescriber()): array
    {
        $fields = [];
        foreach ($this->fields as $field) {
            $fields[] = $describer->describe(PropertyField::fromArray($field, false));
        }
        $values = [];
        foreach ($this->declaredLanes() as $lane) {
            $values[] = ['key' => StorageWorkbenchBoardLane::key($lane['value']), 'value' => $lane['value'], 'label' => $lane['label']];
        }

        return ['fields' => $fields, 'lane_values' => $values, 'board' => $this->toArray()];
    }

    /**
     * A read board laid out by the description: lanes in its order (the board's `order`, then the field's), the
     * field's declared lanes even when empty (unless `empty_lanes` hides them), hidden lanes left out and named,
     * each lane with whether it is collapsed and whether it is over its soft limit.
     *
     * @return array{lanes: list<array{lane: StorageWorkbenchBoardLane, collapsed: bool, limit: int|null, over_limit: bool}>, hidden: list<string>}
     */
    public function arrange(StorageWorkbenchBoardPage $page): array
    {
        $read = [];
        foreach ($page->lanes as $lane) {
            $read[$lane->key] = $lane;
        }
        $all = [];
        foreach ($this->declaredLanes() as $declared) {
            $key = StorageWorkbenchBoardLane::key($declared['value']);
            $all[$key] = $read[$key] ?? new StorageWorkbenchBoardLane($key, $declared['value'], 0, [], [], null);
        }
        foreach ($read as $key => $lane) {
            $all[$key] ??= $lane;
        }
        $ordered = [];
        foreach ($this->lanes['order'] as $key) {
            if (isset($all[$key])) {
                $ordered[$key] = $all[$key];
            }
        }
        $ordered += $all;
        $lanes = [];
        $hidden = [];
        foreach ($ordered as $key => $lane) {
            $key = (string) $key;
            if (in_array($key, $this->lanes['hidden'], true)) {
                $hidden[] = $key;
                continue;
            }
            if ($lane->total === 0 && !$this->emptyLanes) {
                continue;
            }
            $limit = $this->limits[$key] ?? null;
            $lanes[] = ['lane' => $lane, 'collapsed' => in_array($key, $this->lanes['collapsed'], true), 'limit' => $limit,
                'over_limit' => $limit !== null && $lane->total > $limit];
        }

        return ['lanes' => $lanes, 'hidden' => $hidden];
    }

    /**
     * The rule for a move of a card from one lane to another: null when the move is allowed by the rules, else the
     * refusal's code and reason. The ability is checked by the caller (StorageBoardMove), which knows the person.
     *
     * @return array{code: string, reason: string|null}|null
     */
    public function refusal(string $from, string $to): ?array
    {
        $rule = $this->moves[$to] ?? null;
        if ($rule === null || $rule['from'] === null || in_array($from, $rule['from'], true)) {
            return null;
        }

        return ['code' => 'storage_board_move_not_allowed', 'reason' => $rule['reason']];
    }

    /** The ability a person needs to move a card into the lane, null when any person with the update right may. */
    public function ability(string $to): ?string
    {
        return $this->moves[$to]['ability'] ?? null;
    }

    /** The reason a refused move into the lane gives, as its rule says. */
    public function reason(string $to): ?string
    {
        return $this->moves[$to]['reason'] ?? null;
    }

    /** Whether the key names a lane value of the lane field (an empty key: no value, when the field allows none). */
    public function knowsLane(string $key): bool
    {
        $field = $this->fields[$this->laneField];
        if ($key === StorageWorkbenchBoardLane::EMPTY) {
            return ($field['required'] ?? false) !== true;
        }

        return match ($field['type'] ?? null) {
            'boolean' => $key === 'true' || $key === 'false',
            'choice' => in_array($key, array_map(static fn (array $lane): string => StorageWorkbenchBoardLane::key($lane['value']), $this->declaredLanes()), true),
            default => strlen($key) <= 255,
        };
    }

    /** The lane field's definition. @return array<string, mixed> */
    public function laneFieldDefinition(): array
    {
        return $this->fields[$this->laneField];
    }

    /**
     * The lanes the field declares: a choice's options, no and yes; users have none (their lanes come from the
     * records), and the lane without a value only shows when it holds records.
     *
     * @return list<array{value: string|bool|null, label: string|null}>
     */
    private function declaredLanes(): array
    {
        $field = $this->fields[$this->laneField];
        if (($field['type'] ?? null) === 'boolean') {
            return [['value' => false, 'label' => null], ['value' => true, 'label' => null]];
        }
        $lanes = [];
        $options = $field['constraints']['options'] ?? [];
        foreach (is_array($options) ? $options : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $lanes[] = ['value' => $option['value'], 'label' => is_string($option['label'] ?? null) ? $option['label'] : null];
            }
        }

        return $lanes;
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     * @return array{0: bool, 1: list<string>}
     */
    private static function totals(mixed $totals, array $byKey): array
    {
        if (!is_array($totals) || !array_is_list($totals)) {
            self::reject('totals');
        }
        $count = false;
        $sums = [];
        foreach ($totals as $total) {
            if ($total === 'count') {
                $count = true;
                continue;
            }
            $sum = is_array($total) && array_keys($total) === ['sum'] ? $total['sum'] : null;
            if (!is_string($sum) || in_array($sum, $sums, true) || !in_array($byKey[$sum]['type'] ?? null, StorageWorkbenchBoard::SUM_TYPES, true)) {
                self::reject('totals');
            }
            $sums[] = $sum;
        }

        return [$count, $sums];
    }

    /** @return list<string> */
    private static function laneKeys(mixed $keys, string $what = 'lanes'): array
    {
        if (!is_array($keys) || !array_is_list($keys) || count($keys) > self::MAX_LANES) {
            self::reject($what);
        }
        foreach ($keys as $key) {
            if (!is_string($key) || strlen($key) > 255) {
                self::reject($what);
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return array<string, array{from: list<string>|null, reason: string|null, ability: string|null}> */
    private static function moves(mixed $moves): array
    {
        if (!is_array($moves) || ($moves !== [] && array_is_list($moves)) || count($moves) > self::MAX_LANES) {
            self::reject('moves');
        }
        $rules = [];
        foreach ($moves as $lane => $rule) {
            if (!is_array($rule) || array_diff(array_map('strval', array_keys($rule)), ['from', 'reason', 'ability']) !== []) {
                self::reject('moves');
            }
            $from = array_key_exists('from', $rule) ? self::laneKeys($rule['from'], 'moves') : null;
            $reason = $rule['reason'] ?? null;
            $ability = $rule['ability'] ?? null;
            if (($reason !== null && (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > self::MAX_REASON))
                || ($ability !== null && (!is_string($ability) || preg_match(self::ABILITY_PATTERN, $ability) !== 1))) {
                self::reject('moves');
            }
            $rules[(string) $lane] = ['from' => $from, 'reason' => is_string($reason) ? trim($reason) : null, 'ability' => is_string($ability) ? $ability : null];
        }

        return $rules;
    }

    private static function reject(string $what): never
    {
        throw new StorageRejected('storage_board_description_invalid:' . $what);
    }
}
