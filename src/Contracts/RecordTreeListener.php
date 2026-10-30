<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\RelationRejected;

/**
 * What hears a tree's edges change: a role that gives a place in a tree a meaning (a site page's address follows it).
 * placing() comes before the change and may refuse it; placed() follows it, removed() follows an edge's removal.
 */
interface RecordTreeListener
{
    /** @throws RelationRejected */
    public function placing(string $relationKey, string $schemaId, string $recordId, ?string $newParentRecordId): void;

    public function placed(string $relationKey, string $schemaId, string $recordId, ?string $newParentRecordId, string $actor, ?string $correlationId): void;

    public function removed(string $relationKey, string $schemaId): void;
}
