# Batch 4 wave C — smoke

## Migration parity

| Driver | Result |
| --- | --- |
| MySQL 8.2 | `2026_09_28_000001_create_larena_storage_localized_values_table … DONE` in 30ms |
| SQLite (file-backed) | same migration, 3ms |

## Two locales from one revision, on both drivers

Wrote English `title` and `description` plus a Russian `title` on revision 1, then
resolved with the chain `ru, en`:

| Field | Value | Source | Resolution |
| --- | --- | --- | --- |
| `title` | Главная | ru | exact |
| `description` | Front | en | fallback |

Identical on MySQL 8.2 and SQLite. Coverage on the same record:

```
[{"locale":"en","present":["title","description"],"missing":[],"complete":true},
 {"locale":"ru","present":["title"],"missing":["description"],"complete":false}]
```

## Restart readback

A separate SQLite process resolved the same record and returned the same exact and
fallback markers with the same source locales, and `explain` reported
`stored_locales: ["en","ru"]`, `value_count: 3`, `immutable: true`.

## Operation coverage

| Measure | After wave B | After wave C |
| --- | --- | --- |
| Registered operations | 33 | 38 |
| Covered access codes | 12 | 14 |
| Status | passed | passed |

## Root test suite

| Run | Tests | Passed | Failed |
| --- | --- | --- | --- |
| baseline, all repositories on `main` | 1257 | 958 | 208 |
| through wave B | 1267 | 968 | 208 |
| … plus wave C | 1267 | 968 | 208 |

## Not claimed

No push, release or deployment. Publishing one locale while another stays a draft is
wave D. The two-locale *render* the acceptance criterion asks for needs the read
contracts in wave E.
