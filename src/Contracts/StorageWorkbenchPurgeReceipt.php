<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/** What a permanent deletion removed. It carries identifiers and counts, never values. */
final readonly class StorageWorkbenchPurgeReceipt
{
    /** @param list<string> $recordIds */
    public function __construct(
        public string $structureId,
        public string $scopeRef,
        public array $recordIds,
        public int $versionCount,
        public bool $structurePurged,
        public string $actor,
        public string $correlationId,
        public string $purgedAt,
    ) {
    }
}
