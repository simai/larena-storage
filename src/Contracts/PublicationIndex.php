<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\PublicationRejected;

/**
 * A derived index kept in the same transaction as a publication transition, such
 * as the published-key table. Unlike an observer it can refuse: a refusal rolls the
 * transition back, so the index and the publication never disagree.
 */
interface PublicationIndex
{
    /** @throws PublicationRejected */
    public function publicationWritten(PublicationState $state): void;
}
