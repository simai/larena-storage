# Tests

Toolchain: `/opt/homebrew/opt/php@8.3/bin/php` 8.3.31 and `/Applications/ServBay/bin/composer`. Wrappers run with PHP 8.3 first in `PATH`; the unrelated ambient PHP 8.2 ICU failure is not repaired or used.

## Auditor counterexamples before correction

- Exact real-provider compatibility on `70710de...`: `{"storage.record":true,"storage.record:inventory.widget":false}`.
- Disposable single mutation `tenant.visibility: public -> protected`: fatal `storage_record_list_filter_field_not_public`, exit `255`.
- Evidence named clean-clone revision `410ec36...` while the audited handoff was `70710de...`.

## Corrected focused results

- `VersionedStorageRecordListTest.php`: 30 grouped scenarios PASS.
- `VersionedStorageRecordListProviderBindingTest.php`: 7 grouped scenarios PASS.
- Canonical runtime resource is `storage.record`; exact schema remains query and cursor identity input.
- Real `PersistentGlobalRoleQueryScopeProvider` supports canonical resource/operation against Storage descriptor target `storage.record:all`; schema-suffixed resource is not used by runtime.
- Explicit consumer interface binding injects the real provider; no interface binding remains absent and list fails `storage_record_list_scope_missing`.
- Provider-added protected tenant scope isolates alpha/beta in SQL and is absent from public output/Audit/exception diagnostics.
- Caller attempt to filter protected tenant is rejected.
- Provider schema mutation, caller-filter deletion/change, unknown/admin field and unknown operator all fail closed.
- Current-head/CAS, exact historical read, tampered/changed-filter/cross-scope cursor, filter-key metamorphism, zero mutation, fresh PHP process restart and byte-identical alternate-path proof remain PASS.

## Local package checks

- `composer validate --strict`: PASS.
- `composer test`: PASS; 22 documented PHP commands, with the pre-existing opt-in MySQL harness skipped by its contract.
- `composer run quality:gate`: PASS.
- Lint: 88 PHP files; PHPStan: zero errors.
- Metadata/evidence/scope checks: PASS; correction diff remains inside the declared 26-file total candidate surface.
- `git diff --check`: PASS.

Exact no-local successor clone is pending the correction implementation commit.
