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
tests/Integration/VersionedStorageDatabaseTest.php
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
