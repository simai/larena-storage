<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use RuntimeException;

/**
 * Whether a person still may import into a storage, in the scope they started a job in: the check both import
 * jobs (storage.import.plan and storage.import.apply) make before reading or writing anything.
 */
final readonly class StorageImportRights
{
    public function __construct(
        private StorageWorkbenchGateway $gateway,
        private StorageSectionScope $scopes,
    ) {
    }

    /**
     * The person's import rights in this storage, or the queue's reason why there are none.
     *
     * @return array<string, bool>|string
     */
    public function of(string $actor, string $scope, string $structureId): array|string
    {
        try {
            if (!hash_equals($this->scopes->scopeForActor($actor), $scope)) {
                return 'storage_import_scope_changed';
            }
        } catch (RuntimeException) {
            return 'storage_import_denied';
        }
        $state = $this->gateway->snapshot($actor, $scope, ['surface' => 'storage_section', 'structure_id' => $structureId, 'records' => 'skip']);
        if (($state['status'] ?? null) !== 'ok') {
            return in_array($state['status'] ?? null, ['denied', 'rejected'], true) ? 'storage_import_denied' : 'storage_import_unavailable';
        }
        $selected = is_array($state['selected_structure'] ?? null) ? $state['selected_structure'] : [];
        if (($selected['structure_id'] ?? null) !== $structureId || ($selected['archived'] ?? false) === true) {
            return 'storage_import_denied';
        }
        $permissions = [];
        foreach (is_array($state['permissions'] ?? null) ? $state['permissions'] : [] as $key => $allowed) {
            if (is_string($key)) {
                $permissions[$key] = $allowed === true;
            }
        }
        if (($permissions['record.create'] ?? false) !== true && ($permissions['record.update'] ?? false) !== true) {
            return 'storage_import_denied';
        }

        return $permissions;
    }
}
