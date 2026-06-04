<?php

declare(strict_types=1);

namespace Larena\Storage\Enums;

enum StorageDecisionStatus: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case MissingSchema = 'missing_schema';
    case MissingAccessScope = 'missing_access_scope';
    case InvalidPayload = 'invalid_payload';
    case CapabilityLimited = 'capability_limited';

    public function permitsDataAccess(): bool
    {
        return $this === self::Allowed;
    }
}
