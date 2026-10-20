<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Localized values of many revisions resolved in one read, for a page of records.
 * The answer per revision is exactly what `LocalizedValues::resolve` gives for it.
 */
interface LocalizedValuesBatch
{
    /**
     * @param list<array{record_id: string, revision: int}> $heads
     * @param list<string> $fieldKeys empty means every field
     * @return array<string, array<string, LocalizedValue>> by record id, then field key
     */
    public function resolveMany(string $schemaId, array $heads, LocaleFallbackChain $chain, array $fieldKeys = []): array;
}
