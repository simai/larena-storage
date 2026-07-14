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
- Add the public `StorageSchemaEvolution` contract with immutable compatibility,
  plan and result DTOs that expose only safe refs, counts and hashes.
- Add an optional-field-only compatibility analyzer, content-addressed plans,
  atomic schema/record migration results and four guarded migration tables.
- Add sanitized Access operations and Security Audit events for analyze, plan,
  explain, dispatch, apply and rejection paths.
- Add adversarial file-backed SQLite coverage for incompatible definitions,
  unknown keys, tampered plan/source/record state, stale heads, Access denial,
  Audit failure rollback and a two-process one-winner apply race.
- Add a sealed container-local owner policy registry with opaque
  transaction-scoped proof and consumer-validated one-shot orchestration
  capabilities for protected schema namespaces.
- Add provider-order and protected-owner adversarial coverage for direct,
  forged, cloned, expired, mismatched and replayed plan/apply attempts.
- Add opt-in isolated real-MySQL acceptance for all four migration-table shape
  contracts, clean install/down/reapply, restart, concurrent one-winner apply,
  strict value preservation, used rollback refusal and cleanup to zero.

### Changed

- Keep Docara historical revision restoration outside the versioned Storage
  mutation API: the consumer reuses an exact immutable version reference
  instead of asking Storage to create a new restore version.
- Make the versioned `compareAndSwap()` path unconditionally emit an `update`
  record version and `storage.record.updated` Audit event.
- Run the full owned-table preflight before the first DDL statement and verify
  the completed topology again after creating missing tables.
- Block direct schema version 2+ registration and require a verified migration
  plan for evolution.
- Require create/CAS to lock and match the current exact schema head; harden
  immutable reads against transplanted definitions, owner mismatches and
  content-hash corruption.

### Migration notes

- The original migration is additive and creates four version tables. The
  schema-evolution migration adds four immutable plan/result tables.
- A correct empty subset of those tables may be completed during install.
- Install and upgrade refuse foreign columns, incompatible portable column
  metadata, missing or wrongly composed indexes, and data-bearing partial
  topologies before creating a missing table.
- `down()` is a no-op only when all four tables are absent. It refuses partial
  or incompatible shapes and data before any drop, and drops only the full,
  compatible, empty owned topology.
- Clean unused databases support deterministic down/reapply; the second
  migration performs read-only upgrade validation.
- The evolution migration performs its own full SQLite/MySQL shape preflight.
  It refuses foreign/damaged/used partial shapes and refuses `down()` when any
  plan/result data exists.

This unreleased slice does not claim production readiness or readiness of all
Larena packages.
