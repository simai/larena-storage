# Batch 4 wave E — smoke

## Headless two-locale read, on both drivers

A schema with two public fields and one admin field, two records, Russian titles for
both, published in both locales. Read through the contracts alone — no route, no
controller, no Layout, no Admin:

| Driver | English | Russian | Fields returned |
| --- | --- | --- | --- |
| MySQL 8.2 | `{"docs":"Docs","home":"Home"}` | `{"docs":"Документация","home":"Главная"}` | `["slug","title"]` |
| SQLite (file-backed) | same | same | same |

`resolveKey('slug','docs')` returned `{"slug":"docs","title":"Docs"}` in English and
`{"slug":"docs","title":"Документация"}` in Russian on both drivers. `internal_note`
appears nowhere.

## Restart readback

A separate SQLite process read both projections back identically and
`projectionExplain` reported `public_field_keys: ["slug","title"]` with two published
records.

## What the second driver caught

The first SQLite run returned `null` titles in English and `public_field_keys: []`,
while MySQL had been correct. Two real defects, described in the implementation
summary: the smoke's insert omitted the NOT NULL `definition_hash`, so every schema row
was silently dropped on SQLite — and that exposed a leak where an empty public-field
list made the projection return every *localized* value. Both are fixed; the fixture now
mirrors the migration column for column.

This is the clearest argument in the batch for running the same scenario on both
drivers rather than trusting one.

## Operation coverage

| Measure | After wave D | After wave E |
| --- | --- | --- |
| Registered operations | 46 | 49 |
| Covered access codes | 19 | 20 |
| Status | passed | passed |

## Root test suite

| Run | Tests | Passed | Failed |
| --- | --- | --- | --- |
| baseline, all repositories on `main` | 1257 | 958 | 208 |
| through wave D | 1267 | 968 | 208 |
| … plus wave E | 1267 | 968 | 208 |

## Not claimed

No push, release or deployment. No HTTP route: the headless read is proven through the
contracts, and the REST endpoints that would consume them belong to a later batch. No
independent review.
