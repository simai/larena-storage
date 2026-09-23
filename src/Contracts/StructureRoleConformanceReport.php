<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Why a structure does or does not satisfy a role.
 *
 * Every failure is named. "Does not conform" sends someone to read two files;
 * "missing slug, and title is a text where the role wants a string" sends them to
 * one line.
 */
final readonly class StructureRoleConformanceReport
{
    /**
     * @param list<string> $missingFields
     * @param list<array{key: string, expected: string, actual: string}> $typeMismatches
     * @param list<string> $missingRelations
     */
    public function __construct(
        public string $roleRef,
        public string $schemaId,
        public bool $conforms,
        public array $missingFields = [],
        public array $typeMismatches = [],
        public array $missingRelations = [],
        public ?string $lifecycleMismatch = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role_ref' => $this->roleRef,
            'schema_id' => $this->schemaId,
            'conforms' => $this->conforms,
            'missing_fields' => $this->missingFields,
            'type_mismatches' => $this->typeMismatches,
            'missing_relations' => $this->missingRelations,
            'lifecycle_mismatch' => $this->lifecycleMismatch,
        ];
    }
}
