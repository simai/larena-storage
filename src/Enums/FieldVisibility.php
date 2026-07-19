<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

enum FieldVisibility: string
{
    case Public = 'public';
    case Admin = 'admin';
    case Protected = 'protected';
    case Hidden = 'hidden';
    case Encrypted = 'encrypted';

    public function requiresProtectedProjection(): bool
    {
        return in_array($this, [self::Protected, self::Admin, self::Hidden, self::Encrypted], true);
    }
}
