# Implementation summary

S-STORAGE-01 adds one database-native list boundary to the existing immutable `VersionedStorage` runtime. It resolves `QueryScopeProvider` before any Storage schema/record lookup, validates the exact Access decision and scoped query, then joins `larena_storage_records` to its exact current row in `larena_storage_record_versions`.

The corrected query calls Access with canonical resource type `storage.record`, matching the `storage.record:all` descriptor used by the real portfolio provider. The exact schema id remains inside the typed query and both continuation identities.

Caller filters accept only Property-normalized `eq` on current-schema `public` fields. A trusted provider may add typed `public` or `protected` fields but cannot remove/change caller filters, change schema, add `admin`/unknown fields or use another operator. All resolved filters run in SQL before projection. The result remains exactly public-only.

Results contain exact current version refs plus schema-owned public values. They omit owner refs, protected/admin values, write metadata and correlations. Reads emit no raw-value diagnostic or Audit event. No Content/Docara logic, in-memory adapter, migration, dependency change, accepted pin, runtime or user data is involved.

The Storage provider injects only an explicitly selected `QueryScopeProvider`; absence remains fail closed. Package-local tests cover the real `PersistentGlobalRoleQueryScopeProvider` and container selection without claiming Root integration.

Claim: `TESTED`, correction applied and pending independent auditor re-audit. No adoption decision is claimed.
