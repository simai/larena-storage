# Smoke

Package smoke:

- package autoload can load the new storage integration classes;
- access-scoped runtime returns no protected records without a supported
  `QueryScopeProvider`;
- query scope is applied before records are returned;
- denied access mutation does not persist;
- invalid mutation payload does not persist;
- successful mutation emits one redacted audit event;
- required audit routing failure propagates through the transaction boundary.

Entry app smoke:

```bash
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH php artisan test --filter=StorageRuntimeSmokeTest
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH php artisan test
```

Results:

- targeted storage smoke passed: 2 tests, 15 assertions;
- full entry app test suite passed: 4 tests, 17 assertions.

The entry app smoke covers both the previous in-memory storage runtime and the
new batch-3 storage/access/audit integration classes.
