# Tests

Fresh final candidate evidence:

- complete Storage Composer test suite: PASS;
- focused database-native versioned Storage test: PASS;
- invalid new schemas, a missing validator capability, unsupported/future type
  versions and legacy optional-invalid create/CAS all fail closed;
- exact legacy admin/public reads survive without exposing admin values;
- external-process file-backed SQLite restart/race: PASS with one CAS winner;
- isolated real-MySQL Storage schema-evolution shape/race/rollback test: PASS;
- Root MySQL typed/managed/shape composition: `3 tests / 405 assertions` PASS;
- independent verdict: PASS, `P0=0`, `P1=0`, `P2=0`;
- package lint and `git diff --check`: PASS.

Temporary test-only vendor and credential symlinks were removed and are not
part of the worktree or publishable surface.
