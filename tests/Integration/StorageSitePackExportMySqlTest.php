<?php

declare(strict_types=1);

use Larena\Storage\View\StorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/mysql-disposable-database.php';
require_once __DIR__ . '/../Support/storage-sitepack-fixture.php';

// The storage SitePack export scenario on a throwaway MySQL database (opt-in), dropped when the test ends.

$optIn = getenv('LARENA_STORAGE_SITEPACK_MYSQL_TEST');
if (!is_string($optIn) || !filter_var($optIn, FILTER_VALIDATE_BOOL)) {
    echo "StorageSitePackExportMySqlTest skipped (explicit opt-in required).\n";
    exit(0);
}

$database = larena_storage_mysql_disposable_database('larena_storage_sitepack_test_');
try {
    $connection = larena_storage_mysql_connect($database['config']);
    sitePackScenario($connection, StorageViewDdl::for($connection), 'mysql');
} finally {
    ($database['drop'])();
}

echo "Storage SitePack export on MySQL passed.\n";
