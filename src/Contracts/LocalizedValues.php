<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface LocalizedValues
{
    /**
     * Write the localized values of one revision.
     *
     * Rows belong to their revision and are written once: a second write for the
     * same revision, locale and field is refused rather than overwritten, because a
     * revision that can change is not a revision.
     *
     * @param array<string, mixed> $values field key => value
     * @param list<string> $localizedFieldKeys the fields the schema declares localized
     * @param list<string> $requiredFieldKeys the localized fields the schema requires
     * @return list<string> the field keys written
     */
    public function write(
        string $schemaId,
        string $recordId,
        int $revision,
        string $locale,
        array $values,
        array $localizedFieldKeys,
        string $actorId,
        array $requiredFieldKeys = [],
        bool $partialLocalesAllowed = false,
        ?string $correlationId = null,
    ): array;

    /**
     * Read the localized values of one revision for one locale, with no fallback.
     *
     * @return array<string, LocalizedValue>
     */
    public function read(string $schemaId, string $recordId, int $revision, string $locale): array;

    /**
     * Resolve each field through the fallback chain, marking every value exact or
     * fallback and naming the locale it came from.
     *
     * @param list<string> $fieldKeys
     * @return array<string, LocalizedValue> only the fields that resolved; an absent
     *                                       field is absent, not empty
     */
    public function resolve(
        string $schemaId,
        string $recordId,
        int $revision,
        LocaleFallbackChain $chain,
        array $fieldKeys = [],
    ): array;

    /**
     * @param list<string> $declaredLocales
     * @param list<string> $localizedFieldKeys
     */
    public function coverage(
        string $schemaId,
        string $recordId,
        int $revision,
        array $declaredLocales,
        array $localizedFieldKeys,
    ): LocaleCoverageReport;

    /**
     * @return array<string, mixed>
     */
    public function explain(string $schemaId, string $recordId, int $revision): array;
}
