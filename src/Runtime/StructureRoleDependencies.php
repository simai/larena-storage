<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StructureRole;
use Larena\Storage\Contracts\StructureRoleBinding;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Exceptions\StructureRoleRejected;

/**
 * Which roles each consumer depends on.
 *
 * A package that reads structures by role declares the role reference once, at
 * boot. It then receives the bound structures of a scope — the same answer as
 * storage.role.list_structures — only for a role it declared, and a scope can be
 * asked which declared roles nothing satisfies yet.
 */
final class StructureRoleDependencies
{
    /** @var array<string, list<string>> role references by consumer */
    private array $declared = [];

    public function __construct(private readonly StructureRoleRegistry $roles)
    {
    }

    public function declare(string $consumer, string $roleRef): void
    {
        if (trim($consumer) === '' || preg_match('/^[a-z][a-z0-9_]{0,118}@v[1-9][0-9]*$/', $roleRef) !== 1) {
            throw new StructureRoleRejected('invalid_input', 'A role dependency names a consumer and a versioned role reference (code@v1).');
        }
        if (!in_array($roleRef, $this->declared[$consumer] ?? [], true)) {
            $this->declared[$consumer][] = $roleRef;
        }
    }

    /** @return array<string, list<string>> */
    public function declared(): array
    {
        return $this->declared;
    }

    /**
     * @return list<StructureRoleBinding>
     * @phpstan-impure
     */
    public function structuresFor(string $consumer, string $roleRef, string $scopeRef): array
    {
        if (!in_array($roleRef, $this->declared[$consumer] ?? [], true)) {
            throw new StructureRoleRejected('role_dependency_undeclared', $consumer . ' has not declared a dependency on ' . $roleRef . '.');
        }

        return $this->roles->listStructures($scopeRef, $roleRef);
    }

    /**
     * Declared roles that are unknown or have no active binding in the scope.
     *
     * @return list<array{consumer: string, role_ref: string, reason: string}>
     * @phpstan-impure
     */
    public function unsatisfied(string $scopeRef): array
    {
        $missing = [];
        foreach ($this->declared as $consumer => $roleRefs) {
            foreach ($roleRefs as $roleRef) {
                if (!$this->roles->read($roleRef) instanceof StructureRole) {
                    $missing[] = ['consumer' => $consumer, 'role_ref' => $roleRef, 'reason' => 'unknown_role'];
                } elseif ($this->roles->listStructures($scopeRef, $roleRef) === []) {
                    $missing[] = ['consumer' => $consumer, 'role_ref' => $roleRef, 'reason' => 'no_bound_structure'];
                }
            }
        }

        return $missing;
    }
}
