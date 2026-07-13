# Tests

Runtime: ServBay PHP.

Fresh package evidence for this checkpoint:

- `tests/Integration/StorageOwnedTableShapeTest.php`: passed;
- `tests/Integration/VersionedStorageDatabaseTest.php`: passed after migration
  hardening;
- package `composer run quality:gate`: passed (59 PHP files linted, PHPStan
  clean, 11 package script tests passed, metadata/evidence/scope checks passed);
- `composer validate --strict`: passed; Composer emitted upstream PHP 8.4
  deprecation notices but no manifest error;
- root-owned isolated real-MySQL shape test after the final named-index and
  column-contract freeze: passed (1 test, 77 assertions, 6646 ms); generated
  schema cleanup asserted zero remaining schemas. No schema name or credential
  was copied into package evidence.

The SQLite matrix covers foreign/missing/extra columns, portable column
contract and auto-increment mismatch, missing/wrong primary key, missing/wrong unique index,
missing/wrong/renamed secondary and unique indexes, no DDL after failed preflight, compatible empty
partial completion, data-bearing partial refusal, idempotent full topology,
read-only upgrade rejection, absent/partial/incompatible/data-bearing down and
clean down/reapply.

An unsupported-driver preflight proves that install, validation and rollback
fail with a sanitized code before requesting PDO/schema metadata or executing
DDL. SQLite native-JSON mode is covered by fresh install, validation and clean
down/reapply.

The MySQL matrix additionally rejects `INT` in place of the declared `BIGINT`
and `VARCHAR` in place of the declared fixed `CHAR`, as well as wrong length,
nullability and unsigned metadata.
