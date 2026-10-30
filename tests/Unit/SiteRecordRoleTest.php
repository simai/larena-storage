<?php

declare(strict_types=1);

use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Runtime\StarterStructureRoles;

require_once __DIR__ . '/../../vendor/autoload.php';

// A record with an address: the role asks for an address and a name, and goes through publication.
$roles = [];
foreach (StarterStructureRoles::roles() as $role) {
    $roles[$role->roleCode] = $role;
}
$record = $roles[StarterStructureRoles::SITE_RECORD] ?? null;
assert($record !== null);
assert(array_column($record->requiredFields, 'key') === ['slug', 'title']);
assert($record->lifecycle === RoleLifecycle::Publishable);

echo "Site record role tests passed.\n";
