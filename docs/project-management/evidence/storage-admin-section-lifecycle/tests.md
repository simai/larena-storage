# Checks

- StorageWorkbenchLifecycleTest passed on disposable file-backed SQLite: hidden flag add and refusal of a non-boolean value; purge refuses an active record and a stale revision, removes versions and head and audits without values; archived structure refuses record create and update and structure edits, stays readable, is listed only on request; restore; foreign scope denied; purge refused while an outside record references a record and while a role is bound; stale version conflict; purge removes records, internal edges, versions and head, audits once per record plus once per structure, leaves another structure untouched; the id cannot be reused.
- Composer quality:gate passed: preflight, metadata, lint, static analysis, package tests, evidence and scope checks.

No larena.test or working database was used.
- `RoleBoundSiteTreeTwoLocalesTest`: a read without a policy shows the shared value; with Lang's chain `kk → ru → en` the untranslated Kazakh render shows the Russian titles.
- `PublicationSweepCommandTest`: the command publishes only due schedules under `system:scheduler`, is idempotent, refuses a non-positive limit, and the provider schedules it every minute without overlap.
- `PublishedProjectionSearchSourceTest`: keyset cursor, one record by id, the observer's version equals the read's, the version grows on a translation, a newer revision, an unpublish and a republish of the same revision, and a failing observer does not block publication.
- `LocalizedValuesFailsClosedTest`: a translation for an unknown revision, an undeclared field and a value of the wrong type is refused and nothing is written; `LocalizedValuesTest` and `ReadContractsFailsClosedTest` now expect `value_invalid` and `field_unknown` for untyped writes.
- `ReadOperationVisibilityTest`: with a restricting visibility a visitor's projection omits the hidden record and counts it, its key does not resolve, explain counts it without naming it; another actor reads both; the default reads every published record.
