# Changelog

All notable changes to `larena/storage` are documented in this file.

## Unreleased

### Added

- Add additive database tables and contracts for immutable schema and record
  versions.
- Add exact-version admin reads, an actor-checked current-version resolver and
  public-field-only exact projections.
- Add compare-and-swap writes, Access operation registration and transactional
  Security Audit events for schema creation/versioning and record
  creation/update.
- Add disposable SQLite verification for persistence, stale-writer rejection,
  Audit-failure atomicity and safe migration rollback/reapply.
- Convert caller-provided correlation inputs to opaque package-scoped hashes
  before they reach database or Security Audit metadata.
- Add a driver-normalized owned-table shape guard for exact columns, portable
  type/nullability/auto-increment contracts, primary-key composition and
  explicit unique/secondary index names and compositions.
- Add a read-only upgrade validation migration and file-backed SQLite
  adversarial coverage for foreign/partial tables, missing and wrongly composed
  indexes, idempotent completion and rollback/reapply.

### Changed

- Keep Docara historical revision restoration outside the versioned Storage
  mutation API: the consumer reuses an exact immutable version reference
  instead of asking Storage to create a new restore version.
- Make the versioned `compareAndSwap()` path unconditionally emit an `update`
  record version and `storage.record.updated` Audit event.
- Run the full owned-table preflight before the first DDL statement and verify
  the completed topology again after creating missing tables.

### Migration notes

- The migration is additive and creates four `larena_storage_*` tables.
- A correct empty subset of those tables may be completed during install.
- Install and upgrade refuse foreign columns, incompatible portable column
  metadata, missing or wrongly composed indexes, and data-bearing partial
  topologies before creating a missing table.
- `down()` is a no-op only when all four tables are absent. It refuses partial
  or incompatible shapes and data before any drop, and drops only the full,
  compatible, empty owned topology.
- Clean unused databases support deterministic down/reapply; the second
  migration performs read-only upgrade validation.

This unreleased slice does not claim production readiness or readiness of all
Larena packages.
