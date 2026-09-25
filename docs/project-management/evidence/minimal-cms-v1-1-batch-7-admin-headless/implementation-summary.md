# Implementation summary

| File | Change |
| --- | --- |
| `src/Database/StorageOwnedTableShapeGuard.php` | normalizedDriver: a MySqlConnection to a MariaDB server is judged as mariadb |
| `src/Database/StorageSchemaMigrationTableShapeGuard.php` | the same |
| `tests/Unit/MariaDbDriverDetectionTest.php` | new |
