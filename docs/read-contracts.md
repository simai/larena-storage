# Read contracts

Two read models serve a site. Both are derived from Storage, both read only the
published head, and no consumer may treat a copy of either as a source of truth.

## resolveKey

```php
$resolved = $read->resolveKey('site_node@v1', 'site:main', 'en', 'slug', 'about');
```

At most one published record. Two candidates **fail closed** with `ambiguous_key` rather
than returning the first: a site that served whichever row came back first would be
broken in a way nobody could reproduce.

A miss returns null — and so does a record the caller may not read. The absence of a page
must not confirm its existence.

The first argument may be a role reference (`site_node@v1`) or a schema id
(`site.pages`); a role resolves through its active binding.

## publishedProjection

```php
$page = $read->publishedProjection('site_node@v1', 'site:main', 'ru', budget: 100, visibilityFilter: $filter);
$page->filteredCount;  // a number
$page->truncated;      // the budget stopped the page
```

Only the published head, only fields whose visibility is `public`, only records the
caller may read. A field nobody marked is **not** public: a reader must never see a field
because nobody said what it was. A localized value replaces the shared one for its
locale, and visibility still belongs to the field — a translated protected field stays
protected.

A schema with no declared public field projects nothing at all, in any locale.

## Slug uniqueness

```php
$guard->assertAvailable($schemaId, 'site:main', 'en', 'slug', 'about', exceptRecordId: $recordId);
```

Unique per **structure, scope and locale** — not per table. Two sites may both have a page
at `/about`, and one site may have a different `about` per locale. What must not happen is
two published records answering to the same key in the same scope and locale, because then
`resolveKey` has no single answer.

It is a guard rather than a database index because the key field's name is declared per
schema: there is no column to index. It asks the published projection, so "published" has
exactly one definition in this package.
