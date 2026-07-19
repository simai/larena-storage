# Content Platform v1 protected visibility — Storage evidence

Package: `larena/storage`

Branch: `codex/content-platform-v1-protected-visibility`

Base revision: `c2b3d03ee0c576a67aaad978dc2943b9e64c1237`

Specifications revision: `4ee994762907a703ea9e24939627d9111d89f16d`

Launch record revision: `467b5aba4a797fa2005a0ace133efb294fca55de`

This packet covers only the bounded owner compatibility needed by Larena
Content Platform v1: immutable Storage schemas admit distinct `protected`
visibility, while every public projection remains exact-public and fails
closed for legacy visibility drift.

No migration, table, provider, route, frontend, Content-owned behavior, live
database action, release or deployment is included. Independent review passed
with `P0=0`, `P1=0`, `P2=0`; this packet does not claim Content runtime,
frontend, production or all-package readiness.
