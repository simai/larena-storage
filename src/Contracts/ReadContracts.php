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
    ): PublishedProjectionPage;

    /**
     * @return array<string, mixed>
     */
    public function projectionExplain(string $roleRefOrSchemaId, string $scopeRef, string $locale): array;
}
