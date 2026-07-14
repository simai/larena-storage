# Tests

Runtime: ServBay PHP with disposable file-backed SQLite plus an explicitly
opted-in, generated allowlisted real-MySQL schema.

Executable coverage:

- `StorageOwnedTableShapeTest.php`: original version-table shape lifecycle;
- `StorageSchemaMigrationTableShapeTest.php`: unsupported/foreign/partial/
  index-damaged/down-refusal/clean-reapply migration-table lifecycle;
- `StorageSchemaEvolutionTest.php`: RED-then-GREEN happy path, direct-v2 block,
  safe DTOs, exact values, Access operations, Audit sanitation and repeat apply;
- `StorageSchemaEvolutionAdversarialTest.php`: identity/type/type-version/
  required/visibility/constraint/removal/reorder/no-op/unknown rejection,
  explicit-null rejection, legacy omitted defaults, hash/definition/item/
  source/record tamper, stale schema/record heads, Access validation-oracle
  denial, injected plan/apply Audit rollback and immutable-read corruption;
- `StorageSchemaEvolutionOwnerProtectionTest.php`: launch criterion 232 direct
  protected plan/apply denial, Access-before-policy ordering, exact
  actor/operation/source/target/plan/hash/connection/transaction binding,
  forged/cloned/expired capability denial, successful sealed outer
  transaction, one-shot replay denial and unchanged unprotected-owner flow;
- `StorageSchemaEvolutionOwnerPolicyProviderOrderTest.php`: Storage-first,
  consumer-first, already-resolved Storage and transient-registry-before-
  singleton provider permutations. Registration state is scoped to registry
  identity so the singleton is always protected before seal;
- `StorageSchemaEvolutionConcurrencyTest.php`: two forked processes and two
  SQLite connections apply one plan with exactly one result winner;
- `StorageSchemaEvolutionMySqlTest.php`: all four new table shape matrices,
  including varchar length, char/varchar, signed/width, JSON/text and
  auto-increment drift; clean install/down/reapply, null/required/constraint
  rejection, plan restart/reconnect, two-process one-winner apply, strict
  value/hash/JSON preservation, used-down refusal and cleanup remaining zero;
- `VersionedStorageDatabaseTest.php`: legacy exact reads/CAS/restart/Audit
  atomicity through the new schema-evolution path.

The two criterion-232 tests and the MySQL harness are included in
`composer test`; the MySQL harness exits successfully as an explicit skip
unless opted in. Run the real database path with:

```bash
composer run test:mysql-schema-evolution
```

The focused real-MySQL run passed without recording credentials or the random
schema name, and cleanup asserted zero remaining generated schemas. Final
package `composer quality:gate` passed: launch validation, 81 PHP files linted,
PHPStan clean, 18 executable package test scripts passed (the MySQL script
performed its expected non-opted-in skip inside the default gate), metadata and
evidence checks passed, and scope-check accepted 58 changed files. Composer PHP
8.4 deprecation notices are upstream noise and not test failures.
