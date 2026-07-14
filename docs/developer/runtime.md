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
The immutable typed-content slice additionally owns four version tables and
four migration plan/result tables. Their current table shape, install/upgrade preflight
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

## Schema Evolution Boundary

The evolution sequence is `analyze -> plan -> explain/apply`. Direct v2+
registration fails with `storage_schema_version_requires_migration_plan`.
Plans and results are insert-only and content-addressed. Apply uses the common
lock order `schema head -> ordered record heads`, recomputes plan/source/value
hashes, then writes the target schema, record revisions, result and Security
Audit event in one database transaction. Audit failure rolls everything back.

Only optional added fields with empty constraints are accepted. Existing field
descriptors and their relative order must be unchanged. Unknown definition
keys, explicit nulls unsupported by Property, removals, reorders, required
additions and added-field constraints fail closed.

The container-local owner-policy registry is sealed at Storage provider boot.
An owner may protect its package/schema prefix and require a one-shot
capability inside the exact outer transaction and database connection. Direct
generic plan/apply, wrong actor/operation/ref/hash, forged or replayed
capabilities, expired scopes and cross-connection reuse all fail before
mutation. Storage verifies scope; the consumer owns capability issuance and
its aggregate transaction.

## Access And Audit Boundaries

Storage consumes access and audit boundaries. It does not own their policies.

- Access decides what query scope applies.
- Storage applies the scope to storage runtime behavior.
- Audit receives a safe descriptor of mutation activity.
- Audit retention, indexing and security review remain outside storage.
