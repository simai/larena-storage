<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Contracts;

/** Finds storages by name or ID in the actor's scope, for the admin search. */
interface StorageStructureSearch
{
    /**
     * At most $limit storages the actor may list, in the storage order; archived ones are left out.
     *
     * @return list<array{structure_id: string, label: string, field_count: int}>
     */
    public function searchStructures(string $actor, ?string $requestedScope, string $search, int $limit): array;
}
