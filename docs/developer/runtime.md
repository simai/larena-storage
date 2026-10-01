# Storage Runtime Behavior

## Mutation Flow

```text
mutation request
-> schema lookup
-> validation
-> access scope check when wrapped
-> transaction boundary
-> persistence adapter or in-memory mutation
-> audit emission when wrapped
-> record snapshot / fail-closed decision
```

Validation must run before mutation. Audit emission must describe the storage
mutation without leaking raw secrets or unrelated payloads.

## Fail-Closed Rules

Storage must fail closed when:

- schema is missing or invalid;
- mutation fails validation;
- access scope is denied or unavailable;
- persistence adapter cannot write safely;
- transaction boundary reports failure;
- audit-aware wrapper cannot create a safe audit descriptor.

Fail-closed behavior is expected, not a bug. A failed mutation should preserve
state and produce an inspectable decision/result.

## Persistence Boundary

`LaravelDatabaseStorageAdapter` remains the generic baseline adapter boundary.
The immutable typed-content slice additionally owns four version tables and
four migration plan/result tables. Their current table shape, install/upgrade preflight
and unused rollback are package contracts; this does not make the wider Storage
platform production-ready.

`StorageOwnedTableShapeGuard` inspects each existing owned table before DDL. It
normalizes SQLite/MySQL differences through Laravel column/index metadata and
checks exact column names, portable type family, nullability, auto increment,
MySQL length/unsigned metadata where exposed, ordered primary-key composition,
and explicit unique/secondary index names and compositions. The creation migration runs the guard before DDL
and again after creating missing tables. The following read-only migration
validates already-installed tables during declared upgrades.

Future launch records must still decide query translation, retention, cleanup,
large migrations, backup/restore and production rollout policy.

## Schema Evolution Boundary

The evolution sequence is `analyze -> plan -> explain/apply`. Direct v2+
registration fails with `storage_schema_version_requires_migration_plan`.
Plans and results are insert-only and content-addressed. Apply uses the common
lock order `schema head -> ordered record heads`, recomputes plan/source/value
hashes, then writes the target schema, record revisions, result and Security
Audit event in one database transaction. Audit failure rolls everything back.

Only optional added fields with empty constraints are accepted. Existing field
descriptors and their relative order must be unchanged. Unknown definition
keys, explicit nulls unsupported by Property, removals, reorders, required
additions and added-field constraints fail closed.

The container-local owner-policy registry is sealed at Storage provider boot.
An owner may protect its package/schema prefix and require a one-shot
capability inside the exact outer transaction and database connection. Direct
generic plan/apply, wrong actor/operation/ref/hash, forged or replayed
capabilities, expired scopes and cross-connection reuse all fail before
mutation. Storage verifies scope; the consumer owns capability issuance and
its aggregate transaction.

## Access And Audit Boundaries

Storage consumes access and audit boundaries. It does not own their policies.

- Access decides what query scope applies.
- Storage applies the scope to storage runtime behavior.
- Audit receives a safe descriptor of mutation activity.
- Audit retention, indexing and security review remain outside storage.

## Localized Reads And Scheduled Publication

A public read (`ReadContracts`) takes its locale chain from the
`LocaleFallbackResolver` port. Lang owns the order; the application binds the
port to Lang's `FallbackPolicy::chainFor()`, so a record falls back in the same
order as interface text. Without a binding, `RequestedLocaleOnly` tries the
requested locale alone and a missing translation shows the shared value.

A schedule is published by `PublicationLifecycle::sweep()`, never by a read.
`php artisan storage:publication:sweep` runs it (`--limit`, `--now`), and the
provider schedules it every minute without overlap. On ordinary hosting one cron
line starts the Laravel scheduler:

```text
* * * * * php /path/to/site/artisan schedule:run >> /dev/null 2>&1
```

Each publication made by the sweep is recorded in the record's publication
history under the actor `system:scheduler`.

## Published Projection As A Search Source

Search indexes the published projection. Each entry carries
`projection_version`, the newest publication log id plus the newest localized
value id of the record in that locale. It only grows and moves on every
publication transition and every translation, so a derived index can use it as
its monotonic source revision. `publishedProjection(..., afterRecordId)` walks
the projection in record id order and `publishedRecord()` reads one entry. An
application-bound `PublicationObserver` hears every transition with the version
it produced; its failures are swallowed and a rebuild heals what it missed.

## Translations Pass Property Validation

`LocalizedValues::write()` validates a translation the way a shared value is
validated: the field must exist in the schema version of that record revision,
and Property's `normalizeAndValidate()` must accept the value for the field's
type, type version and constraints. The normalized value is stored. A refusal is
`LocalizedValueRejected` with `unknown_revision`, `field_unknown` or
`value_invalid`, and nothing is written. `null` is accepted only for an optional
field.

## Who May Read A Published Record

The read operations (`storage.read.*`) and the application's REST site boundary
ask the `PublishedReadVisibility` port for the caller's filter and pass it to
`ReadContracts`. A hidden record is the same miss as an absent one, and
`projectionExplain()` reports it only as `filtered_record_count`. The default
binding, `PublishedRecordsArePublic`, lets everyone read every published record:
the reads return public fields of published heads only. Access has no row scope
yet; a composition with record-level rules binds its own implementation.

## Publication Audit

`DatabasePublicationLifecycle` audits every transition itself, inside the
transaction that writes the state and log rows: `storage.publication.published`,
`.unpublished`, `.scheduled`, `.archived` and `.swept`, with actor, scope,
locale, revisions and correlation id, never a field value. The operation path,
`storage:publication:sweep` and direct calls are audited alike. A call without a
correlation id gets a fresh one, and one sweep shares one id across its
publications. If the audit event cannot be written, the transition is rolled
back.

`composer test:mysql-publication` proves restart readback on MySQL: it creates
a disposable database on the local server named in the root's ignored
`.env.auth-mfa-mysql-test`, publishes, withdraws, schedules and sweeps, reads
heads, history and revisions back through a new connection, and drops the
database.
