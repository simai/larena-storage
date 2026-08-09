<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use InvalidArgumentException;

final class ScopedStorageWorkbenchSchemaIdentity
{
    private const PREFIX = 'workbench.scoped.v1.';
    private const DOMAIN = "larena.storage.workbench.schema.v1\0";

    public static function derive(string $scopeRef, string $structureId): string
    {
        if (preg_match('/^scope:[a-z][a-z0-9_.:-]{0,184}$/', $scopeRef) !== 1) {
            throw new InvalidArgumentException('storage_workbench_scope_ref_invalid');
        }
        if (preg_match('/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/', $structureId) !== 1) {
            throw new InvalidArgumentException('storage_workbench_structure_id_invalid');
        }

        $identity = self::DOMAIN
            . strlen($scopeRef) . ':' . $scopeRef
            . strlen($structureId) . ':' . $structureId;

        return self::PREFIX . hash('sha256', $identity);
    }
}
