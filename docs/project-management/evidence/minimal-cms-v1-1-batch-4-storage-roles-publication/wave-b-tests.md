# Batch 4 wave B — tests

| Test | What it proves |
| --- | --- |
| `RecordRelationsTest` | A reference is defined, resolved and explained; several references under one key keep their order; a tree edge carries parent-then-child path and depth; a record with no edge explains itself as having none rather than failing; a tree edge is not counted as a reference |
| `RecordRelationsTreeTest` | Children in sibling order; ancestors nearest-last from the path; an empty page for a root; a move rewriting path, depth and identity for the record and every descendant while leaving other branches alone; sibling order preserved; a move to the root; the budget reporting truncation; the depth limit proven by building 32 levels and being refused the 33rd |
| `RecordRelationsFailsClosedTest` | Self-relation, own-ancestor, second tree parent, **a direct insert bypassing the class refused by the unique index**, a reference to the same pair still allowed, cross-schema parent, move into own subtree, move of a record with no edge, three invalid record ids, an out-of-range budget, an unknown relation key, and `schema_missing` |
| `RelationDeletePolicyTest` | Each policy on the same three-level tree: restrict refusing and naming the count, a leaf still deletable under restrict, cascade reporting what it removed, detach freeing the child as a root and keeping the grandchild reachable, and a dangling reference removed |
| `RelationTraversalScopeTest` | A hidden ancestor counted and never named — in the path, in the parent link and in the relation id; an unfiltered read complete; a permissive filter masking only what is outside the readable set; a filter hiding everything returning an empty page with the full count; truncation and filtering as independent signals |
