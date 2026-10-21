# Read contracts

Two read models serve a site, and public lists are read from them. All are derived
from Storage, all read only the published head, and no consumer may treat a copy of
any as a source of truth.

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

## publishedList

```php
use Larena\Storage\Contracts\PublishedListQuery;
use Larena\Storage\Enums\StorageWorkbenchCountMode;

$page = $lists->publishedList(new PublishedListQuery(
    'site_news@v1', 'site:main', 'ru',
    filters: ['status' => ['operator' => 'eq', 'value' => 'live'], 'price' => ['operator' => 'between', 'values' => ['10', '20']]],
    search: 'oak chair',
    sort: [['field' => '_published_at', 'direction' => 'desc']],
    limit: 20,
    continuation: $previous?->continuation,
    countMode: StorageWorkbenchCountMode::Auto,
), $visibilityFilter);
$page->records;        // entries shaped as publishedProjection records
$page->continuation;   // opaque; null on the last page
$page->matchedCount;   // null with count skip (the default) or a visibility filter
$page->countExact;     // false for an estimate («~N»)
$page->filteredCount;  // records of this page's scan the caller may not read
```

A separate contract, `PublishedListReads`, implemented by `DatabaseReadContracts`
(`ReadContracts` is unchanged). It lists what the projection holds — the published head
of each record in one scope and locale, public fields only — narrowed, searched, sorted
and paged:

- **Filters** have the workbench record query's shape (`StorageRecordQueryNormalizer`,
  shared with the workbench): `eq`, `in`, `contains`, `starts_with`, `gt`, `gte`, `lt`,
  `lte`, `between` by field type; `choices` take `eq`/`in`; at most 8 filters, 100 values
  and 1000 bytes per fragment.
- **Search** is word-prefix search (`StorageViewSearch`) over the public searchable fields:
  every term at the start of a word; at most 100 bytes.
- **Sort**: up to three keys over public scalar fields, `_published_at` (when the record was
  published in that scope and locale) or `_record_id`; ties and an empty sort follow the
  record id in the direction of the last key; a missing value sorts first ascending.
- **limit** 1..100.
- **Count**: `skip` by default; `auto` (exact up to 10 000 within 250 ms on MySQL, an
  estimate above) or `exact`.

**Only public fields.** A filter, search or sort that names a field that is not public — an
admin field, a system field, a field that does not exist — is refused with the same
`invalid_query` and the same message as any other invalid query, so a list never reveals
which fields a structure hides.

**The cursor** is a `StorageViewKeyset` continuation (the sort keys and record id of the
last row scanned, HMAC-signed with the application key, bound to the query hash and to the
slot — the storage, scope and locale), sealed in an authenticated secret box. An altered
cursor, or one replayed against another scope, locale, filter, search or sort, is
`invalid_query`. A sort key too long to carry continues by position. Keyset paging neither
repeats nor skips a record when others are published or unpublished between two pages.

**Where it is answered.** A ready site витрина slot answers in SQL: the query compiled onto
the slot (`slot_id = ?`, typed columns, `_m` for choices, `_s` for search, FULLTEXT on
MySQL), one query for the entries of the page, and each page checked against the
publication states (revision and publication time of every row) — a disagreement is read
again in one snapshot, then silences the slot, queues its rebuild and the request is
answered from the records. Without a ready slot (or for a query its columns cannot answer
exactly), the list is answered from the published records in PHP with the same semantics
while they number at most `LIST_FALLBACK_LIMIT` (5000); above that it refuses with
`site_view_not_ready` rather than read every record on a request. A page from the slot
costs four queries.

**Visibility.** With a visibility filter the rows are filtered in PHP: a page scans at
most 500 rows, may be shorter than its limit while a continuation remains (the cursor
continues after the last row scanned, never revealed), and reports no total.

The operation is `storage.read.published_list` (anonymous, like the projection); REST
`GET /api/v1/site/list` takes `target`, `scope_ref`, `locale`, `filters` (a JSON object
string, at most 8 KB), `search`, `sort` (`field[:asc|:desc]`, comma-separated, at most
three), `limit`, `cursor` and `count`; `PublishedListQuery::fromInput()` parses both forms.
An invalid query is 422 `invalid_query`, a list that is not ready 503
`site_view_not_ready`. Each list is timed as `storage.read.published_list` (tags `path`:
slot|fallback|none, `count`, `outcome`).

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
