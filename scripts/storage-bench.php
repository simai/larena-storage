<?php

declare(strict_types=1);

/*
 * Runs `larena:storage:bench` outside the application on a database that exists
 * only for this run: a new SQLite file in the temp directory, or a throwaway
 * MySQL/MariaDB database on the local server named by the root's ignored
 * .env.auth-mfa-mysql-test (created with a random name and dropped at the end).
 *
 *   php scripts/storage-bench.php --driver=sqlite --records=1000,10000 --repeat=5 --json=results.json
 *   php scripts/storage-bench.php --driver=mysql --records=10000,100000 --repeat=5 --json=results.json
 */

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Larena\Property\Contracts\PropertyTypeRegistry as PropertyTypeRegistryContract;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Console\Commands\StorageBenchCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require_once __DIR__ . '/../vendor/autoload.php';

// A list call decodes up to 5000 records in PHP; give the measurement room.
ini_set('memory_limit', '1G');

$options = getopt('', ['driver:', 'records:', 'repeat:', 'json:', 'keep']);
$driver = is_string($options['driver'] ?? null) ? $options['driver'] : 'sqlite';
$records = is_string($options['records'] ?? null) ? $options['records'] : '1000';
$repeat = is_string($options['repeat'] ?? null) ? $options['repeat'] : '5';
$json = is_string($options['json'] ?? null) ? $options['json'] : null;

$cleanup = static function (): void {
};
if ($driver === 'sqlite') {
    $path = sys_get_temp_dir() . '/larena-storage-bench-' . bin2hex(random_bytes(6)) . '.sqlite';
    touch($path);
    $config = ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true];
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    };
} elseif ($driver === 'mysql') {
    require_once __DIR__ . '/../tests/Support/mysql-disposable-database.php';
    $database = larena_storage_mysql_disposable_database('larena_storage_bench_test_');
    $config = $database['config'];
    $cleanup = $database['drop'];
} else {
    fwrite(STDERR, "--driver must be sqlite or mysql\n");
    exit(2);
}

$container = new class extends Container {
    public function runningUnitTests(): bool
    {
        return false;
    }
};
$capsule = new Capsule($container);
$capsule->addConnection($config, 'benchmark');
$capsule->setAsGlobal();
$container->instance(DatabaseManager::class, $capsule->getDatabaseManager());
$container->instance('db', $capsule->getDatabaseManager());
$container->instance(PropertyTypeRegistryContract::class, PropertyTypeRegistry::builtIns());
Container::setInstance($container);

$input = ['--connection' => 'benchmark', '--install' => true, '--records' => $records, '--repeat' => $repeat];
if ($json !== null) {
    $input['--json'] = $json;
}
if (array_key_exists('keep', $options)) {
    $input['--keep'] = true;
}
$command = new StorageBenchCommand();
$command->setLaravel($container);
$arguments = new ArrayInput($input);
$arguments->setInteractive(false);
try {
    $status = $command->run($arguments, new ConsoleOutput());
} finally {
    $capsule->getDatabaseManager()->disconnect('benchmark');
    $cleanup();
}
exit($status);
