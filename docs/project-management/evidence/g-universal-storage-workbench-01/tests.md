# Tests

M1 package-head verification:

- `composer validate --strict --no-check-publish`: PASS.
- PHP lint: PASS (97 PHP files).
- PHPStan: PASS (zero errors).
- `StorageWorkbenchTest.php`: PASS, including two unrelated generic models,
  foreign scope/direct ID denial, CAS conflict, additive/incompatible schema
  cases, signed-cursor tamper, deterministic typed pagination, history, atomic
  bulk failure and four byte-identical fresh PHP process reads.
- Correction counterexample: PASS. Alpha and beta independently create the same
  public `workbench.inventory_asset` with different compatible typed
  descriptors/records; scoped catalog, read, CAS, query, history and cursors
  remain isolated, cross-scope direct IDs reject state-equally, opaque internal
  identities contain no raw scope, and four restart processes return one
  byte-identical safe projection.
- Legacy identity migration: PASS, including exact persisted readback after
  upgrade, idempotent re-run and state-equal refusal of a lossy down migration.
- `StorageWorkbenchProviderBindingTest.php`: PASS.
- complete `composer quality:gate`: PASS; all predecessor Storage suites remain
  green and the optional MySQL-only predecessor test remains explicitly skipped.
- metadata, evidence and scope gates: PASS (26 goal-owned changed files at the
  correction checkpoint).
