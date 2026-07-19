# Smoke

Current status: `accepted_pending_commit_and_push`

- package autoload: PASS;
- immutable schema with `public`, `protected` and `admin`: PASS;
- canonical protected visibility round-trip: PASS;
- public projection contains only the exact public value: PASS;
- unknown, missing and invalid legacy visibility fails closed: PASS;
- no migration, provider, route or table change: PASS by current diff;
- complete package test list and quality gate: PASS;
- exact launch scope: PASS with 22 changed files;
- independent review: PASS, `P0=0`, `P1=0`, `P2=0`.
