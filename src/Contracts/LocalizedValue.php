<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * One field value for one locale, and where it came from.
 *
 * `exact` means the requested locale had it. `fallback` means the chain found it
 * somewhere else, and `sourceLocale` says where. A reader that cannot tell the two
 * apart cannot tell a translated page from an untranslated one, which is the whole
 * reason the marker exists.
 */
final readonly class LocalizedValue
{
    public function __construct(
        public string $fieldKey,
        public string $requestedLocale,
        public string $sourceLocale,
        public mixed $value,
        public bool $exact,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'field_key' => $this->fieldKey,
            'requested_locale' => $this->requestedLocale,
            'source_locale' => $this->sourceLocale,
            'value' => $this->value,
            'exact' => $this->exact,
            'resolution' => $this->exact ? 'exact' : 'fallback',
        ];
    }
}
