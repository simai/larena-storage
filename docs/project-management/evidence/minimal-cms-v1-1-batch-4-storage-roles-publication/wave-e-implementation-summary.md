# Batch 4 wave E — Read contracts

Repository: `simai/larena-storage`
Branch: `feature/minimal-cms-v1-1-batch-4-storage-read-contracts` from wave D at `4640a1e`
Launch record: `docs/project-management/launch-records/minimal-cms-v1-1-batch-4-wave-e-storage-read-contracts.json`

## What exists now

Two read models, both derived and both reading only the published head.

`resolveKey()` answers at most one published record for a key in a scope and a locale.
Two candidates **fail closed** with `ambiguous_key` rather than returning the first: a
site that served whichever row came back first would be broken in a way nobody could
reproduce. A miss returns null, and so does a record the caller may not read — the
absence of a page must not confirm its existence.

`publishedProjection()` carries only the published head, only fields whose visibility
is `public`, and only records the caller may read. A filtered record is a count and
never an identifier.

`SlugUniquenessGuard` keeps a key field unique per **structure, scope and locale** — not
per table. Two sites may both have a page at `/about`, and the same site may have a
different `about` per locale; what must not happen is two published records answering to
the same key in the same scope and locale, because then `resolveKey` has no single
answer. It is a guard rather than an index because the key field's name is declared per
schema: there is no column to index. It asks the published projection, so "published"
has exactly one definition in this package.

A role reference resolves through its active binding, so a caller can pass either
`site_node@v1` or `site.pages` without knowing which it holds.

## The acceptance proof

`RoleBoundSiteTreeTwoLocalesTest` proves `role_bound_site_tree_two_locales` end to end:
the `site_node` role bound to a structure that conforms, three records with one revision
each, one tree (`home/docs/guide` as a materialized path), Russian titles for the same
records and revisions, published in both locales — and then two renders.

Both renders carry the same record ids and the same revisions. Only the localized field
differs. Slugs and structure are shared because they are not localized, admin fields
stay out of both, the tree is navigable from the same edges in both locales (a tree is
structure, and structure is not translated), and an untranslated node still renders with
its shared value.

## Two defects the smoke found that the tests did not

**An empty public-field list leaked every translation.** `publicValues()` passed the
public field list to the localized reader, and that reader treats an empty list as
"every field". So a schema whose visibility nobody had declared projected no shared
values — correctly — and every localized value — wrongly. The projection now returns
early on an empty list, and a test writes a localized value for an undeclared schema and
asserts it stays out in every locale.

**The test fixture was narrower than the migration.** `larena_storage_schema_versions`
has a NOT NULL `definition_hash`, and the fixture omitted it. Every insert in the SQLite
smoke was silently dropped, which is how the leak above became visible at all. The
fixture now mirrors the migration column for column, including `correlation_id` and both
indexes. A fixture that omits a NOT NULL column is worse than no fixture.

Both were found by running the same scenario on the second driver. Neither would have
been found by more unit tests against the same fixture.

## Gates

`composer quality:gate` passes, PHPStan level 5, five new test files.
