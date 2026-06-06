# Storage Troubleshooting

## A Test Says The Runtime Is In-Memory Only

This is expected for the current slice. In-memory runtime is a deliberate
foundation adapter for deterministic tests and early package integration.
Production persistence requires a separate launch record.

## Database Persistence Looks Incomplete

This is expected. The current adapter proves the Laravel database boundary, not
production schema migrations. Do not add migrations or table policy without a
launch record.

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
