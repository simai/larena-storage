<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\PublicationRejected;

/** A check a revision must pass before it becomes the published head. */
interface PublicationGuard
{
    /** @throws PublicationRejected */
    public function assertMayPublish(string $schemaId, string $recordId, string $scopeRef, string $locale, int $revision): void;
}
