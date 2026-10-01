<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface ReadContracts
{
    /**
     * At most one published record for a key in a scope and a locale.
     *
     * Two candidates are a data defect and fail closed rather than returning the first:
     * silently picking one would make a site serve a different page depending on row
     * order.
     */
    public function resolveKey(
        string $roleRefOrSchemaId,
        string $scopeRef,
        string $locale,
        string $keyField,
        string $keyValue,
        ?callable $visibilityFilter = null,
    ): ?ResolvedKey;

    /**
     * The published head of every record in a scope and locale, carrying only fields
     * whose visibility is public and only records the caller may read.
     */
    public function publishedProjection(
        string $roleRefOrSchemaId,
        string $scopeRef,
        string $locale,
        ?int $budget = null,
        ?callable $visibilityFilter = null,
        ?string $afterRecordId = null,
    ): PublishedProjectionPage;

    /**
     * One published record of a role or schema in a scope and locale, in the same
     * shape as a projection entry, or null when it is not published there.
     *
     * Every projection entry carries `projection_version`: a number that only grows,
     * and grows whenever what the entry shows or whether it is published changes —
     * a publication transition or a localized value written for the published
     * revision in that locale. A derived index uses it as its source revision.
     *
     * @return array{record_id: string, revision: int, locale: string, projection_version: int, values: array<string, mixed>}|null
     */
    public function publishedRecord(
        string $roleRefOrSchemaId,
        string $scopeRef,
        string $locale,
        string $recordId,
        ?callable $visibilityFilter = null,
    ): ?array;

    /**
     * What the projection of a scope and locale holds for this caller. Records the
     * caller may not read are reported as a count, never by identifier.
     *
     * @return array<string, mixed>
     */
    public function projectionExplain(string $roleRefOrSchemaId, string $scopeRef, string $locale, ?callable $visibilityFilter = null): array;
}
