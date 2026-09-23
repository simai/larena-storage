<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\Connection;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Storage\Audit\StructureRoleAuditEventCatalog;
use Larena\Storage\Contracts\StructureRole;
use Larena\Storage\Contracts\StructureRoleBinding;
use Larena\Storage\Contracts\StructureRoleConformanceReport;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Enums\RoleBindingStatus;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Enums\RoleStatus;
use Larena\Storage\Exceptions\StructureRoleRejected;

/**
 * Structure roles and bindings on the database.
 *
 * Two rules do most of the work here. A role row is written once and never
 * edited, so a structure bound to a role cannot have the contract changed under
 * it. And binding runs conformance first and writes nothing when it fails,
 * because a binding that does not hold is worse than no binding: every consumer
 * of `list_structures` would trust it.
 */
final class DatabaseStructureRoleRegistry implements StructureRoleRegistry
{
    public const ROLES_TABLE = 'larena_storage_structure_roles';

    public const BINDINGS_TABLE = 'larena_storage_structure_role_bindings';

    public function __construct(
        private readonly Connection $connection,
        private readonly ?ScopeRefResolver $scopes = null,
    ) {
    }

    /** @phpstan-impure */
    public function register(StructureRole $role, string $actorId, ?string $correlationId = null): StructureRole
    {
        $this->assertSchema();

        $existing = $this->connection->table(self::ROLES_TABLE)->where('role_ref', $role->ref())->first();
        if ($existing !== null) {
            throw new StructureRoleRejected(
                'duplicate_role_version',
                'Role ' . $role->ref() . ' already exists; a change needs a new role version.',
            );
        }

        $now = $this->now();

        $this->connection->table(self::ROLES_TABLE)->insert([
            'role_ref' => $role->ref(),
            'role_code' => $role->roleCode,
            'role_version' => $role->roleVersion,
            'title' => $role->title,
            'required_fields' => $this->encode($role->requiredFields),
            'optional_fields' => $this->encode($role->optionalFields),
            'required_relations' => $this->encode($role->requiredRelations),
            'lifecycle' => $role->lifecycle->value,
            'owner_package' => $role->ownerPackage,
            'status' => $role->status->value,
            'created_by' => $actorId,
            'correlation_id' => $correlationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $role;
    }

    /** @phpstan-impure */
    public function read(string $roleRef): ?StructureRole
    {
        $this->assertSchema();

        $row = $this->connection->table(self::ROLES_TABLE)->where('role_ref', $roleRef)->first();

        return $row === null ? null : $this->hydrate((array) $row);
    }

    /**
     * @return list<StructureRole>
     * @phpstan-impure
     */
    public function list(?string $ownerPackage = null): array
    {
        $this->assertSchema();

        $query = $this->connection->table(self::ROLES_TABLE)->orderBy('role_code')->orderBy('role_version');
        if ($ownerPackage !== null) {
            $query->where('owner_package', $ownerPackage);
        }

        $roles = [];
        foreach ($query->get() as $row) {
            $roles[] = $this->hydrate((array) $row);
        }

        return $roles;
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param list<string> $declaredRelationKeys
     * @phpstan-impure
     */
    public function validateStructure(
        string $roleRef,
        string $schemaId,
        array $fields,
        array $declaredRelationKeys = [],
        ?string $schemaLifecycle = null,
    ): StructureRoleConformanceReport {
        $role = $this->read($roleRef) ?? throw new StructureRoleRejected('unknown_role', 'Unknown role: ' . $roleRef);

        return $this->conformance($role, $schemaId, $fields, $declaredRelationKeys, $schemaLifecycle);
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param list<string> $declaredRelationKeys
     * @phpstan-impure
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
    ): StructureRoleBinding {
        $role = $this->read($roleRef) ?? throw new StructureRoleRejected('unknown_role', 'Unknown role: ' . $roleRef);

        // An unknown scope fails closed rather than creating a binding nobody can
        // reach. The resolver is optional so that a package test can run without
        // the core scope tables, and an absent resolver means the caller has taken
        // responsibility for the scope.
        if ($this->scopes !== null && $this->scopes->resolve($scopeRef) === null) {
            throw new StructureRoleRejected('unknown_scope', 'Unknown scope: ' . $scopeRef);
        }

        $report = $this->conformance($role, $schemaId, $fields, $declaredRelationKeys, $schemaLifecycle);
        if (!$report->conforms) {
            throw new StructureRoleRejected(
                'role_conformance_failed',
                'Structure ' . $schemaId . ' does not satisfy ' . $roleRef . ': ' . json_encode($report->toArray()),
            );
        }

        $bindingId = StructureRoleBinding::identity($roleRef, $schemaId, $scopeRef);
        if (strlen($bindingId) > StructureRoleBinding::ID_MAX_LENGTH) {
            // Rejected rather than truncated: a truncated identity would collide
            // with another binding and silently rebind a structure.
            throw new StructureRoleRejected('binding_identity_too_long', 'Binding identity exceeds 190 characters.');
        }

        $existing = $this->connection->table(self::BINDINGS_TABLE)->where('binding_id', $bindingId)->first();
        $now = $this->now();

        if ($existing !== null) {
            if ((string) $existing->status === RoleBindingStatus::Active->value) {
                throw new StructureRoleRejected('duplicate_binding', 'Structure is already bound to this role in this scope.');
            }

            $this->connection->table(self::BINDINGS_TABLE)->where('binding_id', $bindingId)->update([
                'status' => RoleBindingStatus::Active->value,
                'conformance_checked_at' => $now,
                'bound_by' => $actorId,
                'correlation_id' => $correlationId,
                'updated_at' => $now,
            ]);
        } else {
            $this->connection->table(self::BINDINGS_TABLE)->insert([
                'binding_id' => $bindingId,
                'role_ref' => $roleRef,
                'schema_id' => $schemaId,
                'scope_ref' => $scopeRef,
                'status' => RoleBindingStatus::Active->value,
                'conformance_checked_at' => $now,
                'bound_by' => $actorId,
                'correlation_id' => $correlationId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return new StructureRoleBinding(
            bindingId: $bindingId,
            roleRef: $roleRef,
            schemaId: $schemaId,
            scopeRef: $scopeRef,
            status: RoleBindingStatus::Active,
            boundBy: $actorId,
            conformanceCheckedAt: $now,
            correlationId: $correlationId,
        );
    }

    /**
     * @return list<StructureRoleBinding>
     * @phpstan-impure
     */
    public function listStructures(string $scopeRef, ?string $roleRef = null): array
    {
        $this->assertSchema();

        $query = $this->connection->table(self::BINDINGS_TABLE)
            ->where('scope_ref', $scopeRef)
            ->where('status', RoleBindingStatus::Active->value)
            ->orderBy('role_ref')
            ->orderBy('schema_id');

        if ($roleRef !== null) {
            $query->where('role_ref', $roleRef);
        }

        $bindings = [];
        foreach ($query->get() as $row) {
            $row = (array) $row;
            $bindings[] = new StructureRoleBinding(
                bindingId: (string) $row['binding_id'],
                roleRef: (string) $row['role_ref'],
                schemaId: (string) $row['schema_id'],
                scopeRef: (string) $row['scope_ref'],
                status: RoleBindingStatus::from((string) $row['status']),
                boundBy: (string) $row['bound_by'],
                conformanceCheckedAt: $row['conformance_checked_at'] === null ? null : (string) $row['conformance_checked_at'],
                correlationId: $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
            );
        }

        return $bindings;
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    public function explain(string $roleRef): array
    {
        $role = $this->read($roleRef);
        if ($role === null) {
            return ['role_ref' => $roleRef, 'exists' => false];
        }

        $bindingCount = $this->connection->table(self::BINDINGS_TABLE)
            ->where('role_ref', $roleRef)
            ->where('status', RoleBindingStatus::Active->value)
            ->count();

        return $role->toArray() + [
            'exists' => true,
            'active_binding_count' => $bindingCount,
            'audit_events' => StructureRoleAuditEventCatalog::all(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param list<string> $declaredRelationKeys
     */
    private function conformance(
        StructureRole $role,
        string $schemaId,
        array $fields,
        array $declaredRelationKeys,
        ?string $schemaLifecycle,
    ): StructureRoleConformanceReport {
        $byKey = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $byKey[$key] = $field;
            }
        }

        $missingFields = [];
        $typeMismatches = [];

        foreach ($role->requiredFields as $required) {
            $key = $required['key'];
            if (!isset($byKey[$key])) {
                $missingFields[] = $key;

                continue;
            }

            $actual = $byKey[$key]['type'] ?? null;
            if (!is_string($actual) || $actual !== $required['type']) {
                $typeMismatches[] = [
                    'key' => $key,
                    'expected' => $required['type'],
                    'actual' => is_string($actual) ? $actual : 'absent',
                ];
            }
        }

        $missingRelations = [];
        foreach ($role->requiredRelations as $relation) {
            if (!in_array($relation['relation_key'], $declaredRelationKeys, true)) {
                $missingRelations[] = $relation['relation_key'];
            }
        }

        $lifecycleMismatch = null;
        if ($role->isPublishable() && $schemaLifecycle !== RoleLifecycle::Publishable->value) {
            $lifecycleMismatch = 'role requires publishable, structure declares '
                . ($schemaLifecycle ?? 'nothing');
        }

        // A role is a floor, not a ceiling: extra fields on the structure are fine.
        $conforms = $missingFields === []
            && $typeMismatches === []
            && $missingRelations === []
            && $lifecycleMismatch === null;

        return new StructureRoleConformanceReport(
            roleRef: $role->ref(),
            schemaId: $schemaId,
            conforms: $conforms,
            missingFields: $missingFields,
            typeMismatches: $typeMismatches,
            missingRelations: $missingRelations,
            lifecycleMismatch: $lifecycleMismatch,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): StructureRole
    {
        return new StructureRole(
            roleCode: (string) $row['role_code'],
            roleVersion: (int) $row['role_version'],
            title: (string) $row['title'],
            requiredFields: $this->decodeFields($row['required_fields']),
            optionalFields: $this->decodeFields($row['optional_fields']),
            requiredRelations: $this->decodeRelations($row['required_relations']),
            lifecycle: RoleLifecycle::from((string) $row['lifecycle']),
            ownerPackage: (string) $row['owner_package'],
            status: RoleStatus::from((string) $row['status']),
        );
    }

    /**
     * @return list<array{key: string, type: string}>
     */
    private function decodeFields(mixed $encoded): array
    {
        $decoded = is_string($encoded) ? json_decode($encoded, true) : $encoded;
        if (!is_array($decoded)) {
            return [];
        }

        $fields = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_string($entry['key'] ?? null) && is_string($entry['type'] ?? null)) {
                $fields[] = ['key' => $entry['key'], 'type' => $entry['type']];
            }
        }

        return $fields;
    }

    /**
     * @return list<array{relation_key: string, kind: string, target_role_code: string}>
     */
    private function decodeRelations(mixed $encoded): array
    {
        $decoded = is_string($encoded) ? json_decode($encoded, true) : $encoded;
        if (!is_array($decoded)) {
            return [];
        }

        $relations = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)
                && is_string($entry['relation_key'] ?? null)
                && is_string($entry['kind'] ?? null)
                && is_string($entry['target_role_code'] ?? null)) {
                $relations[] = [
                    'relation_key' => $entry['relation_key'],
                    'kind' => $entry['kind'],
                    'target_role_code' => $entry['target_role_code'],
                ];
            }
        }

        return $relations;
    }

    /**
     * @param list<array<string, mixed>> $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @phpstan-impure */
    private function assertSchema(): void
    {
        if (!$this->connection->getSchemaBuilder()->hasTable(self::ROLES_TABLE)) {
            throw new StructureRoleRejected('schema_missing', 'Structure role tables are not migrated.');
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
