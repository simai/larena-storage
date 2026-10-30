<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * A role's own rules for the records of the storages bound to it, beyond what the fields' types check: the workbench
 * asks it on every record create and update of such a storage, in the write's transaction, once per binding of the
 * role (a role played in two sites is asked twice). A storage bound to no role with a validator writes as before.
 *
 * validate() returns the values to write: the person's, normalized, or refused with a StorageRejected reason code
 * the admin shows. written() follows the write in the same transaction: it may write other records of the role
 * through the workbench it is given (a rule rewritten to keep a chain short); a failure there rolls the write back.
 */
interface StructureRoleRecordValidator
{
    /** The role whose bound storages it checks, as bindings name it (site_redirect@v1). */
    public function roleRef(): string;

    /**
     * @return array<string, mixed>
     *
     * @throws StorageRejected
     */
    public function validate(StructureRoleRecordWrite $write): array;

    public function written(StructureRoleRecordWrite $write, StorageWorkbenchRecord $record, StorageWorkbench $workbench): void;
}
