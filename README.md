# Larena Storage

Universal dynamic data storage layer for typed schemas, records, lists, query
boundaries, persistence profiles, validation pipelines and portable
SitePack-compatible data models.

Current implementation state: partial data/content foundation runtime. The
package has an in-memory runtime slice plus an additive database-native slice
for immutable schema versions, immutable record versions, compare-and-swap,
Access authorization, transactional Security Audit and exact public
projection.

The database-native slice now also exposes a bounded schema-evolution service.
It can analyze, persist, explain and atomically apply only additive optional
fields with empty constraints. Direct publication of schema version 2+ is
blocked: callers must use a content-addressed immutable migration plan. Apply
locks and rechecks the schema head, every record head and every immutable
definition/value hash before it publishes a new schema version and matching
`schema_migration` record revisions.

Schema-owning consumers may register an owner-neutral protection policy before
Storage provider boot. Protected namespaces require the consumer's one-shot
capability inside the exact outer transaction and connection; direct generic,
forged, expired or replayed plan/apply calls fail closed before mutation.
Storage does not issue consumer capabilities or own their aggregate workflow.

The versioned database contract deliberately separates historical reads from
new writes:

- `readAdminVersion()` returns an exact immutable version after an actor-based
  Access check;
- `readAdminCurrentVersion()` resolves the current record for an owner after
  the same Access check and supports a consumer's next compare-and-swap;
- `compareAndSwap()` always creates an `update` version from the exact current
  head supplied by the caller;
- record creation and compare-and-swap accept only the current exact schema
  head, using schema-first locking to share the migration lock order;
- caller correlation inputs are converted to opaque package-scoped hashes
  before persistence or Security Audit, so submitted content cannot be copied
  through that metadata channel;
- Docara historical revision restoration belongs to Docara and reuses an exact
  immutable reference; this versioned Storage contract does not expose a
  restore-as-new mutation.

The package-owned version and migration tables are additive and protected by
separate shared shape guards.
Before the first create or drop, the guard validates the complete column
contract plus primary-key composition and the explicit names/compositions of
unique and secondary indexes through Laravel's portable schema inspection
APIs. A compatible empty partial topology
can be completed. Foreign, damaged or data-bearing partial topologies fail
closed without partial DDL. A clean unused migration can be rolled back and
reapplied; rollback refuses before dropping anything when typed-content rows
exist. The plan/result tables additionally have isolated real-MySQL evidence
for exact type/index contracts, restart, concurrent apply and cleanup.

See `docs/developer/schema-evolution.md` for the bounded evolution contract and
`docs/developer/owned-table-shape-guard.md` for the exact install, upgrade,
diagnostic and rollback contracts.

Production readiness, encryption policy, SitePack portability and readiness of
all Larena packages are not claimed by this slice.

Canonical specifications are in `simai/larena-specs`.

Developer documentation starts at `docs/developer/README.md`.
