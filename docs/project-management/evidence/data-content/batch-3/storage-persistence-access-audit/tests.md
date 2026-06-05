# Tests

Commands run:

```bash
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH composer validate --strict
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH composer run quality:gate
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH php artisan test --filter=StorageRuntimeSmokeTest
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH php artisan test
```

Covered tests:

- `StorageSchemaContractTest`
- `StorageFailsClosedTest`
- `InMemoryStorageRuntimeTest`
- `AccessScopedStorageRuntimeTest`
- `LaravelDatabaseStorageAdapterContractTest`
- `StorageAuditEmissionTest`
- `StoragePersistenceFailClosedTest`
- `StorageTransactionBoundaryTest`

The first full `quality:gate` run failed only because evidence files did not
exist yet. Code lint, PHPStan and all package tests passed after adding the
PHPStan scan directories for sibling `access` and `audit` package sources.

Entry app smoke:

- `StorageRuntimeSmokeTest`: 2 tests, 15 assertions, passed.
- full entry app test suite: 4 tests, 17 assertions, passed.
