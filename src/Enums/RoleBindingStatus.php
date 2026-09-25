<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

enum RoleBindingStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
