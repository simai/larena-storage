# Implementation summary

`SchemaDefinitionNormalizer` now requires a registry that also exposes the
separate `PropertyConstraintValidator` capability when accepting new schema
definitions. Unsupported keys, malformed values and contradictory ranges fail
closed as `storage_schema_constraint_invalid` before transaction, head, version
or success-Audit state is created.

Historical database rows are decoded without rewriting old accepted
constraints, preserving immutable stored versions and exact reads. Before any
record write, Storage validates every exact field constraint set, including an
omitted optional field, and only then normalizes submitted values. A legacy
invalid schema therefore remains readable but cannot accept create or CAS.

No new persistence adapter, restore mutation, route, controller, frontend or
destructive schema migration is introduced by this slice.
