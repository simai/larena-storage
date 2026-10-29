<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Contracts;

/** Finds records by text across the storages of the actor's scope, for the admin search. */
interface StorageRecordSearch
{
    /**
     * At most $limit active records the actor may list, in storage order; archived storages are left out.
     *
     * @return list<array{structure_id: string, structure_label: string, record_id: string, title: string}>
     */
    public function searchRecords(string $actor, ?string $requestedScope, string $search, int $limit): array;
}
