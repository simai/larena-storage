# Managed Docara Content Types: Storage M1-M3 Evidence

This packet now covers Storage-owned table hardening plus the bounded schema
evolution runtime: safe compatibility reports, immutable plans/results,
optional-only apply, Access/Security Audit integration, exact-head locking and
record-version migration.

Executable file-backed SQLite evidence covers success, incompatible and
unknown changes, tampering, stale heads, Access denial, Audit rollback,
immutable-read corruption, owner-protected orchestration, safe
rollback/reapply and a two-process one-winner apply race. Opt-in real-MySQL
evidence now covers all four new migration-table contracts, clean install,
restart/reconnect, two-process one-winner apply, exact value preservation,
used rollback refusal and verified cleanup to zero.

The Storage seam for launch criterion 232 is owner-neutral: protected owners
must validate a one-shot capability inside the exact outer transaction and
connection; direct, forged, cloned, expired or replayed calls fail closed.
Docara still owns its concrete capability issuer and ContentType/Page aggregate
transaction. Entry-app publication and root aggregate acceptance remain outside
this package handoff. This packet makes no production-readiness or
all-packages-readiness claim.
