# Batch 4 wave D — smoke

## Migration parity

| Driver | Result |
| --- | --- |
| MySQL 8.2 | `2026_09_29_000001_create_larena_storage_publication_tables … DONE` in 49ms |
| SQLite (file-backed) | same migration, 6ms |

## The composed revision check, observed first

The very first publish attempt in the composed application returned
`unknown_revision`, because no revision rows existed for that record yet. That was not
a planned step — and it is the clearest evidence that the injected check is real in the
runtime rather than only in a unit test.

## Two locales, one record, on both drivers

Three revisions were inserted for `site.pages/pub-smoke`, then:

1. English published at revision 2 → `state: published`, `published_revision: 2`.
2. Russian scheduled for revision 3 at a past timestamp → `state: scheduled`,
   `published_revision: null`, `is_published: false`.
3. Sweep → published Russian at revision 3, one row.

Read back in **separate processes**:

| Driver | English | Russian |
| --- | --- | --- |
| MySQL 8.2 | rev 2, published | rev 3, published |
| SQLite | rev 2, published | rev 3, published |

Russian history on both: `draft→scheduled (schedule, by actor:editor)` then
`scheduled→published (sweep_publish, by actor:scheduler)`.

The smoke wrote record-version rows directly, because the record mutation path is not
part of this batch. That is stated rather than hidden: the publication behaviour is
what is under test, and the revision check it depends on was exercised by the refusal
above.

## Operation coverage

| Measure | After wave C | After wave D |
| --- | --- | --- |
| Registered operations | 38 | 46 |
| Covered access codes | 14 | 19 |
| Status | passed | passed |

## Root test suite

| Run | Tests | Passed | Failed |
| --- | --- | --- | --- |
| baseline, all repositories on `main` | 1257 | 958 | 208 |
| through wave C | 1267 | 968 | 208 |
| … plus wave D | 1267 | 968 | 208 |

## Not claimed

No push, release or deployment. No queue worker is wired to the sweep — this batch
ships the operation a worker would call. Serving a published head to a public reader is
wave E.
