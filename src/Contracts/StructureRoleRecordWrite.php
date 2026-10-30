<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * One record write a role checks (StructureRoleRecordValidator): the role and the scope it is played in (the binding's,
 * a site for a site role), the storage written (its workbench scope, structure and schema), the record (null while it
 * is created) and the person's values, reserved fields aside. With a locale the values are that language's translations
 * alone; without one they are the shared values.
 */
final readonly class StructureRoleRecordWrite
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public string $roleRef,
        public string $roleScopeRef,
        public string $scopeRef,
        public string $structureId,
        public string $schemaId,
        public ?string $recordId,
        public array $values,
        public string $actor,
        public ?string $correlationId = null,
        public ?string $locale = null,
    ) {
    }

    /** @param array<string, mixed> $values */
    public function withValues(array $values): self
    {
        return new self($this->roleRef, $this->roleScopeRef, $this->scopeRef, $this->structureId, $this->schemaId,
            $this->recordId, $values, $this->actor, $this->correlationId, $this->locale);
    }
}
