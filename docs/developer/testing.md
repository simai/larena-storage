# Storage Testing

## Required Package Checks

Run from the package repository:

```bash
composer validate --strict
composer run quality:gate
```

Relevant unit tests:

```text
tests/Unit/InMemoryStorageRuntimeTest.php
tests/Unit/StorageSchemaContractTest.php
tests/Unit/AccessScopedStorageRuntimeTest.php
tests/Unit/LaravelDatabaseStorageAdapterContractTest.php
tests/Unit/StorageAuditEmissionTest.php
tests/Unit/StoragePersistenceFailClosedTest.php
tests/Unit/StorageTransactionBoundaryTest.php
tests/Integration/StorageOwnedTableShapeTest.php
tests/Integration/StorageSchemaMigrationTableShapeTest.php
tests/Integration/StorageSchemaEvolutionTest.php
tests/Integration/StorageSchemaEvolutionAdversarialTest.php
tests/Integration/StorageSchemaEvolutionOwnerProtectionTest.php
tests/Integration/StorageSchemaEvolutionOwnerPolicyProviderOrderTest.php
tests/Integration/StorageSchemaEvolutionConcurrencyTest.php
tests/Integration/StorageSchemaEvolutionMySqlTest.php
tests/Integration/VersionedStorageDatabaseTest.php
```

The real-MySQL schema-evolution harness is opt-in and uses only the ignored
root test credential file. It creates and removes one strict-allowlisted random
schema and refuses a pre-existing name:

```bash
composer run test:mysql-schema-evolution
```

Entry app smoke:

```text
simai/larena:tests/Feature/StorageRuntimeSmokeTest.php
```

## Evidence Paths

Current evidence lives in:

```text
docs/project-management/evidence/data-content/batch-2/storage-runtime-slice/
docs/project-management/evidence/data-content/batch-3/storage-persistence-access-audit/
docs/project-management/evidence/typed-page-content-schema/
docs/project-management/evidence/managed-docara-content-types/
```

Before promoting a future batch, update evidence, implementation metrics,
quality records, inline checkpoint, developer documentation map and code
location map.

## What Tests Must Prove

- schema registration and record mutation are deterministic;
- validation happens before mutation;
- access scope denial blocks reads/mutations as expected;
- persistence failures do not silently mutate state;
- audit wrapper emits safe mutation descriptors;
- entry app smoke can exercise the package boundary.
- an incompatible existing owned table is rejected before any missing table is
  created;
- missing and wrongly composed primary, unique and secondary indexes are
  rejected with stable sanitized reason codes;
- a correct empty partial topology completes, while a data-bearing partial
  topology remains untouched;
- upgrade validation is read-only;
- `down()` drops only the complete compatible empty topology and clean
  down/reapply is reproducible.
- only optional additions with empty constraints are compatible;
- plan/source/record/hash tampering and stale heads produce no partial writes;
- `0`, `false`, empty string, Unicode and absent optional keys survive exact
  record migration, while explicit unsupported null remains rejected by
  Property-owned validation;
- Plan/apply Audit failure rolls back plans, schema, records and result; two processes applying
  one plan produce exactly one winner.
- protected-owner direct plan/apply and forged/replayed capabilities fail
  before mutation, while a one-shot transaction-bound owner capability passes;
- provider registration protects the final container singleton for every
  supported Storage/consumer resolution order;
- real MySQL rejects exact length, char/varchar, integer width/sign, JSON and
  auto-increment drift, survives restart/concurrent apply and removes the
  generated schema completely.
