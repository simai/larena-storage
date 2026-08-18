<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\Schema\MariaDbBuilder;
use Larena\Storage\Database\StorageOwnedTableShapeGuard;
use Larena\Storage\Database\StorageSchemaMigrationTableShapeGuard;
use Larena\Storage\Exceptions\StorageOwnedTableShapeRejected;

require_once __DIR__ . '/../../vendor/autoload.php';

final class MariaDbJsonAliasFixtureBuilder extends MariaDbBuilder
{
    /**
     * @param array<string, list<array<string, mixed>>> $columns
     * @param array<string, list<array<string, mixed>>> $indexes
     */
    public function __construct(
        Connection $connection,
        private readonly array $columns,
        private readonly array $indexes,
    ) {
        parent::__construct($connection);
    }

    public function hasTable($table): bool
    {
        return isset($this->columns[$table]);
    }

    /** @return list<array<string, mixed>> */
    public function getColumns($table): array
    {
        return $this->columns[$table] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function getIndexes($table): array
    {
        return $this->indexes[$table] ?? [];
    }
}

final class MariaDbJsonAliasFixtureConnection extends MariaDbConnection
{
    private ?MariaDbJsonAliasFixtureBuilder $fixtureBuilder = null;

    /** @var array<string, list<string>> */
    private array $checks = [];

    public function __construct(string $driver)
    {
        parent::__construct(
            static fn (): never => throw new RuntimeException('fixture PDO must not be requested'),
            'larena_fixture',
            '',
            ['driver' => $driver],
        );
    }

    /**
     * @param array<string, list<array<string, mixed>>> $columns
     * @param array<string, list<array<string, mixed>>> $indexes
     * @param array<string, list<string>> $checks
     */
    public function setFixture(array $columns, array $indexes, array $checks): void
    {
        $this->fixtureBuilder = new MariaDbJsonAliasFixtureBuilder($this, $columns, $indexes);
        $this->checks = $checks;
    }

    public function getSchemaBuilder(): MariaDbJsonAliasFixtureBuilder
    {
        if ($this->fixtureBuilder === null) {
            throw new RuntimeException('fixture schema builder missing');
        }

        return $this->fixtureBuilder;
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $table = is_string($bindings[0] ?? null) ? $bindings[0] : '';

        return array_map(
            static fn (string $clause): object => (object) ['check_clause' => $clause],
            $this->checks[$table] ?? [],
        );
    }
}

function mariaDbJsonAliasExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array{
 *   columns: array<string, list<array<string, mixed>>>,
 *   indexes: array<string, list<array<string, mixed>>>,
 *   checks: array<string, list<string>>,
 *   json_columns: list<array{table:string,column:string}>
 * }
 */
function mariaDbJsonAliasFixture(string $guardClass, string $driver): array
{
    $reflection = new ReflectionClass($guardClass);
    $shapeConstant = $guardClass === StorageOwnedTableShapeGuard::class ? 'TABLE_SHAPES' : 'SHAPES';
    /** @var array<string, array<string, mixed>> $shapes */
    $shapes = $reflection->getConstant($shapeConstant);
    /** @var array<string, array{unique:array<string,string>,secondary:array<string,string>}>|false $ownedNames */
    $ownedNames = $reflection->getConstant('NAMED_INDEXES');
    $columns = [];
    $indexes = [];
    $checks = [];
    $jsonColumns = [];

    foreach ($shapes as $key => $shape) {
        $table = $shape['table'];
        $contracts = $shape['column_contracts'] ?? $shape['columns'];
        foreach ($contracts as $name => $contract) {
            $typeName = match ($contract['family']) {
                'string' => ($contract['fixed'] ?? false) ? 'char' : 'varchar',
                'integer' => 'bigint',
                'json' => $driver === 'mariadb' ? 'longtext' : 'json',
                'timestamp' => 'timestamp',
                default => throw new RuntimeException('fixture family unsupported'),
            };
            $type = $typeName;
            if (isset($contract['length'])) {
                $type .= '(' . $contract['length'] . ')';
            }
            if ($contract['unsigned'] ?? false) {
                $type .= ' unsigned';
            }
            $columns[$table][] = [
                'name' => $name,
                'type_name' => $typeName,
                'type' => $type,
                'nullable' => $contract['nullable'],
                'auto_increment' => $contract['auto_increment'] ?? false,
            ];
            if ($contract['family'] === 'json') {
                $jsonColumns[] = ['table' => $table, 'column' => $name];
                if ($driver === 'mariadb') {
                    $checks[$table][] = 'json_valid(`' . $name . '`)';
                }
            }
        }

        foreach ($shape['primary'] as $position => $composition) {
            $indexes[$table][] = [
                'name' => $position === 0 ? 'primary' : 'primary_' . $position,
                'columns' => $composition,
                'primary' => true,
                'unique' => true,
            ];
        }
        if ($ownedNames !== false) {
            foreach (['unique', 'secondary'] as $type) {
                foreach ($ownedNames[$key][$type] as $name => $composition) {
                    $indexes[$table][] = [
                        'name' => $name,
                        'columns' => explode('|', $composition),
                        'primary' => false,
                        'unique' => $type === 'unique',
                    ];
                }
            }
        } else {
            foreach (['unique', 'secondary'] as $type) {
                foreach ($shape[$type] as $name => $composition) {
                    $indexes[$table][] = [
                        'name' => $name,
                        'columns' => $composition,
                        'primary' => false,
                        'unique' => $type === 'unique',
                    ];
                }
            }
        }
    }

    return [
        'columns' => $columns,
        'indexes' => $indexes,
        'checks' => $checks,
        'json_columns' => $jsonColumns,
    ];
}

/** @param class-string $guardClass */
function mariaDbJsonAliasRun(string $guardClass, string $driver, ?callable $mutate = null): void
{
    $fixture = mariaDbJsonAliasFixture($guardClass, $driver);
    if ($mutate !== null) {
        $mutate($fixture);
    }
    $connection = new MariaDbJsonAliasFixtureConnection($driver);
    $connection->setFixture($fixture['columns'], $fixture['indexes'], $fixture['checks']);
    (new $guardClass($connection))->assertCompleteCompatible();
}

/** @param class-string $guardClass */
function mariaDbJsonAliasExpectRejected(string $guardClass, callable $mutate): void
{
    try {
        mariaDbJsonAliasRun($guardClass, 'mariadb', $mutate);
    } catch (StorageOwnedTableShapeRejected $exception) {
        mariaDbJsonAliasExpect(
            in_array($exception->reasonCode, [
                'storage_owned_table_column_contract_incompatible',
                'storage_schema_migration_column_contract_incompatible',
            ], true),
            'unexpected MariaDB JSON alias rejection reason',
        );

        return;
    }

    throw new RuntimeException('unsafe MariaDB JSON alias fixture was accepted');
}

foreach ([StorageOwnedTableShapeGuard::class, StorageSchemaMigrationTableShapeGuard::class] as $guardClass) {
    mariaDbJsonAliasRun($guardClass, 'mariadb');
    mariaDbJsonAliasRun($guardClass, 'mysql');

    mariaDbJsonAliasExpectRejected($guardClass, static function (array &$fixture): void {
        $target = $fixture['json_columns'][0];
        $fixture['checks'][$target['table']] = [];
    });
    mariaDbJsonAliasExpectRejected($guardClass, static function (array &$fixture): void {
        $target = $fixture['json_columns'][0];
        $fixture['checks'][$target['table']] = ['json_valid(`wrong_column`)'];
    });
    mariaDbJsonAliasExpectRejected($guardClass, static function (array &$fixture): void {
        $target = $fixture['json_columns'][0];
        $fixture['checks'][$target['table']] = [
            'json_valid(`' . $target['column'] . '`) OR 1 = 1',
        ];
    });
}

echo "MariaDbJsonAliasShapeGuardTest passed.\n";
