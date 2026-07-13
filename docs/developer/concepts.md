# Storage Concepts

## Ownership

`larena/storage` owns:

- schema and field descriptors;
- record read/list and mutation contracts;
- validation pipeline boundary;
- persistence adapter/profile boundary;
- transaction boundary used by storage mutations;
- access-scope and audit emitter integration points;
- exact package-owned table shape and safe install/upgrade/unused rollback for
  the immutable typed-content slice.

`larena/storage` consumes:

- `larena/access` query scope decisions;
- `larena/audit` event emission boundary;
- future `larena/secret` and encryption policy decisions;
- future SitePack portability contracts.

`larena/storage` must not own:

- user permissions;
- audit retention or audit storage;
- secret key lifecycle;
- UI controls or admin screens;
- file/blob delivery;
- search indexing.

## Runtime Model

The package is built around three layers:

1. Contracts: stable PHP interfaces and value objects.
2. In-memory runtime: deterministic implementation used for tests and early
   integration.
3. Adapter wrappers: persistence, access and audit boundaries that can be
   replaced by production integrations later.
4. Database-native versioned slice: exact immutable schema/record references,
   compare-and-swap and guarded package-owned migrations.

This keeps the implemented slices useful without pretending that the wider
Storage platform, arbitrary schema evolution or production rollout is complete.

## Current Non-Goals

- No production-readiness claim or automatic repair of foreign table shapes.
- No arbitrary/destructive schema evolution; managed optional-field evolution
  belongs to the active goal beyond the shape-hardening checkpoint.
- No encryption key policy.
- No SitePack import/export runtime.
- No admin UI or REST endpoints.
- No hidden ownership of access or audit policy.
