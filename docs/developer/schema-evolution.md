# Bounded Storage Schema Evolution

## Supported Change

The current checkpoint accepts one compatibility class:
`optional_additions`. The candidate must keep the same `schema_id` and
`owner_package`, preserve every existing normalized field descriptor and the
relative order of existing fields, and add at least one field with
`required=false` and `constraints=[]`.

Removals, reorders, type/type-version/required/visibility/constraint changes,
required additions, constrained additions, no-op candidates and unknown keys
fail closed. Property now validates an exact type/version constraint set before
Storage persists an immutable schema. The constrained-addition limit remains
intentional because migration compatibility currently proves only optional
fields whose empty constraint set cannot invalidate existing records.

New registrations also require the separate Property constraint-validator
capability. A registry implementing only the historical type-registry contract
fails closed at this boundary. Storage does not retrofit or rewrite an old
immutable definition that predates this validation: exact schema/record reads
and public visibility projection remain available, while create/CAS and schema
evolution fail with stable codes if the historical constraints cannot validate
new values. Such a schema must be superseded through a future explicit,
non-destructive owner migration; silent repair is forbidden.

## Declared Transform

An owner may opt in to a second class, `declared_transform`, by passing
`declaredTransform: true` to `analyze()` and `plan()`. Only the schema identity
is fixed: fields may be removed, retyped, made required or optional, reordered
and added. Apply rewrites every record in the same transaction with
`DeclaredFieldValueTransform`:

- a removed field loses its value in the new revision; earlier revisions keep it;
- a retyped field is converted by fixed rules: text from scalars, integers from
  whole numbers and integer strings, numbers from numeric strings, booleans from
  `true/false/1/0/yes/no/да/нет`; nothing else is guessed;
- the target Property type then validates every value, including required fields.

If any record cannot be converted, planning fails with
`storage_schema_migration_record_unconvertible` and nothing is written. The
plan stores the class, so apply re-derives the same rewrite and refuses a plan
whose class no longer matches. Without the opt-in the behaviour below is
unchanged. The Storage workbench uses this class through
`StorageWorkbench::migrateStructure()` under
`storage.workbench.structure.migrate`.

## Workbench Field Changes (no record rewrite)

The Storage workbench no longer plans and applies a rewrite for its own
structures. A field change registers the next schema version and leaves every
record version as it was written: each version keeps its schema version, and
every reader of current values passes it through `StorageRecordConverter`.

- **Adding an optional field** (`updateStructure`, or `migrateStructure` with any
  constraints on the added optional field): a new schema version, an empty
  витрина column added online before the transaction (MySQL `ALGORITHM=INSTANT`),
  ready at once, nothing backfilled, no record version written. The field reads
  empty until a record is written with it.
- **Removing a field** (`migrateStructure`): a new schema version at once, no
  record version written; the витрина column is dropped after the commit and its
  side rows go. If the field was searched (or titled the rows) the search text is
  refilled by the `storage.view.rebuild` backfill; lists do not search it meanwhile.
- **A change every record must pass** (a retype, a field made required, changed
  constraints, a required added field): a field change. Every current record is
  converted and validated against the target fields; the витрина columns the target
  needs for existing fields (a retyped field's new column) are reserved before and
  filled during the walk, while lists keep answering from the old columns. Then the
  structure switches in one transaction and the old columns are dropped. Up to
  `DatabaseStorageWorkbench::INLINE_FIELD_CHANGE_LIMIT` (5000) records, or without
  a queue, it runs inside `migrateStructure` and is refused as a whole
  (`storage_schema_migration_record_unconvertible`) when a record does not convert.
  Above it, it runs as the `storage.schema.migrate` job and `migrateStructure`
  returns the unchanged structure carrying the running change.

### The read converter

`StorageRecordConverter::convert($schemaId, $from, $to, $values)` walks the schema
versions one change at a time, so a field removed and later added again under the
same key starts empty. One step:

- a value whose field is gone is dropped (the owner removed it);
- a value whose field keeps its type and type version is kept as stored;
- a value whose field changed type is converted by `DeclaredFieldValueTransform`
  and normalized and validated by the target Property type with its constraints; a
  value that cannot be converted is dropped. A current record always converts (a
  retype switches only after every current record passed, strictly); only an older
  history version can lose a value this way;
- an absent field stays absent: an optional field added later reads empty.

It is used by the workbench (single reads, lists, history), `VersionedStorage::
listCurrentRecords`, the published projection (`DatabaseReadContracts`), the
admin tree reader and the витрина rebuild. `readAdminVersion` and
`projectPublicVersion` still return a version exactly as stored, with its own
schema version.

History, revert, export and publication: the stored versions never change, so
history keeps what was written; read through the converter, a removed field's
value does not come back. A revert of an older version (admin: its history
values, written as a new revision) therefore writes only values of the current
fields and cannot resurrect what the owner removed. A write over a record whose
current version is at an older schema version is accepted and written at the
current one. Export reads the list, so it reads converted values.

### The field change job

`storage.schema.migrate`, payload `{"change_ref": "storage-field-change-<32 hex>",
"chunk": 500}` (`StorageFieldChangeJob`, registered deferred: the workbench is made
only when a worker runs it; maintenance priority, 1800 s an attempt, 50 attempts
15 s apart, a 300 s lease). The change is a row of `larena_storage_field_changes`
(target definition and descriptor, position, state `running|done|failed|cancelled`,
progress `checked/total`, reason); `DatabaseStorageWorkbench::runFieldChange()`
runs it (the job, and inline), `cancelFieldChange()` cancels it.

- Resumable: the position is saved with every chunk; a retry or a new job resumes
  (the request's idempotency key follows the position).
- Between chunks the job heartbeats and checks the queue checkpoint; a queue cancel
  or a timeout stops it after a committed chunk and leaves the change running.
- Idempotent: a change that ended is not run again. A newer run takes it over.
- Cancel-safe: the switch happens in one transaction only while the change is still
  running; a cancelled or failed change changed nothing (its reserved columns are
  dropped by the next change).
- Writes during the change are accepted under the current fields. The walk records
  the revision it checked of each record (`larena_storage_field_change_records`);
  before the switch every record written or created since is checked again, the last
  ones inside the switch's transaction, which holds the storage for update (writers
  hold it for share). A record that does not convert fails the change. No clock is
  involved.
- While it runs the structure accepts no other change
  (`storage_workbench_field_change_running`), nor an archive.

### Structure state for the admin

`StorageWorkbenchStructure` (from `readStructure`, `listStructures` and the change
calls) carries, read cheaply (the registry row, and one query for the changes):

- `viewState`: `?string` — `ready`, `building`, `pending`, `failed`, or null without
  a витрина;
- `viewBackfillPending`: `bool` — a ready витрина still fills rows (a new column,
  the search text): «индексируется…»;
- `viewPendingFields`: `list<string>` — the fields whose column is still filled, by a
  backfill or a running field change;
- `fieldChange`: `?StorageWorkbenchFieldChange` — the running change, or the last one
  that failed or was cancelled since the structure's current version
  (`changeRef`, `state`, `checked`, `total`, `fields`, `reason`, `startedAt`,
  `finishedAt`); `changing()` is true while one runs.

The generic `StorageSchemaEvolution` plan/apply below is unchanged: owners that
use it (content, docara) still rewrite their records through it.

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
