<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Benchmark\StorageBenchmark;
use Throwable;

/**
 * Measures the Storage engine on a disposable database and writes the result as
 * JSON. It refuses the application's default connection: point it at a
 * throwaway SQLite file (--sqlite) or at a separately configured disposable
 * connection (--connection), never at a working database.
 */
final class StorageBenchCommand extends Command
{
    private const SQLITE_CONNECTION = 'larena_storage_benchmark';

    protected $signature = 'larena:storage:bench
        {--records=1000,10000 : Comma-separated record counts to measure}
        {--repeat=5 : Calls per operation}
        {--json= : Write the results to this JSON file}
        {--keep : Keep the benchmark structures and their records}
        {--connection= : A configured disposable connection (never the default one)}
        {--sqlite= : A new SQLite file to create, install and measure}
        {--install : Create the Storage tables on the connection first}
        {--site-only : Measure only the public reads (exact path and site витрина)}';

    protected $description = 'Benchmark Larena Storage lists, filters, search, counts and record writes on a disposable database.';

    public function handle(DatabaseManager $databases, PropertyTypeRegistry $propertyTypes): int
    {
        $sizes = [];
        foreach (explode(',', (string) $this->option('records')) as $part) {
            $size = filter_var(trim($part), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10_000_000]]);
            if ($size === false) {
                $this->error('Record counts must be positive integers.');

                return self::INVALID;
            }
            $sizes[] = $size;
        }
        $repeat = filter_var($this->option('repeat'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($repeat === false) {
            $this->error('The repeat count must be a positive integer.');

            return self::INVALID;
        }
        $sqlite = $this->option('sqlite');
        $name = $this->option('connection');
        $install = (bool) $this->option('install');
        if (is_string($sqlite) && $sqlite !== '') {
            if (file_exists($sqlite)) {
                $this->error('The SQLite file already exists; the benchmark only creates a new one.');

                return self::INVALID;
            }
            if (file_put_contents($sqlite, '') === false) {
                $this->error('The SQLite file cannot be created.');

                return self::FAILURE;
            }
            $config = $this->laravel->make('config');
            $config['database.connections.' . self::SQLITE_CONNECTION] = [
                'driver' => 'sqlite',
                'database' => $sqlite,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
            $name = self::SQLITE_CONNECTION;
            $install = true;
        }
        if (!is_string($name) || $name === '') {
            $this->error('Name a disposable connection with --connection or a new SQLite file with --sqlite.');

            return self::INVALID;
        }
        if ($this->isDefaultDatabase($name)) {
            $this->error('The benchmark refuses the application\'s default database. Use a disposable one.');

            return self::INVALID;
        }

        try {
            $connection = $databases->connection($name);
            if ($install) {
                StorageBenchmark::installSchema($connection);
            }
            $result = (new StorageBenchmark($connection, $propertyTypes))->run(
                $sizes,
                $repeat,
                (bool) $this->option('keep'),
                fn (string $line) => $this->line($line),
                (bool) $this->option('site-only'),
            );
        } catch (Throwable $exception) {
            $this->error($exception::class . ': ' . $exception->getMessage());

            return self::FAILURE;
        }
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $path = $this->option('json');
        if (is_string($path) && $path !== '') {
            if (file_put_contents($path, $json) === false) {
                $this->error('The JSON file cannot be written.');

                return self::FAILURE;
            }
            $this->line('Results written to ' . $path);
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    /** True when the named connection points at the same database as the default one. */
    private function isDefaultDatabase(string $name): bool
    {
        $config = $this->laravel->make('config');
        $default = $config['database.default'];
        if (!is_string($default) || $default === $name) {
            return true;
        }
        $connections = $config['database.connections'];
        $target = is_array($connections) ? ($connections[$name] ?? null) : null;
        $base = is_array($connections) ? ($connections[$default] ?? null) : null;
        if (!is_array($target)) {
            return false;
        }
        if (!is_array($base)) {
            return false;
        }
        $identity = static fn (array $settings): string => implode('|', array_map(
            static fn (string $key): string => is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '',
            ['driver', 'host', 'port', 'database', 'url'],
        ));

        return $identity($target) === $identity($base);
    }
}
