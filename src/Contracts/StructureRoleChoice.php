<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Which storage answers a role in a scope where several are bound (a site's «Дерево страниц», decision 13). The
 * product composition binds it over whatever keeps the choice; without it, or for a storage no longer bound, the first
 * by schema id answers.
 */
interface StructureRoleChoice
{
    /** The schema chosen for the role in the scope, or null when nothing is chosen. */
    public function chosenSchema(string $roleRef, string $scopeRef): ?string;
}
