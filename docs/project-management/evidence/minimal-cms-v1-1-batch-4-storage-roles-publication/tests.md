# Batch 4 wave A — tests

| Test | What it proves |
| --- | --- |
| `StructureRoleRegistryTest` | Registration, read-back with lifecycle and relations intact, duplicate version refused, a second version coexisting with the first, ordered and owner-filtered listing, binding identity, scope as part of that identity, and `explain` counting active bindings |
| `StructureRoleConformanceTest` | A conforming structure conforms; extra fields do not break it; every missing field is named; a type mismatch names both types; a missing relation is named; a publishable role refuses a plain or undeclared lifecycle and says which; a plain role imposes none; `doc_page` needs both relations; the `org_chart` overlay conforms without duplicating the hierarchy |
| `StructureRoleFailsClosedTest` | Unknown role in both paths, a refused binding leaving no row, duplicate binding, unknown scope when a resolver is bound, the happy path through the same scoped registry, and `schema_missing` when the tables are absent |
| `StarterStructureRolesIdempotencyTest` | Four installs leave the rows **byte-identical**, not merely the same count — an install that rewrote `created_by` or `updated_at` would quietly rewrite history; the starter set and its lifecycles are exactly the frozen ones |
| `StorageOperationDeclarationTest` | The five declarations load, each binds to a descriptor, and registering storage beside core yields one 27-operation catalogue with the gates, risks and receipts intact and exactly two access codes |

## PHPStan

Level 5 clean. The analyser scans `../core/src` and `../../app/vendor/symfony/yaml`
because `larena/core` and the YAML parser are declared requirements that composer
cannot install into the package vendor tree in this workspace — the same
arrangement `larena/access` already uses for `../core/src`.
