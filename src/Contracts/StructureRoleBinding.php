<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Enums\RoleBindingStatus;

/**
 * One structure playing one role inside one scope.
 *
 * The scope is part of the identity on purpose: the same schema may be the site
 * tree of one site and play no part in another.
 */
final readonly class StructureRoleBinding
{
    public const ID_MAX_LENGTH = 190;

    public function __construct(
        public string $bindingId,
        public string $roleRef,
        public string $schemaId,
        public string $scopeRef,
        public RoleBindingStatus $status,
        public string $boundBy,
        public ?string $conformanceCheckedAt = null,
        public ?string $correlationId = null,
    ) {
    }

    public static function identity(string $roleRef, string $schemaId, string $scopeRef): string
    {
        return $roleRef . '|' . $schemaId . '|' . $scopeRef;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'binding_id' => $this->bindingId,
            'role_ref' => $this->roleRef,
            'schema_id' => $this->schemaId,
            'scope_ref' => $this->scopeRef,
            'status' => $this->status->value,
            'bound_by' => $this->boundBy,
            'conformance_checked_at' => $this->conformanceCheckedAt,
        ];
    }
}
