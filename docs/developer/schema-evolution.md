# Bounded Storage Schema Evolution

## Supported Change

The current checkpoint accepts one compatibility class:
`optional_additions`. The candidate must keep the same `schema_id` and
`owner_package`, preserve every existing normalized field descriptor and the
relative order of existing fields, and add at least one field with
`required=false` and `constraints=[]`.

Removals, reorders, type/type-version/required/visibility/constraint changes,
required additions, constrained additions, no-op candidates and unknown keys
fail closed. The constrained-addition limit is intentional: the frozen
Property API validates values but does not expose a schema-level constraint
validator capable of proving a new empty-valued field usable.

## Public Flow

```php
$report = $evolution->analyze($source, $candidate, $actor);
$plan = $evolution->plan($source, $candidate, $actor);
$samePlan = $evolution->explain($plan->planRef, $actor);
$result = $evolution->apply($plan->planRef, $plan->planHash, $actor);
```

All public operations authorize the actor before validating caller-controlled
plan identifiers. `analyze` never persists a plan. `plan` captures the exact
current schema and ordered record heads in content-addressed immutable rows.
`explain` recomputes the persisted plan hash before returning safe DTOs.

## Protected Owner Orchestration

`StorageSchemaEvolutionOwnerPolicyRegistry` lets any schema owner protect its
own namespace without Storage knowing that package. A consumer registers one
validator for its `owner_package` and optional schema prefix during provider
registration. Storage resolves and seals the container-local registry at its
boot boundary; duplicate or late policies are rejected. Consumer installation
state must be tracked per registry object, because a transient registry can be
resolved before Storage binds the final singleton.

For a protected schema, direct generic `plan()` or `apply()` fails with
`storage_schema_migration_owner_orchestration_required` before persistence.
The owner starts its outer database transaction, calls
`StorageSchemaEvolutionOwnerPolicyRegistry::withinTransaction()` and passes the
issued `StorageSchemaEvolutionTransactionScope` plus its own opaque capability
to `plan()` or `apply()`. The registry proves the exact active connection and
scope; the owner validator remains responsible for consuming a one-shot
capability bound to operation, actor, source ref/hash, target hash and, for
apply, plan ref/hash.

Forged, cloned, mismatched, expired or replayed capabilities fail through the
same sanitized reason. The registry never stores owner secrets and does not
issue owner capabilities. Once the registry is sealed, owners without a
matching protection policy retain the generic Storage flow.

## Apply Invariants

Apply performs all writes in one transaction and fails closed unless:

- the expected hash matches the verified persisted plan;
- no result already exists for the plan;
- the schema head still matches the source version/hash;
- the source definition and every current immutable record version recompute
  to their stored hash and owner;
- the ordered record heads exactly match the plan;
- every stored value normalizes identically under the target schema.

The transaction publishes the target immutable schema version, advances every
record with an unchanged-value `schema_migration` revision, inserts immutable
result rows and emits `storage.schema_migration.applied`. Any database or Audit
failure rolls the transaction back. A unique result-by-plan index and exact
head CAS make concurrent apply one-winner.

## Safe Surfaces

Migration DTOs and Audit payloads contain refs, versions, counts, hashes and
reason codes only. Candidate definitions, field keys and record values are
forbidden from Audit. Caller correlation values are converted to opaque
package-scoped hashes.

The four migration tables have an independent SQLite/MySQL shape guard. It
preflights all existing tables before DDL, refuses foreign or used partial
topologies and refuses rollback when any plan/result row exists.

## Explicit Limits

`required=false` means that a value key may be absent. It does not make
explicit null valid. The frozen built-in Property types reject null, and this
runtime preserves that owner decision. Nullable semantics require a separate
owner-approved contract.

This checkpoint does not claim arbitrary schema migrations, large online
migrations, production readiness or readiness of all Larena packages.
