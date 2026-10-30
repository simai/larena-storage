<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * May refuse a translation before it is written, for what it would change (LocalizedValueRejected). An observer of the
 * writes (LocalizedValueObserver) is asked when it implements this too.
 */
interface LocalizedValueGuard
{
    /** @param array<string, mixed> $values field key => value, as asked */
    public function assertMayWriteLocalized(string $schemaId, string $recordId, int $revision, string $locale, array $values): void;
}
