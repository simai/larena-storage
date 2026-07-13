# Storage Runtime Behavior

## Mutation Flow

```text
mutation request
-> schema lookup
-> validation
-> access scope check when wrapped
-> transaction boundary
-> persistence adapter or in-memory mutation
-> audit emission when wrapped
-> record snapshot / fail-closed decision
```

Validation must run before mutation. Audit emission must describe the storage
mutation without leaking raw secrets or unrelated payloads.

## Fail-Closed Rules

Storage must fail closed when:

- schema is missing or invalid;
- mutation fails validation;
- access scope is denied or unavailable;
- persistence adapter cannot write safely;
- transaction boundary reports failure;
- audit-aware wrapper cannot create a safe audit descriptor.

Fail-closed behavior is expected, not a bug. A failed mutation should preserve
state and produce an inspectable decision/result.

## Persistence Boundary

`LaravelDatabaseStorageAdapter` remains the generic baseline adapter boundary.
The immutable typed-content slice additionally owns four additive
`larena_storage_*` tables. Their current table shape, install/upgrade preflight
and unused rollback are package contracts; this does not make the wider Storage
platform production-ready.

`StorageOwnedTableShapeGuard` inspects each existing owned table before DDL. It
normalizes SQLite/MySQL differences through Laravel column/index metadata and
checks exact column names, portable type family, nullability, auto increment,
MySQL length/unsigned metadata where exposed, ordered primary-key composition,
and explicit unique/secondary index names and compositions. The creation migration runs the guard before DDL
and again after creating missing tables. The following read-only migration
validates already-installed tables during declared upgrades.

Future launch records must still decide query translation, retention, cleanup,
large migrations, backup/restore and production rollout policy.

## Access And Audit Boundaries

Storage consumes access and audit boundaries. It does not own their policies.

- Access decides what query scope applies.
- Storage applies the scope to storage runtime behavior.
- Audit receives a safe descriptor of mutation activity.
- Audit retention, indexing and security review remain outside storage.
