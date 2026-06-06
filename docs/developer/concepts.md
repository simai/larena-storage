# Storage Concepts

## Ownership

`larena/storage` owns:

- schema and field descriptors;
- record read/list and mutation contracts;
- validation pipeline boundary;
- persistence adapter/profile boundary;
- transaction boundary used by storage mutations;
- access-scope and audit emitter integration points.

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

This keeps early batches useful without pretending that production persistence
or full schema migration support is complete.

## Current Non-Goals

- No production database migrations.
- No schema diff/version lifecycle.
- No encryption key policy.
- No SitePack import/export runtime.
- No admin UI or REST endpoints.
- No hidden ownership of access or audit policy.
