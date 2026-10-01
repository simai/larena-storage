<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Told about every publication transition after it is written, with the
 * projection version it produced — the same number a read reports for the entry. A derived index (Search) uses it to
 * add or remove the public document at once instead of waiting for a rebuild.
 *
 * An observer must not decide anything: a failure it raises is swallowed, and the
 * next rebuild of the derived index heals what it missed.
 */
interface PublicationObserver
{
    public function publicationChanged(PublicationState $state, int $projectionVersion): void;
}
