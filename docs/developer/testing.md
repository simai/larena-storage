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
