# Concept alignment: Larena Storage

## Accepted role

Storage is the sole owner of dynamic structures, fields as applied schema, records, revisions, relations, lifecycle and mutation receipts. Every mutation is validated against the exact structure version.

Accepted Target State: `larena.target.minimal_cms_v1` at semantic digest `sha256:2793f61ba9563839831d57e87ac5cd6399c37a3183f1a68981b6fc7f941a1ad2`.

## Dependency and ownership boundary

- Mandatory Larena dependencies: Access, Core and Property.
- Audit and Content compatibility cannot be mandatory.
- Filesystem owns blobs; Storage persists only opaque logical-file references.
- Dataview presents query results and never owns or duplicates records.

## Continuation strategy

Existing typed schema, record, query and persistence contracts are continued. B2 removes Audit from the closure and adds Core; B7-B9 fill missing lifecycle, concurrency, receipt, hierarchy and logical-file behavior through the same data model.

## B7 alignment status

Access, Core and Property remain the exact mandatory Larena Composer dependencies. The mandatory provider now uses a Storage-owned security-event sink; Audit is available only through an optional compatibility adapter. Typed structures and records provide create/read/update/list/delete/restore, immutable revisions, exact-version validation, typed relation values, optimistic concurrency and sanitized mutation receipts. Explicit hierarchy fixtures and Filesystem-owned logical identity remain B8 and B9 work.

## Install and rollback baseline

Install through the Root Composer lock and run Storage-owned migrations through Laravel. B0 is documentation-only. Later migrations require clean install plus down/reapply proof on disposable databases; rollback must not discard structures, records or revisions on real data.

## Verification

Run package tests, `composer validate`, dependency reporting, two unrelated structure fixtures, revision/concurrency tests and SQLite/MariaDB parity suites.
