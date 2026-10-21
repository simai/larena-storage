<?php

declare(strict_types=1);

use Larena\Storage\View\StorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/mysql-disposable-database.php';
require_once __DIR__ . '/../Support/storage-encryption-run-fixture.php';

// The encryption run scenario on a throwaway MySQL database (opt-in; a few dozen rows), dropped when the test ends:
// enable, resume, retry, mutual exclusion, disable, rotate and rewrap with MySQL's row locks and DDL outside
// transactions.

$optIn = getenv('LARENA_STORAGE_ENCRYPTION_MYSQL_TEST');
if (!is_string($optIn) || !filter_var($optIn, FILTER_VALIDATE_BOOL)) {
    echo "StorageFieldEncryptionRunMySqlTest skipped (explicit opt-in required).\n";
    exit(0);
}

$database = larena_storage_mysql_disposable_database('larena_storage_encryption_run_test_');
try {
    $connection = larena_storage_mysql_connect($database['config']);
    storageEncryptionRunScenario($connection, StorageViewDdl::for($connection), 'mysql', false);
} finally {
    ($database['drop'])();
}

echo "Storage field encryption runs on MySQL passed.\n";
