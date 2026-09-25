<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/structure-role-schema.php';

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Larena\Storage\Database\StorageOwnedTableShapeGuard;
use Larena\Storage\Database\StorageSchemaMigrationTableShapeGuard;

// A MariaDB server reached through Laravel's mysql driver reports MariaDB's
// types - a JSON column is LONGTEXT with a json_valid check - so both shape
// guards judge the connection as MariaDB. Without this every Storage migration
// failed on a real MariaDB instance.
final class MariaDbDriverDetectionConnection extends MySqlConnection
{
    public function __construct(private readonly bool $maria)
    {
        parent::__construct(static fn (): PDO => throw new LogicException('no database is opened'), 'larena_test', '', ['driver' => 'mysql']);
    }

    public function isMaria(): bool
    {
        return $this->maria;
    }
}

function maria_driver_of(object $guard): string
{
    $method = new ReflectionMethod($guard, 'normalizedDriver');

    return (string) $method->invoke($guard);
}

foreach ([StorageOwnedTableShapeGuard::class, StorageSchemaMigrationTableShapeGuard::class] as $guardClass) {
    larena_storage_role_assert(maria_driver_of(new $guardClass(new MariaDbDriverDetectionConnection(true))) === 'mariadb', $guardClass . ' sees MariaDB behind the mysql driver');
    larena_storage_role_assert(maria_driver_of(new $guardClass(new MariaDbDriverDetectionConnection(false))) === 'mysql', $guardClass . ' keeps MySQL as MySQL');
    larena_storage_role_assert(maria_driver_of(new $guardClass(new SQLiteConnection(new PDO('sqlite::memory:'), '', '', ['driver' => 'sqlite']))) === 'sqlite', $guardClass . ' keeps SQLite as SQLite');
}

echo "MariaDB driver detection passed.\n";
