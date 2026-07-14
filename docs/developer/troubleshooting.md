# Storage Troubleshooting

## A Test Says The Runtime Is In-Memory Only

This is expected for the current slice. In-memory runtime is a deliberate
foundation adapter for deterministic tests and early package integration.
Production persistence requires a separate launch record.

## An Owned-Table Shape Migration Is Rejected

This is an intentional fail-closed result. Do not rename, drop or recreate an
existing table automatically. Record the stable reason code, inspect the table
against `owned-table-shape-guard.md`, back up the disposable/test target and
prepare an explicit recovery plan. Exception messages never include connection
names, SQL or database/schema names.

The generic Storage adapter remains partial, while the immutable typed-content
slice now has package-owned migrations under an accepted launch record. Do not
extend those tables or add a new migration lifecycle without a separate launch
scope and rollback evidence.

## A Schema Migration Is Rejected

Treat the reason code as a fail-closed diagnostic. Do not edit an immutable
plan/result row or bypass direct-v2 protection. Re-run `analyze()` against the
current schema head, confirm that every existing descriptor is identical and
that additions are optional with empty constraints, then create a new plan.
Stale schema/record heads require a new plan; a tampered plan requires forensic
review, not repair in place.

`required=false` permits an absent key only. Explicit null is not accepted by
the frozen Property built-ins and must not be introduced until Property and
Storage owners approve a canonical nullable contract.

`storage_schema_migration_owner_registry_unsealed` means the runtime was used
before the Storage provider boot boundary. `storage_schema_migration_owner_
orchestration_required` means a protected owner did not provide a valid active
scope/capability pair. Do not bypass protection. Confirm that the consumer
registered its policy on the final registry singleton, opened the outer
transaction on the same connection, and issued a fresh capability bound to the
exact operation and hashes.

## Access Scope Blocks A Query

Treat this as a safety behavior first. Check the access scope resolver and
confirm whether the query is allowed. Storage must not bypass access decisions.

## Audit Event Is A Descriptor Only

This is expected. Storage emits mutation descriptors. Audit storage, retention
and review belong to `larena/audit`.

## Documentation And Code Drift

If a runtime class changes, update:

- `docs/developer/*`;
- documentation map in `larena-specs`;
- code location map in `larena-specs`;
- relevant implementation evidence.
