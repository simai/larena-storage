<?php

declare(strict_types=1);

use Larena\Storage\Enums\StorageDecisionStatus;

require_once __DIR__ . '/../../vendor/autoload.php';

foreach ([
    StorageDecisionStatus::Denied,
    StorageDecisionStatus::MissingSchema,
    StorageDecisionStatus::MissingAccessScope,
    StorageDecisionStatus::InvalidPayload,
] as $status) {
    if ($status->permitsDataAccess()) {
        fwrite(STDERR, "Storage decision {$status->value} must fail closed.\n");
        exit(1);
    }
}

if (!StorageDecisionStatus::Allowed->permitsDataAccess()) {
    fwrite(STDERR, "Allowed storage decision must permit data access.\n");
    exit(1);
}

echo "StorageFailsClosedTest passed.\n";
