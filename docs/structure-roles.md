# Structure roles

A role is a contract a structure can satisfy. It owns no table and adds no
behaviour: it names the fields a structure must have, the relations it must
declare, and whether its records are publishable.

```php
$registry->register(new StructureRole(
    roleCode: 'article',
    roleVersion: 1,
    title: 'Article',
    requiredFields: [['key' => 'slug', 'type' => 'string'], ['key' => 'title', 'type' => 'string']],
    optionalFields: [],
    requiredRelations: [],
    lifecycle: RoleLifecycle::Publishable,
    ownerPackage: 'acme/blog',
), $actorId);
```

A role row is written once and never edited. A breaking change is a new
`role_version`, so a structure validated against version 1 keeps the contract it
was validated against; both versions coexist and are addressed as `article@v1`
and `article@v2`.

## Binding a structure

```php
$report = $registry->validateStructure('article@v1', 'blog.articles', $fields, $relationKeys, 'publishable');
if ($report->conforms) {
    $registry->bindStructure('article@v1', 'blog.articles', 'site:main', $fields, $actorId, $relationKeys, 'publishable');
}
```

Binding runs conformance itself and writes nothing when it fails, so a binding you
can read is a binding that holds. The report names every missing field, every type
mismatch with both types, every missing relation and any lifecycle mismatch.

A role is a floor, not a ceiling: a structure with extra fields still conforms.

The scope is part of the binding identity, so the same structure can be the site
tree of one site and play no part in another.

## The starter roles

`site_node`, `redirect`, `doc_space`, `doc_page` and `org_chart`, installed by
`StarterStructureRoles` — idempotently, and never by editing an existing row.

`org_chart` is an overlay: it carries a `core_plane_node_id` and duplicates
neither the membership nor the hierarchy, which live in `larena/core`.

## Operations

`storage.role.register`, `storage.role.validate_structure`,
`storage.role.bind_structure`, `storage.role.list_structures` and
`storage.role.explain`, declared in `operations.yaml` and registered in the core
operation registry. Register and bind also answer in proposal mode, so an actor can
ask what a binding would do — the conformance report — before doing it.
