<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * «Хранить изменений» lowered through a plan (owner principle: a person's mistake can be undone; a final delete is a
 * conscious step). Lowering the number removes old versions of every record of the structure for good, so it is
 * planned first: the plan says how many versions of how many records go, and only the same plan, confirmed by its
 * digest, applies the new number. A count that moved meanwhile refuses the confirmation instead of removing what
 * nobody saw. Raising the number, keeping it, or keeping every version (null) needs no plan
 * (StorageWorkbench::setHistoryKeep()). Later writes keep each record trimmed to the confirmed number as before.
 */
interface StorageWorkbenchHistoryKeepPlan
{
    /** Lowering refused without a plan. */
    public const PLAN_REQUIRED = 'storage_workbench_history_keep_plan_required';

    /** The plan confirmed is not the plan now: the number or the versions it would remove moved meanwhile. */
    public const PLAN_STALE = 'storage_workbench_history_keep_plan_stale';

    /**
     * What setting $keep would do to the structure now, removing nothing. `lowers` is false when it removes nothing
     * by rule (a higher number, the same one, or null): then no plan is needed and `versions` is 0.
     *
     * @return array{structure_id: string, label: string, from: int|null, to: int|null, lowers: bool, versions: int, records: int, digest: string}
     * @throws StorageRejected storage_workbench_history_keep_invalid, storage_workbench_structure_unknown
     */
    public function planHistoryKeep(string $scopeRef, string $structureId, ?int $keep, string $actor): array;

    /**
     * Sets $keep on the plan with $planDigest: the plan is made again inside the change and must match. Old versions
     * beyond the new number are removed at once, as the plan said.
     *
     * @throws StorageRejected storage_workbench_history_keep_invalid, self::PLAN_STALE, storage_workbench_structure_unknown
     */
    public function applyHistoryKeep(
        string $scopeRef,
        string $structureId,
        ?int $keep,
        string $planDigest,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure;
}
