<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Exceptions\ReadContractRejected;

/**
 * Keeps a key field unique where it has to be.
 *
 * The uniqueness scope is the structure, the scope and the locale — not the whole
 * table. Two sites may both have a page at `/about`, and the same site may have a
 * different `about` in each locale; what must not happen is two published records
 * answering to the same key in the same scope and locale, because then `resolveKey`
 * has no single answer and a site serves whichever row came back first.
 *
 * It is a guard rather than a database index because the key field's name is declared
 * per schema: there is no column to index.
 */
final class SlugUniquenessGuard
{
    /**
     * The guard needs no connection of its own: it asks the published projection, which
     * is the same read `resolveKey` uses. Anything else would be a second definition of
     * "published" that could drift from the first.
     */
    public function __construct(private readonly DatabaseReadContracts $readContracts)
    {
    }

    /**
     * @throws ReadContractRejected when another published record already answers to
     *                              this key in this scope and locale
     * @phpstan-impure
     */
    public function assertAvailable(
        string $schemaId,
        string $scopeRef,
        string $locale,
        string $keyField,
        string $keyValue,
        ?string $exceptRecordId = null,
    ): void {
        // Every published record, not the first projection page: a conflict on
        // record 2001 is still a conflict.
        foreach ($this->readContracts->keyHolderIds($schemaId, $scopeRef, $locale, $keyField, $keyValue) as $recordId) {
            if ($exceptRecordId !== null && $recordId === $exceptRecordId) {
                // The record is allowed to keep its own slug.
                continue;
            }

            throw new ReadContractRejected(
                'slug_conflict',
                $keyField . '=' . $keyValue . ' is already published in ' . $scopeRef . '/' . $locale
                . ' by record ' . $recordId . '.',
            );
        }
    }

    /**
     * Whether a key is free, for a caller that wants an answer rather than an
     * exception.
     *
     * @phpstan-impure
     */
    public function isAvailable(
        string $schemaId,
        string $scopeRef,
        string $locale,
        string $keyField,
        string $keyValue,
        ?string $exceptRecordId = null,
    ): bool {
        try {
            $this->assertAvailable($schemaId, $scopeRef, $locale, $keyField, $keyValue, $exceptRecordId);

            return true;
        } catch (ReadContractRejected $rejection) {
            if ($rejection->reasonCode !== 'slug_conflict') {
                throw $rejection;
            }

            return false;
        }
    }
}
