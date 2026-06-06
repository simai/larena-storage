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

`LaravelDatabaseStorageAdapter` is a baseline adapter boundary. It exists to
prove that storage can talk to Laravel database concepts without committing to
production schema migrations.

Future launch records must decide:

- table shape;
- migration lifecycle;
- rollback behavior;
- schema versioning;
- query builder translation;
- retention and cleanup policy.

## Access And Audit Boundaries

Storage consumes access and audit boundaries. It does not own their policies.

- Access decides what query scope applies.
- Storage applies the scope to storage runtime behavior.
- Audit receives a safe descriptor of mutation activity.
- Audit retention, indexing and security review remain outside storage.
