# Larena Storage

Universal dynamic data storage layer for typed schemas, records, lists, query
boundaries, persistence profiles, validation pipelines and portable
SitePack-compatible data models.

Current implementation state: partial data/content foundation runtime. The
package has an in-memory runtime slice plus an additive database-native slice
for immutable schema versions, immutable record versions, compare-and-swap,
Access authorization, transactional Security Audit and exact public
projection.

The versioned database contract deliberately separates historical reads from
new writes:

- `readAdminVersion()` returns an exact immutable version after an actor-based
  Access check;
- `readAdminCurrentVersion()` resolves the current record for an owner after
  the same Access check and supports a consumer's next compare-and-swap;
- `compareAndSwap()` always creates an `update` version from the exact current
  head supplied by the caller;
- caller correlation inputs are converted to opaque package-scoped hashes
  before persistence or Security Audit, so submitted content cannot be copied
  through that metadata channel;
- Docara historical revision restoration belongs to Docara and reuses an exact
  immutable reference; this versioned Storage contract does not expose a
  restore-as-new mutation.

The new tables are additive. A clean unused migration can be rolled back and
reapplied; rollback refuses before dropping anything when typed-content rows
exist.

Production readiness, encryption policy, SitePack portability and readiness of
all Larena packages are not claimed by this slice.

Canonical specifications are in `simai/larena-specs`.

Developer documentation starts at `docs/developer/README.md`.
