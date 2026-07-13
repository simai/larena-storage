# Storage-Owned Table Shape Guard

## Purpose

The immutable typed-content slice owns exactly four tables. A same-named table
is not assumed to belong to Storage merely because it exists. Install, upgrade
and rollback first prove the package-owned shape through Laravel schema APIs.

The guard validates:

- the supported SQLite/MySQL driver before touching schema metadata or DDL;
- the exact column-name set;
- portable column families, nullability and auto-increment flags;
- MySQL length and unsigned metadata when Laravel exposes it;
- ordered primary-key compositions;
- explicit unique-index names and ordered compositions;
- explicit non-unique secondary-index names and ordered compositions.

The package-declared names of unique and secondary indexes are ownership
evidence and are verified. Primary-key names are deliberately not fixed:
SQLite may expose an engine-generated name while MySQL exposes `primary`, so
the primary contract uses composition and flags only.

## Install And Upgrade

`2026_07_13_000001_create_larena_storage_version_tables.php` performs a full
preflight before its first DDL statement:

1. validate every existing owned table;
2. reject a data-bearing partial topology;
3. create only missing tables in a compatible empty topology;
4. validate the complete topology again.

`2026_07_14_000001_validate_larena_storage_version_table_shapes.php` is a
read-only declared-upgrade check. It requires the complete compatible topology
and never repairs or rewrites a table.

## Rollback

The creation migration's `down()` has five outcomes:

- no owned tables: no-op;
- partial topology: reject without DDL;
- incompatible topology: reject without DDL;
- complete compatible topology with any row: reject without DDL;
- complete compatible empty topology: drop all four tables in dependency-safe
  order.

The validation migration's `down()` is read-only and does not alter tables.

## Stable Diagnostics

`StorageOwnedTableShapeRejected` exposes `reasonCode` and a safe logical
`tableKey`. Its exception message is exactly the reason code. It never includes
SQL, a connection name or a database/schema name.

Current reason codes:

- `storage_owned_table_columns_incompatible`;
- `storage_owned_table_column_contract_incompatible`;
- `storage_owned_table_primary_index_incompatible`;
- `storage_owned_table_unique_index_incompatible`;
- `storage_owned_table_secondary_index_incompatible`;
- `storage_owned_table_topology_incompatible`;
- `storage_owned_table_partial_topology_contains_data`;
- `storage_owned_table_introspection_failed`;
- `storage_owned_table_driver_unsupported`;
- `storage_typed_content_rollback_would_lose_data`.

These diagnostics are evidence for an operator recovery plan, not permission
for automatic repair.
