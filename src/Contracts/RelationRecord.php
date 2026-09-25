<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Enums\RelationDeletePolicy;
use Larena\Storage\Enums\RelationKind;
use Larena\Storage\Enums\RelationStatus;

/**
 * One stored relation edge.
 *
 * For a tree edge `fromRecordId` is the child and `toRecordId` is the parent, so
 * reading children means querying by parent. The direction is stated here because
 * getting it backwards is the easiest mistake to make with this table.
 */
final readonly class RelationRecord
{
    public function __construct(
        public string $relationId,
        public string $relationKey,
        public string $schemaId,
        public string $fromRecordId,
        public string $toRecordId,
        public RelationKind $kind,
        public RelationDeletePolicy $deletePolicy,
        public RelationStatus $status,
        public ?string $path = null,
        public int $depth = 0,
        public int $orderIndex = 0,
        public ?string $createdBy = null,
        public ?string $correlationId = null,
    ) {
    }

    public static function identity(string $relationKey, string $fromRecordId, string $toRecordId): string
    {
        return $relationKey . '|' . $fromRecordId . '|' . $toRecordId;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'relation_id' => $this->relationId,
            'relation_key' => $this->relationKey,
            'schema_id' => $this->schemaId,
            'from_record_id' => $this->fromRecordId,
            'to_record_id' => $this->toRecordId,
            'kind' => $this->kind->value,
            'delete_policy' => $this->deletePolicy->value,
            'status' => $this->status->value,
            'path' => $this->path,
            'depth' => $this->depth,
            'order_index' => $this->orderIndex,
        ];
    }
}
