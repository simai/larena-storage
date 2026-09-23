# Batch 4 wave A — smoke

Root: `simai/larena` at `0d328ea1`, consuming the package by Composer path symlink.

## Migration parity

| Driver | Result |
| --- | --- |
| MySQL 8.2 (`larena`) | `2026_09_26_000001_create_larena_storage_structure_role_tables … DONE` in 39ms; index names accepted |
| SQLite (file-backed, fresh database) | migrated with the full stack, 71 migrations |

The index names are given explicitly and kept short for a reason found in Batch 1:
MySQL rejects an identifier over 64 characters while SQLite accepts it silently,
so a generated name can pass every test and fail on the production driver.

## Round trip and restart readback

On both drivers, in **separate processes**:

1. `ScopeBaselineInstaller::apply()` creates `site:main`, its `groups` plane and
   the `administrators` node.
2. `StarterStructureRoles::install()` installs all five roles.
3. A new process lists them back: `doc_page@v1, doc_space@v1, org_chart@v1, redirect@v1, site_node@v1`.
4. A conforming structure binds: `site_node@v1|site.pages|site:main`.
5. Another new process reads the binding back with its `conformance_checked_at`.
6. A second `install()` reports all five as already present and installs nothing.

## The fail-closed path, observed in the composed application

Binding before the scope baseline existed returned
`StructureRoleRejected: Unknown scope: site:main` and wrote nothing. That was not
a planned smoke step — it happened because the fresh SQLite database had no
scopes yet — and it is the clearest evidence in this wave that the scope check is
real rather than a unit-test fiction.

## Operation coverage after the wave

```
php artisan core:operations:coverage --json
```

| Measure | Before wave A | After wave A |
| --- | --- | --- |
| Registered operations | 22 | 27 |
| Covered access codes | 8 | 10 |
| Storage codes still gapped | 9 | 9 |
| Status | passed | passed |

The storage gap count is unchanged because the two codes this wave adds are new:
they are covered the moment they exist. The nine pre-existing storage codes belong
to record mutation and schema evolution and are not part of this batch.

## Root test suite

| Run | Tests | Passed | Failed |
| --- | --- | --- | --- |
| baseline, all repositories on `main` | 1257 | 958 | 208 |
| Batch 1 + 2 + Batch 3 waves A–D | 1267 | 968 | 208 |
| … plus Batch 4 wave A | 1267 | 968 | 208 |

Identical. Wave A adds no root-visible behaviour: the roles are reachable through
the container and the registry, which no existing root test exercises.

## Not claimed

No push, release or deployment. No relation, locale or publication surface exists
yet — a role-bound site tree needs waves B, C and D. No independent review.
