<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Hears of every localized value write, inside its transaction. A translation added
 * to a revision that is already published changes what that revision shows, so an
 * index derived from published values has to learn of it.
 */
interface LocalizedValueObserver
{
    public function localizedValuesWritten(string $schemaId, string $recordId, int $revision, string $locale): void;
}
