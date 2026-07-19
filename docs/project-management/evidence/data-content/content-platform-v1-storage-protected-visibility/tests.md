# Tests

Runtime: ServBay PHP 8.4.20.

Verified implementation candidate:

- `composer validate --strict`: PASS;
- `composer install --no-interaction --prefer-dist`: PASS, lock contents
  installable, nothing changed;
- `composer run test:content-compatibility`: PASS;
- `composer run test`: PASS across the complete package command list; the
  opt-in schema-evolution MySQL test reports its expected skip because this
  non-migration compatibility batch does not require MySQL;
- `composer run validate:larena`: PASS;
- `composer run lint`: PASS, 82 PHP files;
- `composer run analyse`: PASS at package PHPStan level 5 plus configured
  sibling contract scans;
- metadata sync, evidence and scope checks: PASS;
- `composer run quality:gate`: PASS;
- `git diff --check`: PASS;
- independent review: PASS, `P0=0`, `P1=0`, `P2=0`.

The focused test covers:

- exact immutable schema admission for `public`, `protected` and `admin`;
- rejection of hidden, encrypted, unknown, empty, non-string, null and missing
  immutable schema visibility;
- canonical JSON and persisted schema round-trip;
- protected/admin value preservation in actor-checked immutable admin reads;
- exact-public output from versioned, in-memory and disposable-PDO surfaces;
- fail-closed omission of protected, admin, hidden, encrypted, unknown,
  missing, null and non-string legacy visibility;
- absence of raw protected values from serialized projection evidence.
