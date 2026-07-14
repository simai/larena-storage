# Implementation Summary

- Preserved the M1 exact SQLite/MySQL version-table shape guard and added an
  independent four-table guard/migration for immutable plans and results.
- Added `StorageSchemaEvolution` and immutable safe compatibility, plan,
  record-head, result and record-result DTOs.
- Added shared fail-closed schema/value normalization and optional-field-only
  compatibility analysis.
- Blocked direct schema v2+ registration. Create/CAS now lock and require the
  current exact schema head in the common schema-before-record lock order.
- Added content-addressed plan/explain/apply with source/plan/item/value hash
  verification, immutable record revisions and one-winner result uniqueness.
- Added a container-local, sealed, owner-neutral protection registry. Protected
  owners may require an exact transaction scope plus their own one-shot
  capability; generic direct plan/apply and token replay fail before mutation.
- Added distinct Access operations and sanitized analyzed/planned/applied/
  rejected Security Audit events; Audit failure rolls all apply writes back.
- Hardened immutable schema/record reads against transplanted definitions,
  mismatched owners, cross-table head drift and content-hash corruption.
- Added basic, adversarial, owner-protection, provider-order,
  migration-shape and two-process concurrency tests; updated the legacy
  database integration test to use the migration path.
- Added opt-in real-MySQL acceptance for exact migration-table column/index
  contracts, restart/reconnect, concurrent apply, strict value preservation,
  clean/used rollback behavior and generated-schema cleanup to zero.

Only additive optional fields with empty constraints are supported. No Docara,
Property, Access or Audit package source was changed.
