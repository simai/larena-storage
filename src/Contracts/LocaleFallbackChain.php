<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use InvalidArgumentException;

/**
 * The order in which locales are tried.
 *
 * Storage does not decide the chain — Lang owns that policy. This value object is
 * how the chain is handed in, so the same record resolves the same way wherever it
 * is read from.
 */
final readonly class LocaleFallbackChain
{
    public const LOCALE_PATTERN = '/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/';

    public const MAX_LENGTH = 16;

    /** @param list<string> $locales the requested locale first */
    public function __construct(public array $locales)
    {
        if ($this->locales === []) {
            throw new InvalidArgumentException('A locale fallback chain must contain at least one locale.');
        }

        foreach ($this->locales as $locale) {
            self::assertLocale($locale);
        }
    }

    public static function of(string ...$locales): self
    {
        return new self(array_values($locales));
    }

    public function requested(): string
    {
        return $this->locales[0];
    }

    public static function assertLocale(string $locale): string
    {
        if (strlen($locale) > self::MAX_LENGTH || preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
            throw new InvalidArgumentException('Not a valid locale: ' . $locale);
        }

        return $locale;
    }
}
