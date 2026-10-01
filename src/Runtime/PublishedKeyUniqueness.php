<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\PublicationGuard;
use Larena\Storage\Contracts\PublishedKeyPolicy;
use Larena\Storage\Exceptions\PublicationRejected;

/**
 * Refuses to publish a revision whose key another published record of the same
 * structure, scope and locale already holds.
 *
 * Without it a duplicate is caught only on read, as `ambiguous_key`, after the
 * site has already lost the page. The key value is the one the revision would show
 * in that locale, translations included, which is what `resolveKey` compares.
 */
final readonly class PublishedKeyUniqueness implements PublicationGuard
{
    public function __construct(
        private DatabaseReadContracts $readContracts,
        private PublishedKeyPolicy $keys,
    ) {
    }

    public function assertMayPublish(string $schemaId, string $recordId, string $scopeRef, string $locale, int $revision): void
    {
        $keyField = $this->keys->keyFieldFor($schemaId, $scopeRef);
        if ($keyField === null) {
            return;
        }

        $keyValue = $this->readContracts->publicValuesAt($schemaId, $recordId, $revision, $locale)[$keyField] ?? null;
        if (!is_string($keyValue) || $keyValue === '') {
            return;
        }

        foreach ($this->readContracts->keyHolderIds($schemaId, $scopeRef, $locale, $keyField, $keyValue) as $holder) {
            if ($holder !== $recordId) {
                throw new PublicationRejected(
                    'key_conflict',
                    $keyField . '=' . $keyValue . ' is already published in ' . $scopeRef . '/' . $locale . ' by record ' . $holder . '.',
                );
            }
        }
    }
}
