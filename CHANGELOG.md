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

### Changed

- Keep Docara historical revision restoration outside the versioned Storage
  mutation API: the consumer reuses an exact immutable version reference
  instead of asking Storage to create a new restore version.
- Make the versioned `compareAndSwap()` path unconditionally emit an `update`
  record version and `storage.record.updated` Audit event.

### Migration notes

- The migration is additive and creates four `larena_storage_*` tables.
- `down()` refuses before any drop when those tables contain data.
- Clean unused databases support deterministic down/reapply.

This unreleased slice does not claim production readiness or readiness of all
Larena packages.
