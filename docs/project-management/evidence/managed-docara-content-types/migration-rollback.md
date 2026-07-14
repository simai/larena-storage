# Migration And Rollback

M1 keeps the original four version tables under
`StorageOwnedTableShapeGuard`. M2 adds four immutable migration tables under a
separate `StorageSchemaMigrationTableShapeGuard`:

- `larena_storage_schema_migration_plans`;
- `larena_storage_schema_migration_plan_records`;
- `larena_storage_schema_migration_results`;
- `larena_storage_schema_migration_result_records`.

Both guards validate the supported driver and all existing owned shapes before
the first DDL statement. A compatible empty partial topology may be completed.
Foreign/damaged shapes and any data-bearing partial topology fail closed
without creating a missing table.

M2 `down()` first validates all four tables and their emptiness. Any plan or
result row causes `storage_schema_migration_rollback_would_lose_data` before a
drop. A complete compatible empty topology drops and reapplies reproducibly in
file-backed SQLite and an isolated real-MySQL schema. No foreign keys are
introduced, so ownership is proved by exact columns plus
primary/unique/secondary index contracts.

The real-MySQL matrix additionally proves exact varchar length, char versus
varchar hashes, unsigned bigint width, JSON type and auto-increment contracts.
An explicit partial `down()` leaves every surviving table metadata snapshot
unchanged. An unused complete topology down/reapplies; after a plan/result is
used, `down()` refuses without dropping or altering any table. Cleanup is
limited to the generated allowlisted schema and proves remaining schema count
zero.
