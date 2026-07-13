# Tests

Runtime: ServBay PHP 8.4.20.

Package-local verification:

- syntax lint: 55 PHP files passed;
- PHPStan: no errors;
- nine existing Storage script tests passed;
- `tests/Integration/VersionedStorageDatabaseTest.php` passed;
- the integration test passed 20 consecutive runs after the canonical
  historical-restore boundary was applied;
- Composer manifest validation passed;
- metadata and scope checks passed;
- `git diff --check` passed.

The integration test covers exact schema v1/v2, record v1/v2, actor-checked
current-record resolution, stale CAS, Access allow/deny, update-only mutation
metadata, exact public/admin projection, sanitized exceptions and Audit,
Audit-failure rollback, SQLite reopen, data-bearing down refusal and clean
down/reapply. The package evidence guard separately verifies that the versioned
contract/runtime expose no restore-as-new method and that no corresponding
Access grant or Security Audit event is declared. It also passes a private
sentinel as caller correlation input and proves that only an opaque scoped hash
reaches persistence and Security Audit.

Separate-process restart, true simultaneous writers and isolated MySQL are
tracked in their dedicated proof files and are not claimed here until root
acceptance records them as passed.
