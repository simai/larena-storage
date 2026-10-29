<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

use Larena\Storage\Admin\Contracts\StorageRecordSearch;

/** Without a storage host there are no records to find. */
final class UnavailableStorageRecordSearch implements StorageRecordSearch
{
    public function searchRecords(string $actor, ?string $requestedScope, string $search, int $limit): array
    {
        return [];
    }
}
