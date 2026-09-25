<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

enum RelationStatus: string
{
    case Active = 'active';
    case Detached = 'detached';
}
