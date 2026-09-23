<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface StructureRoleRegistry
{
    /**
     * Register a versioned role. Registering the same code and version twice is
     * refused: a role row is never edited, and a breaking change is a new version.
     */
    public function register(StructureRole $role, string $actorId, ?string $correlationId = null): StructureRole;

    public function read(string $roleRef): ?StructureRole;

    /**
     * @return list<StructureRole>
     */
    public function list(?string $ownerPackage = null): array;

    /**
     * Check a structure against a role without binding it.
     *
     * @param list<array<string, mixed>> $fields the schema's field descriptors
     * @param list<string> $declaredRelationKeys the relation keys the schema declares
     */
    public function validateStructure(
        string $roleRef,
        string $schemaId,
        array $fields,
        array $declaredRelationKeys = [],
        ?string $schemaLifecycle = null,
    ): StructureRoleConformanceReport;

    /**
     * Bind a conforming structure to a role inside a scope. A non-conforming
     * structure is refused and nothing is written.
     *
     * @param list<array<string, mixed>> $fields
     * @param list<string> $declaredRelationKeys
     */
    public function bindStructure(
        string $roleRef,
        string $schemaId,
        string $scopeRef,
        array $fields,
        string $actorId,
        array $declaredRelationKeys = [],
        ?string $schemaLifecycle = null,
        ?string $correlationId = null,
    ): StructureRoleBinding;

    /**
     * @return list<StructureRoleBinding>
     */
    public function listStructures(string $scopeRef, ?string $roleRef = null): array;

    /**
     * @return array<string, mixed>
     */
    public function explain(string $roleRef): array;
}
