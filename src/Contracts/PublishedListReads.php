<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Public filtered lists of published records: the site's catalogue, news and search
 * pages.
 *
 * A list reads what `ReadContracts::publishedProjection` reads — the published head
 * of each record in one scope and locale, public fields only, in the same entry shape
 * — and narrows, searches, sorts and pages it. Only public fields may be filtered,
 * searched or sorted on; a query that names any other field is refused exactly like a
 * query that names a field that does not exist (`invalid_query`), so a list never
 * tells a reader which non-public fields a structure has.
 *
 * The continuation is opaque: signed, encrypted, and bound to the storage, scope and
 * locale it was issued for and to the query (filters, search, sort). Replayed against
 * another scope or another query it is refused (`invalid_query`).
 *
 * A storage whose site витрина cannot answer yet is listed from its published
 * records directly while it has at most a few thousand of them; above that the list
 * refuses (`site_view_not_ready`) rather than read every record on a request.
 */
interface PublishedListReads
{
    /**
     * One page of the list. With a visibility filter, records the caller may not read
     * are skipped and counted in `filteredCount`; the page may then be shorter than its
     * limit while a continuation remains, and no total is reported.
     *
     * @param (callable(string): bool)|null $visibilityFilter
     */
    public function publishedList(PublishedListQuery $query, ?callable $visibilityFilter = null): PublishedListPage;
}
