<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * One language of a record written as one new revision: what the write does not name stays as it was, in every
 * language (sites plan, step 4.1).
 */
interface StorageWorkbenchLanguages
{
    /**
     * With $main the language is the one the shared values are written in: $values change the shared values and replace
     * that language's translations of those fields. Otherwise $values become the language's translations of a revision
     * that keeps the shared values; a field that is not localized is refused
     * (`storage_workbench_record_field_not_localized`), and so is nothing to write
     * (`storage_workbench_record_language_empty`).
     *
     * @param array<string, mixed> $values
     */
    public function updateRecordLanguage(
        string $scopeRef,
        string $structureId,
        string $recordId,
        int $expectedRevision,
        string $locale,
        bool $main,
        array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord;
}
