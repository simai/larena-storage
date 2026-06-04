<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

enum MutationType: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Restore = 'restore';
}
