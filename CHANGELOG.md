# Changelog

## Unreleased

- Workbench fields take two витрина options, kept in the structure (not in the storage schema), so changing them rewrites no record version and no schema version: `searchable` (bool; string and text fields; a field that says nothing is searchable, as every string and text value was searched before; `searchable: false` leaves a field out) and `indexed` (bool; string fields with `max_length` ≤ 255: the column becomes `VARCHAR(max_length)` with a `(state, cN, record_id)` index, used by sorts, equality and prefix filters; choice, record, user, date, datetime, number, integer and boolean fields stay indexed automatically). A table holds at most 40 field indexes, the automatic ones first. Refusals: `storage_field_option_invalid` (not a boolean), `storage_field_searchable_type_unsupported`, `storage_field_indexed_type_unsupported`, `storage_field_indexed_max_length_invalid`, `storage_field_index_limit_exceeded` (`StorageViewFieldOptions`).
- Changing the options changes the витрина online: a string that becomes indexed gets its new column and index before the transaction (MySQL `ALGORITHM=INSTANT`, the index `INPLACE, LOCK=NONE`) and is written from then on while its old column keeps answering lists; a string no longer indexed keeps its VARCHAR column and loses only the index (dropped after the commit); a changed set of search fields is refilled. The rows written before are filled by the queued `storage.view.rebuild` job, which the workbench requests after the commit (`DatabaseStorageWorkbench` takes an optional `$viewBuilds` closure; the provider enqueues the job when the queue is bound). That rebuild now backfills a ready витрина in place, resumable in chunks, instead of rebuilding it: lists keep reading it (a search goes to the PHP list until the search text is complete), and at the end the new columns become ready and the replaced ones are dropped. Without a queue, `larena:storage:view:rebuild <schema>` (or `--missing`, which now includes витрины with a backfill pending) finishes it; `larena:storage:view:check` reports `backfill_pending`. A field added together with its options by a schema apply is filled by that apply.
- The search text moves to a side table per витрина, `<table>_s` (`record_id`, `search_text`), written in the record's transaction; on MySQL it carries a FULLTEXT index (InnoDB's default parser, built with the stopword list off, so every word is searchable; the ngram parser was not chosen: its index is several times larger and every term would read the posting lists of common letter pairs). It is a table of its own because InnoDB adds no column in place to a table with a FULLTEXT index; the main table keeps its INSTANT column changes. The main table's `search_text` column is no longer written for layouts with search fields. Rolling back the registry migration also drops `_s` tables.
- Search semantics change, the same on MySQL, SQLite and the PHP list: a record matches when every whitespace-separated term of the query occurs at the start of a word of its searchable fields (words are runs of letters, marks, digits and underscores, lowercased; punctuation separates words, so `sku-0001` finds "SKU-0001"; terms match in any order and in any searchable field, never across two fields). Before, the whole query was one substring of any string or text field. So `chair` still finds "Chair" and "chairs" but no longer "armchair", `oak chair` now finds "Chair, oak"; every string and text field is still searched by default (`searchable: false` leaves one out). A query of punctuation alone finds nothing.
- On MySQL a search is answered one of two ways that give the same rows: the first rows in list order are checked one by one (`EXISTS` on the search table with a binary `LIKE '% term%'`), which answers a common term after one page; when a window of 1000 rows does not hold the page, the FULLTEXT index finds the candidates (`MATCH … AGAINST ('+word*' IN BOOLEAN MODE)` with the query's longest word of at least `innodb_ft_min_token_size` characters, the LIKE checking them) and counts them in the same query (`COUNT(*) OVER ()`). A query without a word that long (one or two letters) is checked row by row. An estimated count of a search scales the list's estimate by the share of the first rows that match, and an estimate is never below the rows the list has shown.
- `larena:storage:view:rebuild --search` refills only the search text into an emptied search table (on MySQL a fresh FULLTEXT index: InnoDB keeps the ids of changed rows until the index is optimized, and every search reads them); the витрина stays ready.
- A prefix filter (`starts_with`) on an indexed string also reads a range of its index on MySQL.
- `larena:storage:bench` switches the options on the filled storage (title and sku indexed, subtitle and notes out of the search) and times it (`options_backfill_ms`), and also measures `sort_title_desc_deep`, `sort_subtitle_unindexed`, `filter_title_prefix`, `filter_sku_eq`, `search_prefix`, `search_two_words`, `search_common_word` and `search_short_term`.

- Site read contracts are batched: `publishedProjection`, `publishedRecord` and the key scan read versions, localized values (`LocalizedValuesBatch::resolveMany`, implemented by `DatabaseLocalizedValues`) and projection versions with one `whereIn` per chunk of 500 records, so a page costs a constant number of queries (10 on SQLite for 5 or 300 records, before 6 per record plus 4). Shapes, value sources, projection versions and every fail-closed rule are unchanged. `resolveKey`, `publishedProjection` and `publishedRecord` are timed as `storage.read`.
- Published keys (slugs) are indexed: `larena_storage_published_keys` (one row per published head: schema, scope, locale, key field, sha256 and value of the key, record, revision; unique on schema, scope, locale, key field and key hash, and on schema, record, scope, locale and key field) and `larena_storage_published_key_sets` (per schema, scope, locale and key field: the locale chain it was built along, `building`/`ready`/`conflicted`, the build cursor and conflicts), one platform migration. `PublishedKeyIndex` keeps them inside every publication transition (`PublicationIndex`, a new optional argument of `DatabasePublicationLifecycle`; a key another publish took in the meantime is refused as `key_conflict`) and on every translation of a published revision (`LocalizedValueObserver`, a new optional argument of `DatabaseLocalizedValues`); purge removes the rows. The key stored is the one a read shows in that locale along Lang's fallback chain; only public key fields answer. A key lookup (`resolveKey`, `keyHolderIds`, the slug and publish guards) reads the table when its set is ready for the current chain, after checking the holder is still published with that key, and scans in batches otherwise. A new set is built inside its first publish when the scope and locale hold at most 500 other published records.
- `larena:storage:published-keys:rebuild {--schema=} {--scope=} {--locale=} {--chunk=500} {--fresh}` builds the published-key sets in chunks, resumes a stopped build, skips ready sets built along the current locale chain, and rebuilds sets whose chain changed (a locale fallback change needs it) or that met two holders of one key (reported; lookups scan and fail closed as `ambiguous_key` until resolved).

- The витрина rebuild runs as the durable queue job `storage.view.rebuild` (larena/storage now requires larena/queue), registered with the queue's `JobTypeRegistry` by the provider. Payload `{"schema_id": "<storage schema id>", "chunk": 500}`; maintenance priority, 1800 s per attempt, up to 50 attempts 15 s apart, a 300 s lease. It goes through the same `StorageViewRebuilder`: the position is saved with every chunk, between chunks the job heartbeats its lease and checks the queue checkpoint (cancel, timeout), so a stopped, timed out or retried job resumes where it was; a retry that finds the build finished does nothing; a newer build that takes the storage over ends it as `taken_over`. The витрина is marked ready only at the end, in the transaction of the last (short) chunk. The idempotency key follows the storage's registry row and the minute of the request, so a deploy step run twice gives one job. `StorageViewRebuilder::rebuild()` takes a checkpoint closure that runs between chunks.
- `larena:storage:view:rebuild --queue` enqueues one job per storage (with a schema, `--all` or `--missing`) instead of building inline.
- `larena:storage:view:check {schema?} {--repair} {--queue} {--chunk=500}` compares each витрина with the current versions (`StorageViewChecker`: registered and ready for the current schema version, the table's shape, rows missing, extra or at another revision or hash, counted in SQL by joins on the record id) and with `--repair` rebuilds what differs, inline or as a queue job; a build under way is resumed. A витрина whose storage is gone is reported, never dropped. Exit code 1 while anything is left unhealthy.
- Hot-path metrics for larena/monitoring, declared in `monitoring.yaml` and registered in its `MetricCatalog` only when monitoring is installed (`StorageMetrics`, resolved by name, a no-op otherwise; recording never throws into storage): `storage.list` (duration; `mode` sql|legacy, `count` exact|approx|skip, `outcome`), `storage.read` (duration; `outcome`), `storage.write` (duration; `operation` create|update|archive|restore|purge|revert, `outcome`), `storage.view.rebuild` (duration per committed chunk) and `storage.view.rebuild_rows` (counter). Outcomes are ok, rejected (a refused, conflicting or denied operation) or error. Tags are codes only, never ids, titles or values. Reads and writes are measured in `VersionedStorage`, lists in the workbench.
- Record lists read the витрина in SQL once a storage's витрина is `ready` and built for its current schema version (otherwise the PHP list stays): `StorageViewQueryCompiler` turns the normalized query into WHERE clauses on typed columns (eq/in, gt/gte/lt/lte/between, contains/starts_with as a lowercased LIKE with escaped wildcards, choices through EXISTS on the side table, search over `search_text`, `scope_ref = ?`, archived left out unless asked for) and sorts by up to three keys and then `record_id` in the direction of the last key (the PHP list orders ties the same way now), NULL first ascending, so one `(state, column, record_id)` index serves a sort both ways. There is no scope condition in SQL: a витрина holds one scope's records (its table is named from the scoped schema id) and each listed record is still checked against the scope. Tables gain a `(state, record_id)` index for the default order (created with new tables, added in place by `ensureTable` to existing MySQL ones); a rebuild on MySQL ends with `ANALYZE TABLE`. On MySQL string equality stays exact (binary) and strings sort by the column collation. A page reads ids from the витрина (limit + 1) and the records from their current versions in one query, without recomputing hashes; single reads still verify them. Lists above 5000 records now work.
- Continuations of SQL lists are keyset ones (`version: 2` in the signed payload: the last row's sort keys, record id and offset); the earlier offset continuation is still read for one release. Page numbers work while the offset stays at or under 10 000.
- `StorageWorkbenchRecordQuery::$countMode` (`StorageWorkbenchCountMode::Auto|Exact|Skip`, default Auto) and `StorageWorkbenchRecordPage::$countExact` (default true), both additive. Auto counts exactly up to 10 000 matched rows under a 250 ms budget (`MAX_EXECUTION_TIME` on MySQL, `max_statement_time` on MariaDB) and otherwise reports the optimizer's estimate with `countExact = false`; SQLite counts exactly. Exact always counts; Skip does not (the count is what the list has seen). A host may bind its own `StorageViewCounter`.
- Incoming `record@1` references (purge checks) are read from the referencing storage's витрина when it is ready.
- `larena:storage:bench` also measures `matched_count_exact`.
- Each storage gets a витрина, a typed read table kept in the write transaction (`Larena\Storage\View`). Tables are `larena_storage_view_<24 hex of sha256(schema id)>` plus `_m` for multi-values; fields map to typed columns `c1, c2, …` (never reused) by their Property type, with `(state, cN, record_id)` indexes for choice, record, user, date, datetime, number, integer and boolean fields (at most 40) and the system columns record id, revision, schema version, content hash, times, scope, state, title and search text. The registry `larena_storage_views` (platform migration) holds each layout and its state (`pending`, `building`, `ready`, `failed`). Creating a structure creates its table before the transaction, a field change adds its columns before and drops the old ones after the commit (MySQL: `ALGORITHM=INSTANT`, else `INPLACE, LOCK=NONE`); inside an outer MySQL transaction the витрина becomes pending and the DDL waits for the commit. Create, update, archive, restore, purge and schema apply write it in their transaction; a number outside DECIMAL(65,30) is refused (`storage_view_number_out_of_range`). Lists read it once it is ready (below).
- `larena:storage:view:rebuild {schema?} {--all} {--missing} {--chunk=1000}` builds the витрина of existing storages in chunks and resumes a stopped build. Opt-in MySQL check: `composer test:mysql-view`.
- Record writes take the schema row with a shared lock instead of for update, and record updates, archives and restores take the workbench structure with a shared lock, so writers to one storage no longer wait for each other; a schema apply and a structure change still wait for them. A record update hashes its values once.
- `larena:storage:bench` measures the engine on a disposable database (a new SQLite file or a named non-default connection; the default one is refused): a 20-field workbench structure seeded with engine-identical rows, then list pages, filters, sorts, search, the matched count and single-record read, update and create, each with p50/p95/max ms, peak memory and SQL query count, as JSON. A refused operation is recorded as `fails` with its reason. `scripts/storage-bench.php` runs it outside the application on a temp SQLite file or a throwaway local MySQL database.
- An existing workbench structure may gain an optional `record@1` field: its `target_structure_id` is the one constraint an added field may carry, in the workbench and in the optional-field compatibility analysis.
- Workbench records take `record@1` fields: a write that sets or changes one must name an active record of the field's target structure in the same scope (`storage_record_reference_target_unavailable`), an unchanged value survives its target's archiving, filters take `eq`/`in`, and a record or structure still referenced through `record@1` is not purged (`storage_record_purge_referenced`).
- Each starter role ships a conforming fixture (`StarterStructureRoles::fixtures()`), doc_space included.
- A refused bind carries the conformance report in `StructureRoleRejected::$diagnostic`.
- Role versions migrate by plan: `planRoleMigration()` names the breaking changes and the bindings still on the older version; `migrateBinding()` checks a structure against the newer version and moves its binding in one transaction, or refuses with `role_migration_blocked` and the diagnostic.
- `StructureRoleDependencies`: a consumer declares the roles it reads and receives bound structures only for those; `unsatisfied()` reports declared roles a scope does not satisfy.
- Role registration, binding and migration are audited (`storage.role.registered`, `.bound`, `.migrated`); a guard test keeps role codes out of Storage outside the starter role set and the starter site.

- Relations are checked before they are written through `RelationTargets` (`DatabaseRelationTargets` in the provider): the source must be in the schema, the target must exist and be of the declared structure (`target_schema_id`) or role, a tree parent shares the schema and the scope (`larena_scope_ref`), and a schema version that declares `relations` (with their delete policy) is followed exactly. New codes: `source_not_found`, `source_schema_mismatch`, `target_not_found`, `target_schema_mismatch`, `target_role_mismatch`, `cross_scope_parent`, `relation_undeclared`, `relation_descriptor_mismatch`.
- A schema definition may declare `relations`, kept only when present.
- `storage.relation.delete_record` applies the declared delete policy; purging records applies it to edges reaching in from outside (`releaseIncomingEdges`): restrict refuses, cascade removes the edge and its subtree's edges, detach turns a tree child into a root with its subtree.
- Detaching rewrites the whole subtree's paths; a detached root is no longer treated as its own child.
- The tree operations apply the caller's `RecordReadVisibility` (default `AllRecordsVisible`); define, move and delete are audited (`storage.relation.defined`, one `storage.tree.moved` per move with the moved set, `storage.relation.deleted`).

- A storage schema field may declare `localized: true` and a schema `partial_locales: true`; both are kept only when true, so stored definitions hash as before. The workbench and the starter site write the flag; toggling it on an existing workbench field is an accepted change.
- `LocalizedValues::write()` takes localized fields, required fields and partial-locale permission from the schema version of the revision; the caller's lists apply only to versions that declare no localized field. A value for the primary locale (the application locale) may be partial. Each write emits `storage.locale.written` with field keys in its transaction.
- Projection entries and `ResolvedKey` carry `value_sources` per field: `exact`, `fallback:<locale>` or `shared`.
- `storage.locale.fallback_resolve` takes one `locale` and Lang's chain from `LocaleFallbackResolver` instead of a caller-supplied list.
- `AuditStorageSecurityEventSink` takes severity and retention from the event descriptor.

- Keep a routed key unique on publish: the application declares a structure's key field through `PublishedKeyPolicy`, and `PublishedKeyUniqueness` refuses a publish or a scheduled publication whose key another published record of the same structure, scope and locale holds (`key_conflict`); the sweep reports refused schedules and keeps them scheduled.
- Key lookups read every published record page by page instead of stopping silently at the first page; past 50,000 records they refuse with `key_scan_limit_exceeded`.

- Audit every publication transition from `DatabasePublicationLifecycle` itself, in the transition's transaction, on every path (operation, sweep, direct call): `storage.publication.*` with actor, scope, locale, revisions and a correlation id; a call without one gets a fresh id shared by one sweep. A transition whose audit fails is rolled back.
- Opt-in MySQL restart readback for publication (`composer test:mysql-publication`).

- Translations pass Property validation against the schema version of their record revision (`unknown_revision`, `field_unknown`, `value_invalid`).
- Read operations apply the caller's `PublishedReadVisibility` filter; `projectionExplain()` takes the filter and reports hidden records as `filtered_record_count` only.

- Serve the published projection as a Search source: keyset cursor, `publishedRecord()`, a monotonic `projection_version` per entry and a `PublicationObserver` port.

- Public reads take their locale chain from the `LocaleFallbackResolver` port, bound by the application to Lang's fallback policy; `storage:publication:sweep` publishes due schedules and is scheduled every minute.

- Declared schema transforms: remove fields, change their type or make them required, rewriting every record in one transaction with fixed conversion rules; the workbench exposes it as `migrateStructure()`.
- Archive and restore workbench structures; delete archived structures and archived records permanently with relation and role-binding refusals and identifier-only audit; hide a field without touching its values.
- Expose the workbench's executable filter operators by field type through its public contract; verify a complete typed structure and Property display projection across a disposable SQLite restart.
- Archive up to 1000 workbench records in one bulk transaction (previous limit: 100).
- Accept a declared `options` list (objects with scalar values) as the only non-scalar field constraint, for `choice` and `choices` fields; Property validates the list and schema digests stay canonical.
- Store `choices` record values as JSON arrays in declared option order; a required `choices` field rejects an empty list with `storage_record_required_field_missing`.
- Add workbench record filter operators `in`, `contains`, `starts_with`, `gt`, `gte`, `lt`, `lte` and `between` next to `eq`, with a per-type operator matrix and `storage_query_filter_operator_unsupported` for unsupported combinations.
- Raise the workbench record scan limit from 500 to 5000 current records and iterate scan rows with a cursor.

- Support bounded numbered record pages alongside the existing signed continuation API; reject mixed page/cursor inputs and preserve scope and scan limits.

- Accept MariaDB's JSON alias in both owned-table shape guards only when the exact `LONGTEXT`/`TEXT` column has an exact `JSON_VALID(column)` CHECK constraint; keep native JSON mandatory for MySQL and fail closed when constraint metadata is missing or ambiguous.
- Use the existing MySQL DDL compensation and rollback path for Laravel's `mariadb` driver so implicit DDL commits cannot leave Laravel attempting to commit a closed transaction.

## Unreleased — Minimal CMS v1 B8

### Added

- Add named site/page and organization hierarchy fixtures using the same generic Storage structure, record and relation contracts.
- Add round-trip evidence and a negative database-table assertion proving that the fixtures do not introduce a parallel Content owner.

### Migration notes

- No database migration or product runtime change is added. Rollback removes only B8 fixtures, tests and metadata.

## Unreleased — Minimal CMS v1 B7

### Added

- Add canonical record delete/restore transitions with immutable revisions and optimistic-concurrency checks.
- Add complete sanitized mutation receipts for structure and record writes.
- Add a Storage-owned security-event sink plus an optional Audit compatibility adapter.
- Prove two unrelated typed structures, relation values, atomic bulk mutation and fresh-process SQLite replay through the workbench integration suite.

### Changed

- Remove the mandatory Audit service requirement from the discovered Storage provider while preserving existing Audit-backed integrations as an optional development compatibility path.

### Migration notes

- No database migration is added. Rollback is the package commit revert; existing immutable structures, records and revisions remain untouched.

All notable changes to `larena/storage` are documented in this file.

## Unreleased

### Changed

- Set Access, Core and Property as Storage's exact mandatory Larena Composer dependencies; Audit remains a development-only compatibility fixture.

### Added

- Add a database-native `VersionedStorage::listCurrentRecords()` contract for
  deterministic, bounded current-head pages across arbitrary Storage schemas.
- Resolve canonical Access query scope before Storage data access; bind opaque
  HMAC continuations to schema, normalized filters and resolved scope; reject
  missing/denied/invalid scope, cursor tampering and cross-query/scope reuse.
- Add exact Property-typed equality filters and schema-owned public projection,
  rejecting unknown fields/operators and protected/admin filter inference.
- Add disposable SQLite integration evidence for two unrelated schemas, CAS
  current-head uniqueness, exact history, negative controls, key-order
  metamorphism, zero mutation and cross-process/cross-path durability.

- Add additive database tables and contracts for immutable schema and record
  versions.
- Add exact-version admin reads, an actor-checked current-version resolver and
  public-field-only exact projections.
- Add compare-and-swap writes, Access operation registration and transactional
  Security Audit events for schema creation/versioning and record
  creation/update.
- Add disposable SQLite verification for persistence, stale-writer rejection,
  Audit-failure atomicity and safe migration rollback/reapply.
- Convert caller-provided correlation inputs to opaque package-scoped hashes
  before they reach database or Security Audit metadata.
- Add a driver-normalized owned-table shape guard for exact columns, portable
  type/nullability/auto-increment contracts, primary-key composition and
  explicit unique/secondary index names and compositions.
- Add a read-only upgrade validation migration and file-backed SQLite
  adversarial coverage for foreign/partial tables, missing and wrongly composed
  indexes, idempotent completion and rollback/reapply.
- Add the public `StorageSchemaEvolution` contract with immutable compatibility,
  plan and result DTOs that expose only safe refs, counts and hashes.
- Add an optional-field-only compatibility analyzer, content-addressed plans,
  atomic schema/record migration results and four guarded migration tables.
- Add sanitized Access operations and Security Audit events for analyze, plan,
  explain, dispatch, apply and rejection paths.
- Add adversarial file-backed SQLite coverage for incompatible definitions,
  unknown keys, tampered plan/source/record state, stale heads, Access denial,
  Audit failure rollback and a two-process one-winner apply race.
- Add a sealed container-local owner policy registry with opaque
  transaction-scoped proof and consumer-validated one-shot orchestration
  capabilities for protected schema namespaces.
- Add provider-order and protected-owner adversarial coverage for direct,
  forged, cloned, expired, mismatched and replayed plan/apply attempts.
- Add opt-in isolated real-MySQL acceptance for all four migration-table shape
  contracts, clean install/down/reapply, restart, concurrent one-winner apply,
  strict value preservation, used rollback refusal and cleanup to zero.
- Validate Property constraints before persisting an immutable schema version,
  rejecting unsupported keys, wrong types and contradictory ranges atomically.
- Preserve exact reads and visibility projection for previously stored schemas
  with legacy scalar-but-invalid constraints, while blocking all new writes
  against them without mutation or a success Audit event.
- Require the additive Property constraint-validation capability for new schema
  registration without changing the original Property registry interface.
- Admit `protected` as a distinct immutable schema visibility alongside
  `public` and `admin`, preserving it through canonical round-trip.
- Add a focused Content compatibility proof covering versioned, in-memory and
  disposable-PDO exact-public projections.

### Changed

- Correct `storage.record.list` scope resolution to use canonical resource type
  `storage.record`, matching its `storage.record:all` operation descriptor and
  the portfolio `PersistentGlobalRoleQueryScopeProvider`.
- Separate caller filters from provider-owned scope filters: callers remain
  public-only, while an explicitly selected provider may add typed public or
  protected fields without changing the caller query; admin/unknown/operator
  drift remains fail closed and protected scope values never enter projection.
- Add package-local real-provider and container-binding coverage without adding
  a default `QueryScopeProvider` binding or claiming Root integration.

- Keep Docara historical revision restoration outside the versioned Storage
  mutation API: the consumer reuses an exact immutable version reference
  instead of asking Storage to create a new restore version.
- Make the versioned `compareAndSwap()` path unconditionally emit an `update`
  record version and `storage.record.updated` Audit event.
- Run the full owned-table preflight before the first DDL statement and verify
  the completed topology again after creating missing tables.
- Block direct schema version 2+ registration and require a verified migration
  plan for evolution.
- Require create/CAS to lock and match the current exact schema head; harden
  immutable reads against transplanted definitions, owner mismatches and
  content-hash corruption.
- Classify `protected`, `admin`, `hidden` and `encrypted` as protected and make
  legacy in-memory/PDO public projections omit every field that is not exactly
  `public`, including unknown, missing and invalid visibility values.

### Migration notes

- S-STORAGE-01 adds no migration or table change. Rollback is the package commit
  revert; previously persisted immutable records are untouched.

- The original migration is additive and creates four version tables. The
  schema-evolution migration adds four immutable plan/result tables.
- A correct empty subset of those tables may be completed during install.
- Install and upgrade refuse foreign columns, incompatible portable column
  metadata, missing or wrongly composed indexes, and data-bearing partial
  topologies before creating a missing table.
- `down()` is a no-op only when all four tables are absent. It refuses partial
  or incompatible shapes and data before any drop, and drops only the full,
  compatible, empty owned topology.
- Clean unused databases support deterministic down/reapply; the second
  migration performs read-only upgrade validation.
- The evolution migration performs its own full SQLite/MySQL shape preflight.
  It refuses foreign/damaged/used partial shapes and refuses `down()` when any
  plan/result data exists.
- The protected-visibility compatibility change has no migration and is
  reverted by reverting its bounded package commit.

This unreleased slice does not claim frontend or production readiness, Content
runtime readiness, or readiness of all Larena packages.
