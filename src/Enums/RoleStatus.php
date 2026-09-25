<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

/**
 * A role row is never edited. A breaking change is a new version, and the old
 * version becomes superseded so that structures bound to it keep working.
 */
enum RoleStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
}
