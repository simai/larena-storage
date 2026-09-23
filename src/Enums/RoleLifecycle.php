<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

/**
 * Whether a role's records have a publication lifecycle at all.
 *
 * A `plain` role is saved and read. A `publishable` role additionally has a
 * published head per scope and locale, which is what a site node or a
 * documentation page needs and what a redirect does not.
 */
enum RoleLifecycle: string
{
    case Plain = 'plain';
    case Publishable = 'publishable';
}
