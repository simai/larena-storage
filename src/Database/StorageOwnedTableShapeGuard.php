<?php

declare(strict_types=1);

namespace Larena\Storage\Database;

use Illuminate\Database\Connection;
use Larena\Storage\Exceptions\StorageOwnedTableShapeRejected;
use Throwable;

final readonly class StorageOwnedTableShapeGuard
{
    /**
     * @var array<string, array{
     *     table: string,
     *     columns: list<string>,
     *     primary: list<list<string>>,
     *     unique: list<list<string>>,
     *     secondary: list<list<string>>,
     *     column_contracts: array<string, array{
     *         family: 'string'|'integer'|'json'|'timestamp',
     *         nullable: bool,
     *         auto_increment?: bool,
     *         length?: int,
     *         unsigned?: bool,
     *         fixed?: bool
     *     }>
     * }>
     */
    private const TABLE_SHAPES = [
        'schemas' => [
            'table' => 'larena_storage_schemas',
            'columns' => [
                'schema_id',
                'current_version',
                'current_hash',
                'created_at',
                'updated_at',
            ],
            'primary' => [['schema_id']],
            'unique' => [],
            'secondary' => [],
            'column_contracts' => [
                'schema_id' => ['family' => 'string', 'nullable' => false, 'length' => 120],
                'current_version' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'current_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'created_at' => ['family' => 'timestamp', 'nullable' => true],
                'updated_at' => ['family' => 'timestamp', 'nullable' => true],
            ],
        ],
        'schema_versions' => [
            'table' => 'larena_storage_schema_versions',
            'columns' => [
                'id',
                'schema_id',
                'version',
                'definition',
                'definition_hash',
                'owner_package',
                'created_by',
                'correlation_id',
                'created_at',
            ],
            'primary' => [['id']],
            'unique' => [['schema_id', 'version']],
            'secondary' => [['schema_id', 'created_at']],
            'column_contracts' => [
                'id' => [
                    'family' => 'integer',
                    'nullable' => false,
                    'auto_increment' => true,
                    'unsigned' => true,
                ],
                'schema_id' => ['family' => 'string', 'nullable' => false, 'length' => 120],
                'version' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'definition' => ['family' => 'json', 'nullable' => false],
                'definition_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'owner_package' => ['family' => 'string', 'nullable' => false, 'length' => 120],
                'created_by' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'correlation_id' => ['family' => 'string', 'nullable' => true, 'length' => 191],
                'created_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
        ],
        'records' => [
            'table' => 'larena_storage_records',
            'columns' => [
                'record_id',
                'schema_id',
                'owner_ref',
                'current_revision',
                'current_schema_version',
                'current_hash',
                'created_at',
                'updated_at',
            ],
            'primary' => [['record_id']],
            'unique' => [['schema_id', 'owner_ref']],
            'secondary' => [['schema_id', 'current_revision']],
            'column_contracts' => [
                'record_id' => ['family' => 'string', 'nullable' => false, 'length' => 39],
                'schema_id' => ['family' => 'string', 'nullable' => false, 'length' => 120],
                'owner_ref' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'current_revision' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'current_schema_version' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'current_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'created_at' => ['family' => 'timestamp', 'nullable' => true],
                'updated_at' => ['family' => 'timestamp', 'nullable' => true],
            ],
        ],
        'record_versions' => [
            'table' => 'larena_storage_record_versions',
            'columns' => [
                'id',
                'schema_id',
                'record_id',
                'revision',
                'owner_ref',
                'schema_version',
                'values_json',
                'content_hash',
                'operation',
                'created_by',
                'correlation_id',
                'created_at',
            ],
            'primary' => [['id']],
            'unique' => [['schema_id', 'record_id', 'revision']],
            'secondary' => [['schema_id', 'owner_ref', 'revision']],
            'column_contracts' => [
                'id' => [
                    'family' => 'integer',
                    'nullable' => false,
                    'auto_increment' => true,
                    'unsigned' => true,
                ],
                'schema_id' => ['family' => 'string', 'nullable' => false, 'length' => 120],
                'record_id' => ['family' => 'string', 'nullable' => false, 'length' => 39],
                'revision' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'owner_ref' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'schema_version' => ['family' => 'integer', 'nullable' => false, 'unsigned' => true],
                'values_json' => ['family' => 'json', 'nullable' => false],
                'content_hash' => ['family' => 'string', 'nullable' => false, 'length' => 64, 'fixed' => true],
                'operation' => ['family' => 'string', 'nullable' => false, 'length' => 24],
                'created_by' => ['family' => 'string', 'nullable' => false, 'length' => 191],
                'correlation_id' => ['family' => 'string', 'nullable' => true, 'length' => 191],
                'created_at' => ['family' => 'timestamp', 'nullable' => false],
            ],
        ],
    ];

    /** @var array<string, array{unique: array<string, string>, secondary: array<string, string>}> */
    private const NAMED_INDEXES = [
        'schemas' => [
            'unique' => [],
            'secondary' => [],
        ],
        'schema_versions' => [
            'unique' => [
                'storage_schema_version_unique' => 'schema_id|version',
            ],
            'secondary' => [
                'storage_schema_created_index' => 'schema_id|created_at',
            ],
        ],
        'records' => [
            'unique' => [
                'storage_schema_owner_unique' => 'schema_id|owner_ref',
            ],
            'secondary' => [
                'storage_record_head_index' => 'schema_id|current_revision',
            ],
        ],
        'record_versions' => [
            'unique' => [
                'storage_record_revision_unique' => 'schema_id|record_id|revision',
            ],
            'secondary' => [
                'storage_record_owner_index' => 'schema_id|owner_ref|revision',
            ],
        ],
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @return list<string> */
    public static function tableNames(): array
    {
        return array_values(array_map(
            static fn (array $shape): string => $shape['table'],
            self::TABLE_SHAPES,
        ));
    }

    /**
     * Validate every existing owned table before the caller executes DDL.
     *
     * A compatible empty subset is recoverable. A partial topology containing
     * data is not: the package cannot prove how the missing tables were lost.
     *
     * @return list<string> Exact table names which the migration may create.
     */
    public function preflightUp(): array
    {
        $this->assertSupportedDriver();
        $existing = $this->existingShapes();
        $this->assertShapesCompatible($existing);

        if ($existing !== [] && count($existing) !== count(self::TABLE_SHAPES)) {
            foreach ($existing as $key => $shape) {
                if ($this->tableHasData($shape['table'], $key)) {
                    throw new StorageOwnedTableShapeRejected(
                        'storage_owned_table_partial_topology_contains_data',
                        $key,
                    );
                }
            }
        }

        $missing = [];
        foreach (self::TABLE_SHAPES as $key => $shape) {
            if (!isset($existing[$key])) {
                $missing[] = $shape['table'];
            }
        }

        return $missing;
    }

    /**
     * Read-only validation used by upgrade migrations and runtime preflight.
     */
    public function assertCompleteCompatible(): void
    {
        $this->assertSupportedDriver();
        $existing = $this->existingShapes();
        $this->assertShapesCompatible($existing);

        if (count($existing) !== count(self::TABLE_SHAPES)) {
            throw new StorageOwnedTableShapeRejected('storage_owned_table_topology_incompatible');
        }
    }

    /**
     * Validate ownership, topology and emptiness before the first drop.
     *
     * @return list<string> Exact table names in safe drop order; an empty list
     *     means the owned topology is already absent and down() is a no-op.
     */
    public function preflightDown(): array
    {
        $this->assertSupportedDriver();
        $existing = $this->existingShapes();
        if ($existing === []) {
            return [];
        }

        $this->assertShapesCompatible($existing);
        if (count($existing) !== count(self::TABLE_SHAPES)) {
            throw new StorageOwnedTableShapeRejected('storage_owned_table_topology_incompatible');
        }

        foreach ($existing as $key => $shape) {
            if ($this->tableHasData($shape['table'], $key)) {
                throw new StorageOwnedTableShapeRejected(
                    'storage_typed_content_rollback_would_lose_data',
                    $key,
                );
            }
        }

        return array_reverse(self::tableNames());
    }

    /**
     * @return array<string, array{
     *     table: string,
     *     columns: list<string>,
     *     primary: list<list<string>>,
     *     unique: list<list<string>>,
     *     secondary: list<list<string>>,
     *     column_contracts: array<string, array{
     *         family: 'string'|'integer'|'json'|'timestamp',
     *         nullable: bool,
     *         auto_increment?: bool,
     *         length?: int,
     *         unsigned?: bool,
     *         fixed?: bool
     *     }>
     * }>
     */
    private function existingShapes(): array
    {
        $schema = $this->connection->getSchemaBuilder();
        $existing = [];

        foreach (self::TABLE_SHAPES as $key => $shape) {
            try {
                $exists = $schema->hasTable($shape['table']);
            } catch (Throwable) {
                throw new StorageOwnedTableShapeRejected(
                    'storage_owned_table_introspection_failed',
                    $key,
                );
            }

            if ($exists) {
                $existing[$key] = $shape;
            }
        }

        return $existing;
    }

    /**
     * @param array<string, array{
     *     table: string,
     *     columns: list<string>,
     *     primary: list<list<string>>,
     *     unique: list<list<string>>,
     *     secondary: list<list<string>>,
     *     column_contracts: array<string, array{
     *         family: 'string'|'integer'|'json'|'timestamp',
     *         nullable: bool,
     *         auto_increment?: bool,
     *         length?: int,
     *         unsigned?: bool,
     *         fixed?: bool
     *     }>
     * }> $shapes
     */
    private function assertShapesCompatible(array $shapes): void
    {
        foreach ($shapes as $key => $shape) {
            $this->assertShapeCompatible($key, $shape);
        }
    }

    /**
     * @param array{
     *     table: string,
     *     columns: list<string>,
     *     primary: list<list<string>>,
     *     unique: list<list<string>>,
     *     secondary: list<list<string>>,
     *     column_contracts: array<string, array{
     *         family: 'string'|'integer'|'json'|'timestamp',
     *         nullable: bool,
     *         auto_increment?: bool,
     *         length?: int,
     *         unsigned?: bool,
     *         fixed?: bool
     *     }>
     * } $shape
     */
    private function assertShapeCompatible(string $key, array $shape): void
    {
        $schema = $this->connection->getSchemaBuilder();

        try {
            $columnMetadata = $schema->getColumns($shape['table']);
            $actualColumns = array_map(
                static fn (array $column): string => strtolower((string) $column['name']),
                $columnMetadata,
            );
            $actualIndexes = $schema->getIndexes($shape['table']);
        } catch (Throwable) {
            throw new StorageOwnedTableShapeRejected(
                'storage_owned_table_introspection_failed',
                $key,
            );
        }

        $expectedColumns = $shape['columns'];
        sort($actualColumns);
        sort($expectedColumns);
        if ($actualColumns !== $expectedColumns) {
            throw new StorageOwnedTableShapeRejected(
                'storage_owned_table_columns_incompatible',
                $key,
            );
        }

        $metadataByName = [];
        foreach ($columnMetadata as $column) {
            $metadataByName[strtolower((string) $column['name'])] = $column;
        }
        foreach ($shape['column_contracts'] as $columnName => $contract) {
            if (!$this->columnContractMatches($metadataByName[$columnName], $contract)) {
                throw new StorageOwnedTableShapeRejected(
                    'storage_owned_table_column_contract_incompatible',
                    $key,
                );
            }
        }

        $normalized = [
            'primary' => [],
            'unique' => [],
            'secondary' => [],
        ];
        $namedIndexes = [
            'unique' => [],
            'secondary' => [],
        ];
        foreach ($actualIndexes as $index) {
            $columns = array_map(
                static fn (string $column): string => strtolower($column),
                $index['columns'],
            );
            if ($index['primary']) {
                $normalized['primary'][] = $columns;
            } elseif ($index['unique']) {
                $normalized['unique'][] = $columns;
                $namedIndexes['unique'][strtolower($index['name'])] = implode('|', $columns);
            } else {
                $normalized['secondary'][] = $columns;
                $namedIndexes['secondary'][strtolower($index['name'])] = implode('|', $columns);
            }
        }

        foreach (['primary', 'unique', 'secondary'] as $type) {
            $actual = $this->normalizedCompositions($normalized[$type]);
            $expected = $this->normalizedCompositions($shape[$type]);
            if ($actual !== $expected) {
                $reasonCode = match ($type) {
                    'primary' => 'storage_owned_table_primary_index_incompatible',
                    'unique' => 'storage_owned_table_unique_index_incompatible',
                    default => 'storage_owned_table_secondary_index_incompatible',
                };
                throw new StorageOwnedTableShapeRejected($reasonCode, $key);
            }
        }

        foreach (['unique', 'secondary'] as $type) {
            $actual = $namedIndexes[$type];
            $expected = self::NAMED_INDEXES[$key][$type];
            ksort($actual);
            ksort($expected);
            if ($actual !== $expected) {
                $reasonCode = $type === 'unique'
                    ? 'storage_owned_table_unique_index_incompatible'
                    : 'storage_owned_table_secondary_index_incompatible';
                throw new StorageOwnedTableShapeRejected($reasonCode, $key);
            }
        }
    }

    /**
     * @param list<list<string>> $compositions
     * @return list<string>
     */
    private function normalizedCompositions(array $compositions): array
    {
        $normalized = array_map(
            static fn (array $columns): string => implode('|', array_map('strtolower', $columns)),
            $compositions,
        );
        sort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $column
     * @param array{
     *     family: 'string'|'integer'|'json'|'timestamp',
     *     nullable: bool,
     *     auto_increment?: bool,
     *     length?: int,
     *     unsigned?: bool,
     *     fixed?: bool
     * } $contract
     */
    private function columnContractMatches(array $column, array $contract): bool
    {
        $driver = strtolower($this->connection->getDriverName());
        $typeName = strtolower((string) ($column['type_name'] ?? ''));
        $fullType = strtolower((string) ($column['type'] ?? ''));
        $expectedTypeName = match ($driver) {
            'mysql' => match ($contract['family']) {
                'string' => ($contract['fixed'] ?? false) ? 'char' : 'varchar',
                'integer' => 'bigint',
                'json' => 'json',
                'timestamp' => 'timestamp',
            },
            'sqlite' => match ($contract['family']) {
                'string' => 'varchar',
                'integer' => 'integer',
                'json' => $this->connection->getConfig('use_native_json') ? 'json' : 'text',
                'timestamp' => 'datetime',
            },
            default => null,
        };
        if ($expectedTypeName === null
            || $typeName !== $expectedTypeName
            || (bool) ($column['nullable'] ?? false) !== $contract['nullable']) {
            return false;
        }

        $expectedAutoIncrement = $contract['auto_increment'] ?? false;
        if ((bool) ($column['auto_increment'] ?? false) !== $expectedAutoIncrement) {
            return false;
        }

        if ($driver === 'mysql') {
            if (isset($contract['length'])) {
                if (preg_match('/\((\d+)\)/', $fullType, $matches) !== 1
                    || (int) $matches[1] !== $contract['length']) {
                    return false;
                }
            }

            $expectedUnsigned = $contract['unsigned'] ?? false;
            if (str_contains($fullType, 'unsigned') !== $expectedUnsigned) {
                return false;
            }
        }

        return true;
    }

    private function tableHasData(string $table, string $key): bool
    {
        try {
            return $this->connection->table($table)->limit(1)->exists();
        } catch (Throwable) {
            throw new StorageOwnedTableShapeRejected(
                'storage_owned_table_introspection_failed',
                $key,
            );
        }
    }

    private function assertSupportedDriver(): void
    {
        if (!in_array(strtolower($this->connection->getDriverName()), ['mysql', 'sqlite'], true)) {
            throw new StorageOwnedTableShapeRejected('storage_owned_table_driver_unsupported');
        }
    }
}
