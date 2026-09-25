<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use InvalidArgumentException;
use Larena\Storage\Enums\RelationDeletePolicy;
use Larena\Storage\Enums\RelationKind;

/**
 * What a schema declares about one relation it participates in.
 *
 * The delete policy lives here, in the versioned schema, rather than being passed
 * at delete time: the same delete must not behave differently depending on who
 * asks.
 */
final readonly class RelationDescriptor
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(
        public string $relationKey,
        public RelationKind $kind,
        public RelationDeletePolicy $deletePolicy,
        public ?string $targetSchemaId = null,
        public ?string $targetRoleCode = null,
    ) {
        if (preg_match(self::KEY_PATTERN, $this->relationKey) !== 1) {
            throw new InvalidArgumentException('Relation key is not a valid relation key: ' . $this->relationKey);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'relation_key' => $this->relationKey,
            'kind' => $this->kind->value,
            'delete_policy' => $this->deletePolicy->value,
            'target_schema_id' => $this->targetSchemaId,
            'target_role_code' => $this->targetRoleCode,
        ];
    }
}
