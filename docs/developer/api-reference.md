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
