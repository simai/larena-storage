# Batch 4 wave A — Storage structure roles

Repository: `simai/larena-storage`
Branch: `feature/minimal-cms-v1-1-batch-4-storage-structure-roles` from `main` at `e459770`
Launch record: `docs/project-management/launch-records/minimal-cms-v1-1-batch-4-wave-a-storage-structure-roles.json`
Pre-codegen freeze: `larena-specs` → `specs/implementation-planning/minimal-cms-v1-1-batch-4-pre-codegen-freeze.json`
Target: `larena.target.minimal_cms_v1_1`, digest `sha256:cbbfa628327f11f1f2c958c9b24cc10334fba965b9cce232e40884a6f37a1d3f`

## What exists now

A structure role is a versioned contract that owns no table and adds no
behaviour. It names the fields a structure must have, the relations it must
declare and whether its records are publishable; a structure gains the role by
conforming, not by inheriting from anything. That is what keeps role-specific
business code out of Storage, which is the whole point of the design.

Two rules carry the implementation:

- **A role row is written once and never edited.** A breaking change is a new
  `role_version`, so a structure validated against version 1 cannot have the
  contract changed under it. `site_node@v1` and `site_node@v2` coexist.
- **Binding runs conformance first and writes nothing when it fails.** A binding
  that does not hold would be worse than no binding, because every consumer of
  `list_structures` trusts it.

Conformance names every failure: each missing field, each type mismatch with both
the expected and the actual type, each missing relation, and a lifecycle
mismatch. "Does not conform" sends someone to read two files; "missing title, and
order_index is a string where the role wants an integer" sends them to one line.

A role is a floor, not a ceiling — a structure with extra fields still conforms.

## The five starter roles

| Role | Lifecycle | Required fields | Required relations |
| --- | --- | --- | --- |
| `site_node` | publishable | slug, title, order_index, target | site_tree_parent |
| `redirect` | plain | from_path, to_target, status_code | — |
| `doc_space` | publishable | slug, title | — |
| `doc_page` | publishable | slug, title, body, order_index | doc_tree_parent, doc_space_ref |
| `org_chart` | plain | core_plane_node_id, title | — |

`redirect` has no publication lifecycle on purpose: a draft redirect is a
contradiction. `org_chart` is an overlay — it points at a core plane node by
identifier and duplicates neither the membership nor the hierarchy, which stay in
core where Batch 1 put them.

Installation is idempotent under concurrency: a re-run leaves every row
byte-identical, and a role installed by another process between the read and the
write is treated as already present rather than as an error.

## Registering the operations

Storage now declares its five role operations in its own `operations.yaml` and
contributes them to the core registry. The registry holds 27 operations in the
composed application — 22 from core, 5 from storage — which is what REST parity
and the MCP projection read. Two of the 23 access operation codes the Batch 3
coverage report listed as unscheduled gaps are now covered; the remaining nine
storage codes arrive with waves B to E.

## One thing the wiring had to work around

`larena/core` composes its registry from a hard-coded provider list, so a package
cannot contribute by binding a tag. Storage extends the resolved registry
instance instead, which needs no change to core and keeps the single-catalogue
guarantee. It is a workaround, not a design: the proper fix is a tagged
contributor port in core, and it is recorded as a finding for review.

## Gates

`composer quality:gate` passes: `validate:larena`, `lint`, PHPStan level 5,
the package suite including five new tests, `larena:metadata:check`,
`evidence:check`, `scope:check`.
