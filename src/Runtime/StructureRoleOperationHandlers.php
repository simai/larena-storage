<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Storage\Audit\StructureRoleAuditEventCatalog;
use Larena\Storage\Contracts\StructureRole;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Exceptions\StructureRoleRejected;

/**
 * The five structure role operations.
 *
 * It implements the proposal port as well as the plain handler port, so an actor
 * — human or AI — can ask what a registration or a binding would do before doing
 * it, through the same path that performs it.
 */
final readonly class StructureRoleOperationHandlers implements OperationProposalHandler
{
    public function __construct(private StructureRoleRegistry $registry)
    {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $manage = 'storage.role.manage';
        $read = 'storage.role.read';

        $descriptors = [
            new OperationDescriptor(
                name: 'storage.role.register',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $manage,
                auditEvent: StructureRoleAuditEventCatalog::REGISTERED,
                idempotencyKey: 'role_ref',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.role.validate_structure',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.role.bind_structure',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $manage,
                auditEvent: StructureRoleAuditEventCatalog::BOUND,
                idempotencyKey: 'binding_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.role.list_structures',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.role.explain',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
        ];

        $indexed = [];
        foreach ($descriptors as $descriptor) {
            $indexed[$descriptor->name] = $descriptor;
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'storage.role.register' => ['role' => $this->register($context)->toArray()],
            'storage.role.validate_structure' => ['report' => $this->validate($context)->toArray()],
            'storage.role.bind_structure' => ['binding' => $this->bind($context)->toArray()],
            'storage.role.list_structures' => $this->listStructures($context),
            'storage.role.explain' => $this->registry->explain($this->string($context, 'role_ref')),
            default => throw new StructureRoleRejected(
                'unknown_operation',
                sprintf('Operation "%s" is not a structure role operation.', $descriptor->name),
            ),
        };
    }

    /**
     * Describe the change without making it.
     *
     * For a registration this is the role that would be written; for a binding it
     * is the conformance report, which is exactly what an editor needs to see
     * before binding.
     *
     * @return array<string, mixed>
     */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'storage.role.register' => [
                'kind' => 'register_role',
                'role' => $this->role($context)->toArray(),
                'already_present' => $this->registry->read($this->role($context)->ref()) !== null,
            ],
            'storage.role.bind_structure' => [
                'kind' => 'bind_structure',
                'report' => $this->validate($context)->toArray(),
            ],
            default => throw new StructureRoleRejected(
                'proposal_unsupported',
                sprintf('Operation "%s" has nothing to propose.', $descriptor->name),
            ),
        };
    }

    private function register(OperationContext $context): StructureRole
    {
        return $this->registry->register(
            $this->role($context),
            $context->actorId,
            $context->correlationId,
        );
    }

    private function role(OperationContext $context): StructureRole
    {
        $lifecycle = RoleLifecycle::tryFrom($this->string($context, 'lifecycle'))
            ?? throw new StructureRoleRejected('invalid_input', 'Unknown role lifecycle.');

        return new StructureRole(
            roleCode: $this->string($context, 'role_code'),
            roleVersion: $this->int($context, 'role_version'),
            title: $this->string($context, 'title'),
            requiredFields: $this->fieldEntries($context, 'required_fields'),
            optionalFields: $this->fieldEntries($context, 'optional_fields'),
            requiredRelations: $this->relationEntries($context, 'required_relations'),
            lifecycle: $lifecycle,
            ownerPackage: $this->string($context, 'owner_package'),
        );
    }

    private function validate(OperationContext $context): \Larena\Storage\Contracts\StructureRoleConformanceReport
    {
        return $this->registry->validateStructure(
            $this->string($context, 'role_ref'),
            $this->string($context, 'schema_id'),
            $this->rawList($context, 'fields'),
            $this->stringList($context, 'declared_relation_keys'),
            $this->optionalString($context, 'schema_lifecycle'),
        );
    }

    private function bind(OperationContext $context): \Larena\Storage\Contracts\StructureRoleBinding
    {
        return $this->registry->bindStructure(
            $this->string($context, 'role_ref'),
            $this->string($context, 'schema_id'),
            $this->string($context, 'scope_ref'),
            $this->rawList($context, 'fields'),
            $context->actorId,
            $this->stringList($context, 'declared_relation_keys'),
            $this->optionalString($context, 'schema_lifecycle'),
            $context->correlationId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function listStructures(OperationContext $context): array
    {
        $bindings = $this->registry->listStructures(
            $this->string($context, 'scope_ref'),
            $this->optionalString($context, 'role_ref'),
        );

        return [
            'bindings' => array_map(
                static fn ($binding): array => $binding->toArray(),
                $bindings,
            ),
        ];
    }

    /**
     * @return list<array{key: string, type: string}>
     */
    private function fieldEntries(OperationContext $context, string $key): array
    {
        $entries = [];
        foreach ($this->rawList($context, $key) as $entry) {
            if (!is_string($entry['key'] ?? null) || !is_string($entry['type'] ?? null)) {
                throw new StructureRoleRejected('invalid_input', 'Every field entry needs a key and a type.');
            }

            $entries[] = ['key' => $entry['key'], 'type' => $entry['type']];
        }

        return $entries;
    }

    /**
     * @return list<array{relation_key: string, kind: string, target_role_code: string}>
     */
    private function relationEntries(OperationContext $context, string $key): array
    {
        $entries = [];
        foreach ($this->rawList($context, $key) as $entry) {
            if (!is_string($entry['relation_key'] ?? null)
                || !is_string($entry['kind'] ?? null)
                || !is_string($entry['target_role_code'] ?? null)) {
                throw new StructureRoleRejected(
                    'invalid_input',
                    'Every relation entry needs a relation_key, a kind and a target_role_code.',
                );
            }

            $entries[] = [
                'relation_key' => $entry['relation_key'],
                'kind' => $entry['kind'],
                'target_role_code' => $entry['target_role_code'],
            ];
        }

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawList(OperationContext $context, string $key): array
    {
        $value = $context->metadata[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new StructureRoleRejected('invalid_input', sprintf('"%s" must be a list.', $key));
        }

        $entries = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new StructureRoleRejected('invalid_input', sprintf('Every member of "%s" must be a mapping.', $key));
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function stringList(OperationContext $context, string $key): array
    {
        $value = $context->metadata[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new StructureRoleRejected('invalid_input', sprintf('"%s" must be a list.', $key));
        }

        $entries = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new StructureRoleRejected('invalid_input', sprintf('Every member of "%s" must be a string.', $key));
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new StructureRoleRejected('invalid_input', sprintf('Structure role operations require a non-empty "%s".', $key));
        }

        return $value;
    }

    private function optionalString(OperationContext $context, string $key): ?string
    {
        $value = $context->metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function int(OperationContext $context, string $key): int
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_int($value)) {
            throw new StructureRoleRejected('invalid_input', sprintf('Structure role operations require an integer "%s".', $key));
        }

        return $value;
    }
}
