# Storage Examples

## In-Memory Runtime

```php
$runtime = new InMemoryStorageRuntime();
$schema = new ArrayStorageSchema('article', [
    'title' => FieldVisibility::Public,
    'draft_notes' => FieldVisibility::Private,
]);

$runtime->registerSchema($schema);
$record = $runtime->mutate(new ArrayStorageMutation(
    schema: 'article',
    type: MutationType::Create,
    payload: ['title' => 'Hello']
));
```

Use this style only for tests and early integration. Production persistence
requires a separate launch record.

## Access-Scoped Read

```php
$scoped = new AccessScopedStorageRuntime($runtime, $scopeResolver);
$records = $scoped->list(new ArrayStorageQuery(schema: 'article'));
```

The scope resolver belongs to the access boundary. Storage applies the returned
scope; it does not decide permissions.

## Audit-Aware Mutation

```php
$audited = new AuditAwareStorageMutationRuntime($runtime, $auditEmitter);
$result = $audited->mutate($mutation);
```

The emitted event is a descriptor, not audit storage. `larena/audit` owns
retention and audit pipeline behavior.
