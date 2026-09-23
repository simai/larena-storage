<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StructureRole;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Exceptions\StructureRoleRejected;

/**
 * The five roles the platform ships with.
 *
 * Installation is idempotent and never edits an existing row: if a starter role
 * needs to change, it becomes a new `role_version`, so a structure already bound
 * to version 1 keeps the contract it was validated against. That is the whole
 * reason roles are versioned.
 */
final class StarterStructureRoles
{
    public const OWNER_PACKAGE = 'larena/storage';

    public const SITE_NODE = 'site_node';

    public const REDIRECT = 'redirect';

    public const DOC_SPACE = 'doc_space';

    public const DOC_PAGE = 'doc_page';

    public const ORG_CHART = 'org_chart';

    public function __construct(private readonly StructureRoleRegistry $registry)
    {
    }

    /**
     * @return list<StructureRole>
     */
    public static function roles(): array
    {
        return [
            new StructureRole(
                roleCode: self::SITE_NODE,
                roleVersion: 1,
                title: 'Site node',
                requiredFields: [
                    ['key' => 'slug', 'type' => 'string'],
                    ['key' => 'title', 'type' => 'string'],
                    ['key' => 'order_index', 'type' => 'integer'],
                    ['key' => 'target', 'type' => 'string'],
                ],
                optionalFields: [
                    ['key' => 'description', 'type' => 'string'],
                    ['key' => 'visible_in_menu', 'type' => 'boolean'],
                ],
                requiredRelations: [
                    ['relation_key' => 'site_tree_parent', 'kind' => 'tree_parent', 'target_role_code' => self::SITE_NODE],
                ],
                lifecycle: RoleLifecycle::Publishable,
                ownerPackage: self::OWNER_PACKAGE,
            ),
            new StructureRole(
                roleCode: self::REDIRECT,
                roleVersion: 1,
                title: 'Redirect',
                requiredFields: [
                    ['key' => 'from_path', 'type' => 'string'],
                    ['key' => 'to_target', 'type' => 'string'],
                    ['key' => 'status_code', 'type' => 'integer'],
                ],
                optionalFields: [],
                requiredRelations: [],
                // A redirect answers immediately or not at all; a draft redirect is
                // a contradiction, so it has no publication lifecycle.
                lifecycle: RoleLifecycle::Plain,
                ownerPackage: self::OWNER_PACKAGE,
            ),
            new StructureRole(
                roleCode: self::DOC_SPACE,
                roleVersion: 1,
                title: 'Documentation space',
                requiredFields: [
                    ['key' => 'slug', 'type' => 'string'],
                    ['key' => 'title', 'type' => 'string'],
                ],
                optionalFields: [
                    ['key' => 'description', 'type' => 'string'],
                ],
                requiredRelations: [],
                lifecycle: RoleLifecycle::Publishable,
                ownerPackage: self::OWNER_PACKAGE,
            ),
            new StructureRole(
                roleCode: self::DOC_PAGE,
                roleVersion: 1,
                title: 'Documentation page',
                requiredFields: [
                    ['key' => 'slug', 'type' => 'string'],
                    ['key' => 'title', 'type' => 'string'],
                    ['key' => 'body', 'type' => 'string'],
                    ['key' => 'order_index', 'type' => 'integer'],
                ],
                optionalFields: [],
                requiredRelations: [
                    ['relation_key' => 'doc_tree_parent', 'kind' => 'tree_parent', 'target_role_code' => self::DOC_PAGE],
                    ['relation_key' => 'doc_space_ref', 'kind' => 'reference', 'target_role_code' => self::DOC_SPACE],
                ],
                lifecycle: RoleLifecycle::Publishable,
                ownerPackage: self::OWNER_PACKAGE,
            ),
            new StructureRole(
                roleCode: self::ORG_CHART,
                roleVersion: 1,
                title: 'Organisation chart overlay',
                requiredFields: [
                    ['key' => 'core_plane_node_id', 'type' => 'string'],
                    ['key' => 'title', 'type' => 'string'],
                ],
                optionalFields: [
                    ['key' => 'headcount', 'type' => 'integer'],
                ],
                // An overlay: it points at a core plane node and duplicates neither
                // the membership nor the hierarchy, which stay in core.
                requiredRelations: [],
                lifecycle: RoleLifecycle::Plain,
                ownerPackage: self::OWNER_PACKAGE,
            ),
        ];
    }

    /**
     * @return array<string, mixed> what the install did, per role
     * @phpstan-impure
     */
    public function install(string $actorId, ?string $correlationId = null): array
    {
        $installed = [];
        $alreadyPresent = [];

        foreach (self::roles() as $role) {
            if ($this->registry->read($role->ref()) !== null) {
                $alreadyPresent[] = $role->ref();

                continue;
            }

            try {
                $this->registry->register($role, $actorId, $correlationId);
                $installed[] = $role->ref();
            } catch (StructureRoleRejected $rejection) {
                if ($rejection->reasonCode !== 'duplicate_role_version') {
                    throw $rejection;
                }

                // Another process installed it between the read and the write.
                // Idempotent means idempotent under concurrency too.
                $alreadyPresent[] = $role->ref();
            }
        }

        return [
            'installed' => $installed,
            'already_present' => $alreadyPresent,
            'role_count' => count(self::roles()),
        ];
    }
}
