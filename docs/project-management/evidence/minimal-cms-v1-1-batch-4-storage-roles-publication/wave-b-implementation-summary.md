# Batch 4 wave B — Record relations and trees

Repository: `simai/larena-storage`
Branch: `feature/minimal-cms-v1-1-batch-4-storage-record-relations` from the wave A branch at `ed68c5a`
Launch record: `docs/project-management/launch-records/minimal-cms-v1-1-batch-4-wave-b-storage-record-relations.json`

## What exists now

One table holds both kinds of edge. A `reference` points at another record; a
`tree_parent` edge additionally places the record in a hierarchy and carries a
materialized path, a depth and a sibling order.

Three decisions do the work:

**The single parent is a unique index, not a check.** `tree_child_key` is NULL for
a reference edge and equals the child id for a tree edge, so the unique index on
`(relation_key, tree_child_key)` *is* the guarantee. A check in PHP can lose a
race; an index cannot. The test proves it by bypassing the class entirely and
inserting the row directly — the database refuses it. Repeated NULLs are legal in a
unique index on both SQLite and MySQL, which is why references are unaffected.

**No recursive query anywhere.** Children are read by parent, ancestors come from
splitting the child's path, a subtree is a prefix match. MySQL 5.7 has no recursive
CTE and ordinary shared hosting still runs it, so the tree behaves identically on
5.7, on 8.2 and on SQLite.

**A move rewrites the whole subtree in one transaction**, including each
descendant's path and depth, and the moved record's own identity — the relation id
encodes the parent, so leaving the old one would mean a primary key naming a parent
the row no longer has.

## The leak the tests found

A materialized path spells out every ancestor id, and every edge names its parent.
The first version of the scoped traversal filtered the *rows* and returned the
surviving records untouched — so a caller who could not read `sales` still received
`company/sales/field` in the path of the record above it, and `sales` again as the
parent link and inside the relation id. The filter hid the row and the payload gave
it back.

The fix is not to blank everything, which would also hide ancestors the caller
*may* read and make the answer useless for building a tree. The filter is evaluated
for the whole slice first, and only the ids it actually rejected are masked with
`*`, plus the ids the caller supplied, which are never a leak. Depth and order
survive, because they are structural rather than identifying.

A permissive filter therefore masks nothing it does not have to: `*/sales/field`
rather than `*/*/field`.

## Delete policies

| Policy | Behaviour |
| --- | --- |
| `restrict` | refuses while descendants exist, and the refusal says how many |
| `cascade` | removes the subtree and reports which records it removed |
| `detach` | frees each child as a root, marks the row `detached`, and the grandchildren stay reachable |

A reference edge pointing at a deleted record is removed under every policy,
because pointing at nothing is not a state worth keeping.

## Traversal budget

Default 2000 rows. A traversal reads one row past its budget so that truncation is
observed rather than guessed, and reports `truncated` with the count returned. A
silently short answer is worse than an error, because nothing downstream can tell
it from a complete one.

## Gates

`composer quality:gate` passes: PHPStan level 5, the package suite with five new
test files, metadata sync, evidence contract, scope check.
