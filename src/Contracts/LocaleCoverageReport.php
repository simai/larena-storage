<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Which localized fields exist in which locale for one revision.
 *
 * A count, not a parse: the layout puts one row per field and locale precisely so
 * that this question is answered by the database rather than by decoding every
 * document.
 */
final readonly class LocaleCoverageReport
{
    /**
     * @param list<string> $declaredLocales
     * @param list<string> $localizedFields
     * @param array<string, array{locale: string, present: list<string>, missing: list<string>, complete: bool}> $perLocale
     */
    public function __construct(
        public string $schemaId,
        public string $recordId,
        public int $revision,
        public array $declaredLocales,
        public array $localizedFields,
        public array $perLocale,
    ) {
    }

    public function complete(): bool
    {
        foreach ($this->perLocale as $locale) {
            if ($locale['complete'] !== true) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_id' => $this->schemaId,
            'record_id' => $this->recordId,
            'revision' => $this->revision,
            'declared_locales' => $this->declaredLocales,
            'localized_fields' => $this->localizedFields,
            'per_locale' => array_values($this->perLocale),
            'complete' => $this->complete(),
        ];
    }
}
