# Batch 4 wave C — Localized values

Repository: `simai/larena-storage`
Branch: `feature/minimal-cms-v1-1-batch-4-storage-localized-values` from wave B at `c260c8d`
Launch record: `docs/project-management/launch-records/minimal-cms-v1-1-batch-4-wave-c-storage-localized-values.json`

## What exists now

A localized value is a row in `larena_storage_localized_values`, keyed by schema,
record, revision, locale and field. The layout was the whole decision, and the
freeze records why the two alternatives are wrong: a locale column on
`larena_storage_record_versions` would need one row per locale for one revision,
breaking the unique key on `(schema_id, record_id, revision)`; a locale dimension
inside `values_json` could be neither indexed nor counted without decoding every
document.

Two invariants carry everything else.

**A row belongs to its revision and is written once.** The unique index is the
guarantee, so a published revision cannot have its translation changed under it —
proven by a direct insert that bypasses the class and is still refused. A change is
a new revision.

**An absent value is absent, never an empty one.** A caller has to be able to tell
"this page has no German title" from "this page's German title is an empty string",
so `resolve()` omits a field it cannot find rather than returning null, and an empty
string or a null that was actually written comes back as present.

## The fallback chain

Storage does not decide the chain — Lang owns that policy, and `LocaleFallbackChain`
is how it is handed in. Every resolved value says whether it is `exact` or
`fallback` and names the locale it came from, because a reader that cannot tell the
two apart cannot tell a translated page from an untranslated one.

The chain is resolved in **one** query for the whole locale set. Walking locale by
locale would cost one round trip per fallback step: a page with ten fields and a
three-locale chain would pay thirty.

## Coverage

`coverage()` reports, per declared locale, which localized fields are present and
which are missing, and whether the record is complete. It is a count rather than a
parse — which is exactly what the row layout bought. A locale the site does not
declare is not reported even when rows exist for it: the declared set is the
question being asked.

A required localized field missing in a locale is refused unless the schema allows
partial locales. A half-translated record that can be published is worse than a
refusal, because nothing downstream can tell.

## The workbench change, and the one it could not make

`DatabaseStorageWorkbench` asserted an exact field key set of eight keys. It now
accepts an optional ninth, `localized`, and a field that says nothing is not
localized — which is what the entire installed base means today. A non-boolean value
is refused and any other key is still refused, so the set was widened by one entry
rather than opened up.

The flag deliberately does **not** reach the storage schema. The first attempt put it
there, and the workbench integration test failed immediately with
`storage_schema_field_unknown_key`: the schema normalizer has a closed key set of its
own, and widening it would mean migrating every stored definition. Nothing needs it
there either — the localized value writer is told which fields are localized by its
caller. That is recorded as a deviation and a follow-up rather than forced through.

## Gates

`composer quality:gate` passes, including the workbench integration test that proves
the eight-key shape still works.
