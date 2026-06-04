<?php

declare(strict_types=1);

use Larena\Storage\Contracts\StorageSchema;
use Larena\Storage\Enums\FieldVisibility;

require_once __DIR__ . '/../../vendor/autoload.php';

$contract = new ReflectionClass(StorageSchema::class);

foreach (['id', 'version', 'accessPolicyRef', 'persistenceProfile', 'fields'] as $method) {
    if (!$contract->hasMethod($method)) {
        fwrite(STDERR, "StorageSchema is missing {$method}().\n");
        exit(1);
    }
}

if (!FieldVisibility::Encrypted->requiresProtectedProjection()) {
    fwrite(STDERR, "Encrypted storage fields must require protected projection.\n");
    exit(1);
}

if (!FieldVisibility::Hidden->requiresProtectedProjection()) {
    fwrite(STDERR, "Hidden storage fields must require protected projection.\n");
    exit(1);
}

echo "StorageSchemaContractTest passed.\n";
