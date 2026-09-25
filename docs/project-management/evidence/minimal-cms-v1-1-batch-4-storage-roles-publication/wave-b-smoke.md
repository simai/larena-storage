# Batch 4 wave B — smoke

Root: `simai/larena`, consuming the package by Composer path symlink.

## Migration parity

| Driver | Result |
| --- | --- |
| MySQL 8.2 | `2026_09_27_000001_create_larena_storage_record_relations_table … DONE` in 62ms |
| SQLite (file-backed) | same migration, 6ms |

The `path` index is driver-specific on purpose. A 2048-character column cannot be
indexed whole on MySQL with utf8mb4, so the migration issues a `path(191)` prefix
index there and a plain index on SQLite. Writing it as one Blueprint index would
have produced a migration that passes on SQLite and fails on the production driver
— the same class of trap as the index-name length limit found in Batch 1.

## Tree round trip on both drivers

Built `company → sales → field → inside` with `marketing` as a second child, then
moved `field` under `marketing`:

| Driver | `inside` path after the move | Depth |
| --- | --- | --- |
| MySQL 8.2 | `company/marketing/field/inside` | 4 |
| SQLite | `company/marketing/field/inside` | 4 |

Identical. The moved record's relation id became `site_tree_parent|field|marketing`
on both.

## Restart readback

A separate SQLite process read the ancestors of `inside` back as
`marketing (company/marketing, depth 2)` then `field (company/marketing/field,
depth 3)` — the post-move state, in order, from the materialized path.

## Operation coverage

| Measure | After wave A | After wave B |
| --- | --- | --- |
| Registered operations | 27 | 33 |
| Covered access codes | 10 | 12 |
| Status | passed | passed |

## Root test suite

| Run | Tests | Passed | Failed |
| --- | --- | --- | --- |
| baseline, all repositories on `main` | 1257 | 958 | 208 |
| through Batch 4 wave A | 1267 | 968 | 208 |
| … plus wave B | 1267 | 968 | 208 |

## Not claimed

No push, release or deployment. No locale and no publication state exists yet — a
role-bound site tree in two locales needs waves C and D. No independent review.
