<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

use Larena\Storage\Admin\Contracts\StorageStructureSearch;

/** Without a storage host there are no storages to find. */
final class UnavailableStorageStructureSearch implements StorageStructureSearch
{
    public function searchStructures(string $actor, ?string $requestedScope, string $search, int $limit): array
    {
        return [];
    }
}
