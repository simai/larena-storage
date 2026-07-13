# Migration And Rollback

The original table-creation migration is hardened in place so a fresh install
cannot skip a foreign same-named table and then create the remaining topology.
All existing owned tables are validated before the first DDL statement.

The new migration is read-only and provides the declared upgrade check for
installations where the original migration was already recorded.

Rollback is deterministic only for the complete compatible empty topology.
Absent tables are a no-op. Partial, foreign, damaged or data-bearing topologies
fail closed and remain untouched. Reapply recreates and revalidates the exact
owned shape.
