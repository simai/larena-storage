<?php

declare(strict_types=1);

namespace Larena\Storage\Database;

use Illuminate\Database\Connection;
use Larena\Storage\Exceptions\StorageOwnedTableShapeRejected;
use Throwable;

final readonly class StorageSchemaMigrationTableShapeGuard
{
    /**
     * @var array<string, array{
     *   table:string,
     *   columns:array<string, array{family:string, nullable:bool, auto_increment?:bool, length?:int, unsigned?:bool, fixed?:bool}>,
     *   primary:list<list<string>>, unique:array<string,list<string>>, secondary:array<string,list<string>>
     * }>
     */
    private const SHAPES = [
        'migration_plans' => [
            'table' => 'larena_storage_schema_migration_plans',
            'columns' => [
                'plan_ref' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'schema_id' => ['family' => 'string', 'nullable' => false, 'length' => 120],
                'source_version' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'source_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'target_version' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'target_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'target_definition' => ['family' => 'json', 'nullable' => false],
                'compatibility_class' => ['family' => 'string', 'nullable' => false, 'length' => 48],
                'added_optional_count' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'record_count' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'record_heads_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'plan_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'created_by' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'correlation_id' => ['family' => 'string', 'nullable' => true, 'length' => 191],
                'created_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
            'primary' => [['plan_ref']],
            'unique' => [],
            'secondary' => ['storage_migration_plan_schema_index' => ['schema_id', 'source_version']],
        ],
        'migration_plan_records' => [
            'table' => 'larena_storage_schema_migration_plan_records',
            'columns' => [
                'id' => ['family' => 'integer', 'nullable' => false, 'auto_increment' => true, 'unsigned' => true],
                'plan_ref' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'record_id' => ['family' => 'string', 'nullable' => false, 'length' => 39],
                'owner_ref' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'expected_revision' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'expected_schema_version' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'expected_content_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
            ],
            'primary' => [['id']],
            'unique' => ['storage_migration_plan_record_unique' => ['plan_ref', 'record_id']],
            'secondary' => ['storage_migration_plan_owner_index' => ['plan_ref', 'owner_ref']],
        ],
        'migration_results' => [
            'table' => 'larena_storage_schema_migration_results',
            'columns' => [
                'result_ref' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'plan_ref' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'schema_id' => ['family' => 'string', 'nullable' => false, 'length' => 120],
                'target_version' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'target_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'migrated_record_count' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'migrated_records_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'result_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'applied_by' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'correlation_id' => ['family' => 'string', 'nullable' => true, 'length' => 191],
                'applied_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
            'primary' => [['result_ref']],
            'unique' => ['storage_migration_result_plan_unique' => ['plan_ref']],
            'secondary' => ['storage_migration_result_schema_index' => ['schema_id', 'target_version']],
        ],
        'migration_result_records' => [
            'table' => 'larena_storage_schema_migration_result_records',
            'columns' => [
                'id' => ['family' => 'integer', 'nullable' => false, 'auto_increment' => true, 'unsigned' => true],
                'result_ref' => ['family' => 'string', 'nullable' => false, 'length' => 64],
                'record_id' => ['family' => 'string', 'nullable' => false, 'length' => 39],
                'owner_ref' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'from_revision' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'to_revision' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'target_schema_version' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'content_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
            ],
            'primary' => [['id']],
            'unique' => ['storage_migration_result_record_unique' => ['result_ref', 'record_id']],
            'secondary' => ['storage_migration_result_owner_index' => ['result_ref', 'owner_ref']],
        ],
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_values(array_map(static fn (array $shape): string => $shape['table'], self::SHAPES));
    }

    /** @return list<string> */
    public function preflightUp(): array
    {
        $existing = $this->existingAndCompatible();
        if ($existing !== [] && count($existing) !== count(self::SHAPES)) {
            foreach ($existing as $key => $shape) {
                if ($this->hasData($shape['table'], $key)) {
                    throw new StorageOwnedTableShapeRejected('storage_schema_migration_partial_topology_contains_data', $key);
                }
            }
        }

        $missing = [];
        foreach (self::SHAPES as $key => $shape) {
            if (!isset($existing[$key])) {
                $missing[] = $shape['table'];
            }
        }

        return $missing;
    }

    public function assertCompleteCompatible(): void
    {
        if (count($this->existingAndCompatible()) !== count(self::SHAPES)) {
            throw new StorageOwnedTableShapeRejected('storage_schema_migration_topology_incompatible');
        }
    }

    /** @return list<string> */
    public function preflightDown(): array
    {
        $existing = $this->existingAndCompatible();
        if ($existing === []) {
            return [];
        }
        if (count($existing) !== count(self::SHAPES)) {
            throw new StorageOwnedTableShapeRejected('storage_schema_migration_topology_incompatible');
        }
        foreach ($existing as $key => $shape) {
            if ($this->hasData($shape['table'], $key)) {
                throw new StorageOwnedTableShapeRejected('storage_schema_migration_rollback_would_lose_data', $key);
            }
        }

        return array_reverse(self::tableNames());
    }

    /** @return array<string, array<string, mixed>> */
    private function existingAndCompatible(): array
    {
        $this->assertSupportedDriver();
        $schema = $this->connection->getSchemaBuilder();
        $existing = [];
        foreach (self::SHAPES as $key => $shape) {
            try {
                if (!$schema->hasTable($shape['table'])) {
                    continue;
                }
                $columns = $schema->getColumns($shape['table']);
                $indexes = $schema->getIndexes($shape['table']);
            } catch (Throwable) {
                throw new StorageOwnedTableShapeRejected('storage_schema_migration_introspection_failed', $key);
            }
            $actualNames = array_map(static fn (array $column): string => strtolower((string) $column['name']), $columns);
            $expectedNames = array_keys($shape['columns']);
            sort($actualNames);
            sort($expectedNames);
            if ($actualNames !== $expectedNames) {
                throw new StorageOwnedTableShapeRejected('storage_schema_migration_columns_incompatible', $key);
            }
            $byName = [];
            foreach ($columns as $column) {
                $byName[strtolower((string) $column['name'])] = $column;
            }
            foreach ($shape['columns'] as $name => $contract) {
                if (!$this->columnMatches($byName[$name], $contract)) {
                    throw new StorageOwnedTableShapeRejected('storage_schema_migration_column_contract_incompatible', $key);
                }
            }
            $this->assertIndexes($key, $shape, $indexes);
            $existing[$key] = $shape;
        }

        return $existing;
    }

    /** @param array<string, mixed> $shape @param list<array<string, mixed>> $indexes */
    private function assertIndexes(string $key, array $shape, array $indexes): void
    {
        $actual = ['primary' => [], 'unique' => [], 'secondary' => []];
        foreach ($indexes as $index) {
            $columns = array_map('strtolower', $index['columns']);
            if ($index['primary']) {
                $actual['primary'][] = implode('|', $columns);
            } elseif ($index['unique']) {
                $actual['unique'][strtolower((string) $index['name'])] = implode('|', $columns);
            } else {
                $actual['secondary'][strtolower((string) $index['name'])] = implode('|', $columns);
            }
        }
        $expectedPrimary = array_map(static fn (array $columns): string => implode('|', $columns), $shape['primary']);
        sort($actual['primary']);
        sort($expectedPrimary);
        if ($actual['primary'] !== $expectedPrimary) {
            throw new StorageOwnedTableShapeRejected('storage_schema_migration_primary_index_incompatible', $key);
        }
        foreach (['unique', 'secondary'] as $type) {
            $expected = array_map(static fn (array $columns): string => implode('|', $columns), $shape[$type]);
            ksort($actual[$type]);
            ksort($expected);
            if ($actual[$type] !== $expected) {
                throw new StorageOwnedTableShapeRejected('storage_schema_migration_' . $type . '_index_incompatible', $key);
            }
        }
    }

    /** @param array<string, mixed> $column @param array<string, mixed> $contract */
    private function columnMatches(array $column, array $contract): bool
    {
        $driver = strtolower($this->connection->getDriverName());
        $typeName = strtolower((string) ($column['type_name'] ?? ''));
        $fullType = strtolower((string) ($column['type'] ?? ''));
        $expected = match ($driver) {
            'mysql' => match ($contract['family']) {
                'string' => ($contract['fixed'] ?? false) ? 'char' : 'varchar',
                'integer' => 'bigint',
                'json' => 'json',
                'timestamp' => 'timestamp',
                default => '',
            },
            'sqlite' => match ($contract['family']) {
                'string' => 'varchar',
                'integer' => 'integer',
                'json' => $this->connection->getConfig('use_native_json') ? 'json' : 'text',
                'timestamp' => 'datetime',
                default => '',
            },
            default => '',
        };
        if ($typeName !== $expected || (bool) ($column['nullable'] ?? false) !== $contract['nullable']) {
            return false;
        }
        if ((bool) ($column['auto_increment'] ?? false) !== ($contract['auto_increment'] ?? false)) {
            return false;
        }
        if ($driver === 'mysql') {
            if (isset($contract['length']) && (preg_match('/\((\d+)\)/', $fullType, $m) !== 1 || (int) $m[1] !== $contract['length'])) {
                return false;
            }
            if (str_contains($fullType, 'unsigned') !== ($contract['unsigned'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function hasData(string $table, string $key): bool
    {
        try {
            return $this->connection->table($table)->limit(1)->exists();
        } catch (Throwable) {
            throw new StorageOwnedTableShapeRejected('storage_schema_migration_introspection_failed', $key);
        }
    }

    private function assertSupportedDriver(): void
    {
        if (!in_array(strtolower($this->connection->getDriverName()), ['sqlite', 'mysql'], true)) {
            throw new StorageOwnedTableShapeRejected('storage_schema_migration_driver_unsupported');
        }
    }
}
