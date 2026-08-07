# Tests

Toolchain: `/opt/homebrew/opt/php@8.3/bin/php` 8.3.31 and `/Applications/ServBay/bin/composer`. Every wrapper was run with `/opt/homebrew/opt/php@8.3/bin` first in `PATH`; ambient PHP 8.2 is unusable because it references missing ICU 73 and was not repaired.

## Before gap

- Base: `7c606e092591dbcb40fbe7c45ef30bb917f1df68`, original checkout clean.
- Reflection check for `VersionedStorage::listCurrentRecords`: expected diagnostic `persistent VersionedStorage has exact/current reads but no durable scoped current-record list contract`, exit `23`.

## Focused result

`php -d zend.assertions=1 -d assert.exception=1 tests/Integration/VersionedStorageRecordListTest.php` passed with 21 grouped scenarios:

- two unrelated neutral schemas use one generic contract;
- three records across two scopes plus a second schema are isolated correctly;
- CAS exposes one revision-2 current head while revision 1 remains exactly readable;
- missing, unsupported, denied and malformed scope fail closed;
- wrong schema, unknown field/operator, hidden-field filter, limits 0/101 and missing cursor key fail closed;
- byte-tampered, changed-filter and changed-scope continuations fail closed;
- rejected queries leave head/version counts unchanged;
- filter/object-key permutations and Property-equivalent typed inputs yield identical items and continuation;
- a child PHP process reads the same file-backed SQLite state after the writer process disconnects;
- a byte-identical database copy at another disposable path yields the exact same payload and continuation;
- private sentinels never appear in returned projections or Audit payloads.

## Package checks

- `composer validate --strict`: PASS.
- `composer test`: PASS; 21 documented PHP test commands, with the pre-existing real-MySQL harness explicitly skipped by its opt-in contract.
- `composer run quality:gate`: PASS, including 87-file lint, PHPStan with zero errors, metadata, evidence and 25-file scope checks.
- `git diff --check`: PASS.

Independent no-local clean-clone reproduction at implementation revision `410ec36d1e983a5486fef5033ab5d7bb0729b641` is PASS and recorded in `smoke.md` and `verification.json`.
