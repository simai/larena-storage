# Migration and rollback

The migration is additive and creates only the four typed-content Storage
tables. `up()` checks each table independently, so re-entry after a partial
local setup does not recreate existing tables.

`down()` first inspects every typed-content table. If any schema, record or
version row exists, it throws
`storage_typed_content_rollback_would_lose_data` before dropping any table.
The integration test confirms that all four tables remain after refusal.

For an unused clean database, `down()` removes the four tables and a following
`up()` recreates them. This is verified only on generated disposable test
databases. Existing application databases are outside the permitted boundary.
