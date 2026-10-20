<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewLayoutPlanner;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewShapeVerifier;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// The витрина DDL on SQLite: the table and side table with their system columns
// and indexes, idempotent column and index changes, hashed index names that do
// not collide across tables, the shape verifier, the listing of витрина tables
// with escaped LIKE underscores, and dropping the tables.

/** Runs the same DDL checks on any driver's DDL. */
function storageViewDdlChecks(Connection $connection, StorageViewDdl $ddl): void
{
    $planner = new StorageViewLayoutPlanner();
    $fields = [
        storageViewField('title', 10, 'string', ['max_length' => 200]),
        storageViewField('qty', 20, 'integer'),
        storageViewField('price', 30, 'number'),
        storageViewField('day', 40, 'date'),
        storageViewField('at', 50, 'datetime'),
        storageViewField('flag', 60, 'boolean'),
        storageViewField('status', 70, 'choice', ['options' => [['value' => 'a', 'label' => 'A']]]),
        storageViewField('tags', 80, 'choices', ['options' => [['value' => 'a', 'label' => 'A']]]),
        storageViewField('owner', 90, 'user'),
        storageViewField('body', 100, 'text'),
    ];
    $layout = $planner->plan($fields, 1)->promoted();
    $first = StorageViewNaming::table('workbench.ddl.first');
    $second = StorageViewNaming::table('workbench.ddl.second');
    $verifier = new StorageViewShapeVerifier($ddl);
    $ddl->dropTables($first);
    $ddl->dropTables($second);
    storageViewExpect($verifier->problems($first, $layout) === ['table_missing'], 'a missing table was not reported');

    $ddl->ensureTable($first, $layout);
    $ddl->ensureTable($first, $layout);
    $ddl->ensureTable($second, $layout);
    storageViewExpect($verifier->matches($first, $layout), 'the created table does not match: ' . implode(', ', $verifier->problems($first, $layout)));
    storageViewExpect(count($ddl->columns($first)) === 10 + 9, 'the table does not have ten system columns and nine field columns');
    storageViewExpect($ddl->columns($first . '_m') === ['record_id', 'field_key', 'value'], 'the side table columns are wrong');
    $indexes = $ddl->indexes($first);
    storageViewExpect(in_array(StorageViewDdl::stateIndexName($first), $indexes, true), 'the (state, updated_at) index is missing');
    foreach ($layout->physical() as $column) {
        $name = StorageViewDdl::fieldIndexName($first, (string) $column->column);
        storageViewExpect(in_array($name, $indexes, true) === $column->indexed, 'index presence of ' . $column->column . ' is wrong');
        storageViewExpect($name !== StorageViewDdl::fieldIndexName($second, (string) $column->column), 'index names collide across tables');
    }

    // A row with every type.
    $connection->table($first)->insert([
        'record_id' => 'record-' . str_repeat('a', 32), 'revision' => 1, 'schema_version' => 1, 'content_hash' => str_repeat('f', 64),
        'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-20 08:00:00', 'scope_ref' => 'scope:alpha', 'state' => 'active',
        'title' => 'T', 'search_text' => 't', 'c1' => 'Title', 'c2' => 9_007_199_254_740_993, 'c3' => '-' . str_repeat('9', 35) . '.' . str_repeat('9', 30),
        'c4' => '2026-10-20', 'c5' => '2026-10-20 08:30:00', 'c6' => 1, 'c7' => 'a', 'c8' => 'user:' . str_repeat('a', 64) . ':' . str_repeat('9', 20), 'c9' => str_repeat('x', 70000),
    ]);
    $row = $connection->table($first)->first();
    storageViewExpect($row !== null && (int) $row->c2 === 9_007_199_254_740_993 && strlen((string) $row->c9) === 70000, 'a BIGINT or long text value did not survive');

    // Columns and indexes are added and dropped idempotently.
    $added = new StorageViewColumn('extra', 'c12', 'integer', StorageViewColumn::SQL_BIGINT, true, StorageViewColumn::STATE_READY);
    $ddl->addColumn($first, $added);
    $ddl->addColumn($first, $added);
    $ddl->createIndex($first, $added);
    $ddl->createIndex($first, $added);
    storageViewExpect(in_array('c12', $ddl->columns($first), true) && in_array(StorageViewDdl::fieldIndexName($first, 'c12'), $ddl->indexes($first), true), 'a column or index was not added');
    storageViewExpect(in_array('column_unexpected:c12', $verifier->problems($first, $layout), true), 'an unexpected column was not reported');
    $ddl->dropColumn($first, 'c12');
    $ddl->dropColumn($first, 'c12');
    $ddl->dropIndex($first, 'c2');
    $ddl->dropIndex($first, 'c2');
    storageViewExpect(!in_array('c12', $ddl->columns($first), true), 'a column was not dropped');
    storageViewExpect($verifier->problems($first, $layout) === ['index_missing:c2'], 'a missing index was not reported');
    $ddl->materialize($first, $layout);
    storageViewExpect($verifier->matches($first, $layout), 'materialize did not repair the table');

    // Only витрина tables are listed; an underscore in the pattern does not match any character.
    $decoy = 'larenaXstorageXviewX' . str_repeat('b', 24);
    $connection->getSchemaBuilder()->dropIfExists($decoy);
    $connection->getSchemaBuilder()->create($decoy, static function ($table): void {
        $table->string('id');
    });
    $listed = $ddl->viewTables();
    storageViewExpect(in_array($first, $listed, true) && in_array($first . '_m', $listed, true) && in_array($second, $listed, true), 'витрина tables are not listed');
    storageViewExpect(!in_array($decoy, $listed, true), 'a table matched the LIKE pattern through an unescaped underscore');
    $connection->getSchemaBuilder()->drop($decoy);

    $ddl->dropTables($first);
    $ddl->dropTables($first);
    storageViewExpect(!$ddl->tableExists($first) && !$ddl->tableExists($first . '_m') && $ddl->tableExists($second), 'dropping one витрина touched another or left a table');
    $ddl->dropTables($second);

    try {
        $ddl->dropTables('larena_storage_records');
        throw new RuntimeException('a non-витрина table was accepted');
    } catch (InvalidArgumentException $exception) {
        storageViewExpect($exception->getMessage() === 'storage_view_table_invalid', 'a non-витрина table was refused for another reason');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();
    $ddl = new SqliteStorageViewDdl($connection);
    storageViewExpect($ddl->transactional(), 'SQLite DDL is not transactional');
    storageViewDdlChecks($connection, $ddl);

    // SQLite DDL rolls back with the transaction.
    $table = StorageViewNaming::table('workbench.ddl.rollback');
    try {
        $connection->transaction(static function () use ($ddl, $table): void {
            $ddl->ensureTable($table, (new StorageViewLayoutPlanner())->plan([storageViewField('title', 10, 'string')], 1)->promoted());
            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }
    storageViewExpect(!$ddl->tableExists($table), 'SQLite DDL did not roll back');

    echo "StorageViewDdlTest passed.\n";
}
