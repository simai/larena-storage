# Implementation summary

`Larena\Storage\Contracts\VersionedStorage` now provides database-native,
immutable schema and record versions. The additive model uses four tables:

- `larena_storage_schemas` — schema heads;
- `larena_storage_schema_versions` — immutable schema definitions;
- `larena_storage_records` — record heads;
- `larena_storage_record_versions` — immutable normalized values.

Schema creation and versioning have distinct Access operations and Security
Audit events. Record create, update and actor-checked admin reads also have
distinct Access boundaries. Head movement uses conditional updates, so a stale
expected revision fails closed. `compareAndSwap()` can only persist an `update`
version and emit `storage.record.updated`.

Property performs type normalization and validation. Storage persists values,
but full exact reads are not exposed without an actor: `readAdminVersion()` and
`readAdminCurrentVersion()` are Access-checked, while
`projectPublicVersion()` accepts an exact immutable ref and returns only fields
marked `public` in that exact schema version.

This versioned Storage contract has no restore-as-new mutation, Access grant or
Security Audit event. Docara historical revision restoration is owned by
Docara and reuses a chosen exact immutable record-version reference. A later
edit resolves the actor-checked current head and performs the normal
compare-and-swap update.

Storage and its Audit pipeline execute inside the same database transaction.
An Audit exception rolls back both the immutable version and head movement.
Audit payloads contain only safe refs, versions, operations and counts.
Caller-provided correlation inputs are represented only by an opaque
package-scoped SHA-256 identifier; their raw text is never persisted or
forwarded to Security Audit.

No routes, controllers, templates, assets, frontend, REST/MCP or unsupported
property types were added.
