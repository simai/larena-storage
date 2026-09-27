# Checks

- StorageWorkbenchLifecycleTest passed on disposable file-backed SQLite: hidden flag add and refusal of a non-boolean value; purge refuses an active record and a stale revision, removes versions and head and audits without values; archived structure refuses record create and update and structure edits, stays readable, is listed only on request; restore; foreign scope denied; purge refused while an outside record references a record and while a role is bound; stale version conflict; purge removes records, internal edges, versions and head, audits once per record plus once per structure, leaves another structure untouched; the id cannot be reused.
- Composer quality:gate passed: preflight, metadata, lint, static analysis, package tests, evidence and scope checks.

No larena.test or working database was used.
