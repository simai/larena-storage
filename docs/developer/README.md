# Larena Storage Developer Guide

## Purpose

`larena/storage` owns Larena's dynamic data storage runtime. It provides typed
schemas, records, list/query boundaries, mutation validation, baseline
persistence profiles and integration points for access and audit.

Storage is not an ORM replacement and it does not own access policy, audit
policy, encryption policy, SitePack import/export, UI rendering or admin
screens. It exposes safe contracts that those packages can consume.

## Current Implementation Slice

The current implemented slice covers:

- in-memory schema and record runtime;
- validation-gated record mutations;
- baseline `laravel_database_default` persistence adapter boundary;
- access-scoped runtime wrapper;
- audit-aware mutation wrapper;
- transaction boundary abstraction;
- fail-closed tests for missing scope, invalid mutation and persistence
  failures;
- additive database-native immutable schema/record versions with exact reads,
  compare-and-swap, Access and transactional Security Audit;
- package-owned migration shape preflight and read-only upgrade validation for
  SQLite/MySQL portable column/index contracts.

Canonical evidence:

- `docs/project-management/evidence/data-content/batch-2/storage-runtime-slice/`
- `docs/project-management/evidence/data-content/batch-3/storage-persistence-access-audit/`
- `docs/project-management/evidence/typed-page-content-schema/`
- `docs/project-management/evidence/managed-docara-content-types/`

## Feature Coverage

| Feature | Current state | Notes |
| --- | --- | --- |
| `storage.schema_registry` | Partial | In-memory baseline plus immutable database schema versions; broader lifecycle/evolution policy remains incomplete. |
| `storage.persistence_profiles` | Partial | Only `laravel_database_default` baseline profile is represented. |
| `storage.record_list` | Partial | Access scope boundary exists; full DB query translation is future work. |
| `storage.record_mutation` | Partial | In-memory and exact-version database mutation slices exist; general-purpose production rollout is not claimed. |
| `storage.validation_pipeline` | Partial | Validation runs before mutation. Property/lang/licensing stages are future work. |
| `storage.owned_table_shape_guard` | Implemented checkpoint | Fresh/upgrade/down preflight validates exact package-owned table contracts before DDL. |

## Reading Path

1. Read `docs/developer/concepts.md` for ownership boundaries.
2. Read `docs/developer/api-reference.md` for contracts and runtime classes.
3. Read `docs/developer/runtime.md` for state and fail-closed behavior.
4. Read `docs/developer/owned-table-shape-guard.md` before changing migrations.
5. Read `docs/developer/testing.md` before changing implementation.
6. Read `docs/developer/troubleshooting.md` when a smoke or evidence check
   reports a degraded state.

## Canonical Source Rules

This documentation explains the implementation. It does not authorize new
features and does not override `larena-specs`.

If the docs reveal a missing feature boundary or relation, create a graph/spec
proposal through the Larena process instead of silently changing package
behavior.
