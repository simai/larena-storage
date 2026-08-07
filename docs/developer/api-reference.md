# Storage API Reference

## Core Contracts

| Contract | Purpose |
| --- | --- |
| `StorageRuntime` | Main read/write runtime contract. |
| `StorageSchema` | Describes fields, visibility and validation rules. |
| `StorageRecord` | Immutable record snapshot returned by runtime operations. |
| `StorageQuery` | Query descriptor for list/read operations. |
| `StorageMutation` | Mutation descriptor for create/update/delete-like operations. |
| `StorageValidationReport` | Result of validation before mutation. |
| `StoragePersistenceAdapter` | Persistence boundary used by storage runtime. |
| `StoragePersistenceProfile` | Named persistence profile descriptor. |
| `StorageAccessScopeResolver` | Consumes an access query scope decision. |
| `StorageAuditEmitter` | Emits storage mutation audit descriptors. |
| `VersionedStorage` | Exact immutable schema/record version service contract. |
| `StorageRecordListQuery` | Exact schema, typed equality filters, bounded limit and optional opaque continuation. |
| `StorageRecordListPage` | Current-head public items plus the next opaque continuation. |
| `StorageRecordListItem` | Exact current immutable ref and schema-owned public values only. |
| `StorageSchemaEvolution` | Bounded analyze/plan/explain/apply contract for optional-only evolution. |

## Runtime Classes

| Class | Purpose |
| --- | --- |
| `InMemoryStorageRuntime` | Deterministic runtime for schema, list, record and mutation tests. |
| `LaravelDatabaseStorageAdapter` | Baseline Laravel database adapter boundary. |
| `AccessScopedStorageRuntime` | Wraps storage reads with explicit access scope checks. |
| `AuditAwareStorageMutationRuntime` | Wraps storage mutations with audit event emission. |
| `StorageTransactionBoundary` | Encapsulates transaction execution and fail-closed persistence behavior. |
| `StorageValidationResult` | Runtime validation report implementation. |
| `VersionedStorage` | Database-native immutable version/CAS runtime guarded by Access and Audit. |
| `StorageOwnedTableShapeGuard` | Pre-DDL install/upgrade/down ownership verifier for package tables. |
| `DatabaseStorageSchemaEvolution` | Transactional content-addressed schema migration runtime. |
| `SchemaDefinitionNormalizer` | Shared fail-closed definition/value canonicalization boundary. |
| `OptionalFieldCompatibilityAnalyzer` | Pure additive-optional compatibility classifier. |
| `StorageSchemaMigrationTableShapeGuard` | Exact shape/ownership guard for migration plan/result tables. |
| `StorageSchemaEvolutionOwnerPolicyRegistry` | Container-local protected-owner policy and exact outer-transaction scope verifier. |

## Durable current-record list

`VersionedStorage::listCurrentRecords()` accepts a
`StorageRecordListQuery($schemaId, $filters, $limit, $continuation)`. Filters
are an object keyed by schema field; each value has exactly `operator` and
`value`, and the only supported operator is `eq`:

```php
$query = new StorageRecordListQuery(
    'inventory.widget',
    ['rank' => ['operator' => 'eq', 'value' => 7]],
    25,
);
$page = $storage->listCurrentRecords($query, 'actor:reader:42');
```

The runtime first requires `QueryScopeProvider` support and an allowed decision
for canonical resource type `storage.record` / operation
`storage.record.list`. This matches the operation target `storage.record:all`
used by `PersistentGlobalRoleQueryScopeProvider`. The exact schema id is not
placed in the resource type: it remains an exact typed query input and is bound
into query/scope continuation identities.

The provider receives the schema identity and structured caller filters and
must return exactly those top-level keys with every normalized caller filter
preserved. Caller filters are limited to schema-known `public` fields. A
trusted provider may add exact typed `public` or `protected` filters; it may not
change schema, delete/change a caller filter, add unknown/`admin` fields or use
an unsupported operator. Provider-added protected filters are applied in SQL
but never enter `StorageRecordListItem`. Invalid or missing scope never falls
back to an unscoped query.

After scope resolution, Storage loads the current schema, normalizes filter
values through the exact Property type/version, and rejects unknown,
non-public or invalid fields. The database query joins each record head to its
exact current immutable version and orders by unique `record_id`. Limits are
`1..100`. An opaque continuation is valid only with the same integrity key,
schema, normalized caller filters, actor-bound resolved Access decision and
scoped filters. Returned items expose the exact version ref and fields whose
current schema visibility is exactly `public`; they omit owner, audit and raw
private metadata.

Laravel provider wiring obtains an explicitly consumer-selected
`QueryScopeProvider` from the container and uses `app.key` for HMAC integrity.
Neither Storage nor Access creates a default interface binding. If provider or
key is unavailable, list calls fail closed. Direct package tests/consumers must
inject a provider and a key of at least 32 bytes. Package-local container proof
covers explicit injection and missing-binding rejection only; Root integration
is unclaimed. Reads emit no caller-controlled diagnostic payload.

## Schema Evolution DTOs

`StorageSchemaCompatibilityReport`, `StorageSchemaMigrationPlan` and
`StorageSchemaMigrationResult` are immutable. Their nested record DTOs expose
owner/ref/version/count/hash metadata only. Definitions, field keys and values
remain inside the Storage persistence boundary and never enter these DTOs.

`StorageSchemaEvolution::apply()` requires both `planRef` and the caller's
expected `planHash`. A mismatch, stale schema/record head, changed immutable
row or already-applied plan fails closed with a stable sanitized reason code.

`StorageSchemaEvolutionOwnerContext` is the safe binding material delivered to
a protected owner's validator. `StorageSchemaEvolutionTransactionScope` is an
opaque, registry-issued, callback-scoped object; constructing or retaining an
object is not authority because active scope membership and the exact database
connection are held in a registry-local `WeakMap`.

`plan()` and `apply()` accept trailing optional `transactionScope` and
`orchestrationCapability` arguments. Generic consumers omit them. A protected
owner supplies both only inside the registry's `withinTransaction()` callback
and validates/consumes its own capability.

## Migration Diagnostics

`StorageOwnedTableShapeRejected` exposes a stable `reasonCode` plus safe
logical `tableKey`. Its message contains the reason code only. Consumers must
not infer repair actions from the exception; recovery requires an explicit
operator plan.

## Enum Classes

| Enum | Purpose |
| --- | --- |
| `FieldVisibility` | Field exposure and projection policy. |
| `MutationType` | Mutation kind. |
| `StorageDecisionStatus` | Decision result for validation and runtime gates. |

## Usage Boundary

The public API should be treated as package-internal contracts for Larena
package integration until a dedicated public API launch record exists.

Downstream packages should depend on contracts, not concrete runtime classes,
unless a launch record explicitly allows a test/runtime adapter.
