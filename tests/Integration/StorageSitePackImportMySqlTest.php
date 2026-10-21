<?php

declare(strict_types=1);

use Larena\Storage\View\StorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/mysql-disposable-database.php';
require_once __DIR__ . '/../Support/storage-sitepack-import-fixture.php';

// The storage SitePack import scenario on throwaway MySQL databases (opt-in): the source and a fresh installation,
// both dropped when the test ends.

$optIn = getenv('LARENA_STORAGE_SITEPACK_MYSQL_TEST');
if (!is_string($optIn) || !filter_var($optIn, FILTER_VALIDATE_BOOL)) {
    echo "StorageSitePackImportMySqlTest skipped (explicit opt-in required).\n";
    exit(0);
}

$database = larena_storage_mysql_disposable_database('larena_storage_sitepack_import_test_');
$fresh = larena_storage_mysql_disposable_database('larena_storage_sitepack_fresh_test_');
try {
    $connection = larena_storage_mysql_connect($database['config']);
    sitePackImportScenario($connection, StorageViewDdl::for($connection), 'mysql', static function () use ($fresh): array {
        $other = larena_storage_mysql_connect($fresh['config']);

        return ['connection' => $other, 'ddl' => StorageViewDdl::for($other), 'close' => static fn () => $other->disconnect()];
    });
} finally {
    ($fresh['drop'])();
    ($database['drop'])();
}

echo "Storage SitePack import on MySQL passed.\n";
