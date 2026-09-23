# Localized values

A localized value is a row, not a column and not a key inside the document:

```
larena_storage_localized_values (schema_id, record_id, revision, locale, field_key) → value_json
```

Per-locale reads are indexed, coverage is a count, and immutability comes free
because a row is written with its revision and never updated. A change is a new
revision.

```php
$values->write($schemaId, $recordId, $revision, 'ru', ['title' => 'Главная'], $localizedFields, $actorId);
```

A field must be declared localized — through the workbench structure descriptor's
optional `localized` key — or the write is refused. A non-localized field keeps its
single value in `values_json` and never appears here.

## Reading with a fallback

```php
$resolved = $values->resolve($schemaId, $recordId, $revision, LocaleFallbackChain::of('ru', 'en'));
$resolved['title']->exact;        // true when Russian had it
$resolved['title']->sourceLocale; // where it actually came from
```

Storage does not decide the chain; Lang owns that policy and the chain is handed in.
Every value says whether it is exact or a fallback, because a reader that cannot tell
the two apart cannot tell a translated page from an untranslated one.

The whole chain resolves in one query. Walking it locale by locale would cost one
round trip per fallback step.

## Absent is not empty

`resolve()` omits a field it cannot find in any locale of the chain. It does not
return null, because null is a value a field can legitimately hold. Treat a missing
key as "not translated" and a present null as "translated to nothing" — the
difference matters to every consumer.

## Coverage

```php
$report = $values->coverage($schemaId, $recordId, $revision, ['en', 'ru'], $localizedFields);
$report->complete();
$report->perLocale['ru']['missing']; // ['description']
```

Per declared locale: what is present, what is missing by name, and whether it is
complete. A locale the site does not declare is not reported even when rows exist for
it.

A required localized field missing in a locale is refused unless the schema allows
partial locales, because a half-translated record that can be published is worse than
a refusal.
