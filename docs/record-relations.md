# Record relations and trees

One table holds both kinds of edge.

```php
$tree = new RelationDescriptor('site_tree_parent', RelationKind::TreeParent, RelationDeletePolicy::Cascade);
$relations->define($tree, 'site.pages', $childId, $parentId, $actorId);
```

For a tree edge the **child** is `from_record_id` and the **parent** is
`to_record_id`, so reading children means querying by parent. Getting that backwards
is the easiest mistake to make with this table.

## One parent, guaranteed by the database

`tree_child_key` is NULL for a reference and equals the child id for a tree edge, so
the unique index on `(relation_key, tree_child_key)` is the single-parent guarantee.
A check in PHP can lose a race; an index cannot. Repeated NULLs are legal in a
unique index on both SQLite and MySQL, so references are unaffected.

## No recursive queries

Children are read by parent, ancestors come from splitting the child's materialized
path, and a subtree is a prefix match. MySQL 5.7 has no recursive CTE and ordinary
hosting still runs it, so the tree behaves the same there as on 8.2 and on SQLite.

Maximum depth is 32.

## Moving a branch

```php
$moved = $relations->move('site_tree_parent', $recordId, $newParentId, $actorId);
```

One transaction rewrites the path and depth of the record and every descendant, and
the record's own relation id, which encodes the parent. Sibling order is preserved.
The operation is declared `bulk`, so the confirmation policy always asks first.

## Traversal, scope and truncation

```php
$page = $relations->ancestors('site_tree_parent', $recordId, budget: 100, visibilityFilter: $filter);
```

`$page->truncated` says the budget stopped the walk. `$page->filteredCount` says how
many records the caller may not read — as a number. The identifiers of those records
are masked with `*` wherever they would otherwise appear: in the path, in the parent
link and in the relation id. Only the ids the filter actually rejected are masked, so
a caller who may read an ancestor still sees its name.

## Delete policies

`restrict` refuses while descendants exist and says how many. `cascade` removes the
subtree. `detach` frees each child as a root and keeps the grandchildren reachable.
The choice belongs to the versioned schema, not to the caller: the same delete must
not behave differently depending on who asks.
