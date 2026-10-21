<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Larena\Storage\View\MySqlStorageViewDdl;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewTableShape;
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
    storageViewExpect(in_array(StorageViewDdl::stateRecordIndexName($first), $indexes, true), 'the (state, record_id) index is missing');
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

/**
 * The site shape on any driver's DDL: (slot_id, record_id) keys on the table and its
 * side tables, field indexes that start with slot_id, the shape verifier for the site
 * columns, the listing of site tables apart from admin ones, and dropping them.
 */
function storageViewSiteDdlChecks(Connection $connection, StorageViewDdl $ddl): void
{
    $site = StorageViewTableShape::Site;
    $layout = (new StorageViewLayoutPlanner())->plan([
        storageViewField('title', 10, 'string', ['max_length' => 200]),
        storageViewField('qty', 20, 'integer'),
        storageViewField('tags', 30, 'choices', ['options' => [['value' => 'a', 'label' => 'A']]]),
    ], 1)->promoted();
    $table = StorageViewNaming::siteTable('workbench.ddl.site');
    storageViewExpect(StorageViewNaming::isSiteTable($table) && !StorageViewNaming::isTable($table) && str_starts_with($table, 'larena_storage_site_'), 'the site table name is wrong');
    storageViewExpect(!StorageViewNaming::isSiteTable('larena_storage_site_views') && !StorageViewNaming::isSiteTable('larena_storage_site_view_slots'), 'a registry table passed for a site table');
    try {
        $ddl->ensureTable(StorageViewNaming::table('workbench.ddl.site'), $layout, $site);
        throw new RuntimeException('an admin table name was accepted for the site shape');
    } catch (InvalidArgumentException $exception) {
        storageViewExpect($exception->getMessage() === 'storage_view_table_invalid', 'the admin name was refused for another reason');
    }
    $ddl->dropTables($table);
    $ddl->ensureTable($table, $layout, $site);
    $ddl->ensureTable($table, $layout, $site);
    $verifier = new StorageViewShapeVerifier($ddl);
    storageViewExpect($verifier->matches($table, $layout, true, $site), 'the site table does not match: ' . implode(', ', $verifier->problems($table, $layout, true, $site)));
    storageViewExpect($verifier->problems($table, $layout) !== [], 'the site table passed for an admin one');
    storageViewExpect($ddl->columns($table) === ['slot_id', 'record_id', 'revision', 'schema_version', 'projection_version', 'entry_hash', 'entry_json', 'title', 'updated_at', 'c1', 'c2'], 'the site columns are wrong: ' . json_encode($ddl->columns($table)));
    storageViewExpect($ddl->columns($table . '_m') === ['slot_id', 'record_id', 'field_key', 'value'] && $ddl->columns($table . '_s') === ['slot_id', 'record_id', 'search_text'], 'the site side tables are wrong');
    storageViewExpect(in_array(StorageViewDdl::fieldIndexName($table, 'c2'), $ddl->indexes($table), true) && in_array(StorageViewDdl::siteRecordIndexName($table), $ddl->indexes($table), true), 'a site index is missing');
    $row = static fn (int $slot, string $record): array => ['slot_id' => $slot, 'record_id' => $record, 'revision' => 1, 'schema_version' => 1, 'projection_version' => 3,
        'entry_hash' => str_repeat('a', 64), 'entry_json' => '{}', 'title' => 'T', 'updated_at' => '2026-10-21 08:00:00', 'c1' => 'T', 'c2' => 1];
    // One record in two slots is two rows; the same slot and record is one.
    $connection->table($table)->insert([$row(1, 'record-a'), $row(2, 'record-a')]);
    try {
        $connection->table($table)->insert($row(1, 'record-a'));
        throw new RuntimeException('a second row for one slot and record was accepted');
    } catch (Illuminate\Database\QueryException) {
    }
    storageViewExpect($connection->table($table)->count() === 2, 'the site key is not (slot_id, record_id)');
    $writer = new Larena\Storage\View\StorageViewWriter($connection);
    $writer->upsert($table, [['row' => ['title' => 'U', 'c1' => 'U'] + $row(1, 'record-a'), 'multi' => [['slot_id' => 1, 'record_id' => 'record-a', 'field_key' => 'tags', 'value' => 'a']], 'search' => ['slot_id' => 1, 'record_id' => 'record-a', 'search_text' => ' u']]], true, false, $site);
    $writer->upsert($table, [['row' => $row(2, 'record-a'), 'multi' => [['slot_id' => 2, 'record_id' => 'record-a', 'field_key' => 'tags', 'value' => 'a']], 'search' => null]], true, false, $site);
    storageViewExpect($connection->table($table)->where('slot_id', 1)->value('title') === 'U' && $connection->table($table)->where('slot_id', 2)->value('title') === 'T', 'an upsert crossed slots');
    storageViewExpect($connection->table($table . '_m')->count() === 2, 'a side row replacement crossed slots');
    $writer->deleteSlotRecords($table, 1, ['record-a']);
    storageViewExpect($connection->table($table)->count() === 1 && $connection->table($table . '_m')->count() === 1 && $connection->table($table . '_s')->count() === 0, 'a slot delete crossed slots');
    $writer->upsert($table, [['row' => $row(1, 'record-b'), 'multi' => [], 'search' => null]], true, true, $site);
    $writer->deleteRecordsInAllSlots($table, ['record-a']);
    storageViewExpect($connection->table($table)->pluck('record_id')->all() === ['record-b'] && $connection->table($table . '_m')->count() === 0, 'a purge did not reach every slot');
    $writer->deleteSlotRecords($table, 1);
    storageViewExpect($connection->table($table)->count() === 0, 'emptying a slot left rows');
    // Columns of the site table change in place like the admin ones.
    $added = new StorageViewColumn('extra', 'c9', 'integer', StorageViewColumn::SQL_BIGINT, true, StorageViewColumn::STATE_READY);
    $ddl->addColumn($table, $added);
    $ddl->createIndex($table, $added, $site);
    storageViewExpect(in_array('c9', $ddl->columns($table), true) && in_array(StorageViewDdl::fieldIndexName($table, 'c9'), $ddl->indexes($table), true), 'a site column or index was not added');
    $ddl->dropColumn($table, 'c9');
    $admin = StorageViewNaming::table('workbench.ddl.site');
    $ddl->ensureTable($admin, $layout);
    storageViewExpect($ddl->viewTables($site) === [$table, $table . '_m', $table . '_s'], 'the site tables are not listed apart: ' . json_encode($ddl->viewTables($site)));
    storageViewExpect(!in_array($table, $ddl->viewTables(), true) && in_array($admin, $ddl->viewTables(), true), 'the admin listing holds a site table');
    $ddl->dropTables($table);
    $ddl->dropTables($admin);
    storageViewExpect($ddl->viewTables($site) === [] && !$ddl->tableExists($table . '_s'), 'the site tables were not dropped');
}

/**
 * The SQL of a fixed sequence of DDL calls (ensureTable, createIndex, ensureSearchTable,
 * materialize) on a fresh admin table, without a shape and with the admin shape given.
 *
 * @return array{0: string, 1: string} sha256 of each statement log
 */
function storageViewAdminDdlLog(Closure $connect, Closure $ddlFor): array
{
    $layout = (new StorageViewLayoutPlanner())->plan([
        storageViewField('title', 10, 'string', ['max_length' => 200]),
        storageViewField('qty', 20, 'integer'),
        storageViewField('tags', 80, 'choices', ['options' => [['value' => 'a', 'label' => 'A']]]),
        storageViewField('body', 100, 'text'),
    ], 1)->promoted();
    $table = StorageViewNaming::table('workbench.golden');
    $column = new StorageViewColumn('extra', 'c12', 'integer', StorageViewColumn::SQL_BIGINT, true, StorageViewColumn::STATE_READY);
    $hashes = [];
    foreach ([null, StorageViewTableShape::Admin] as $shape) {
        $connection = $connect();
        $ddl = $ddlFor($connection);
        $run = static function () use ($ddl, $table, $layout, $column, $shape): void {
            $ddl->ensureTable($table, $layout, $shape);
            $ddl->createIndex($table, $column, $shape);
            $ddl->ensureSearchTable($table, $shape);
            $ddl->materialize($table, $layout, $shape);
        };
        if ($connection->getDriverName() === 'mysql') {
            $log = $connection->pretend($run);
        } else {
            $connection->enableQueryLog();
            $run();
            $log = $connection->getQueryLog();
        }
        $hashes[] = hash('sha256', implode("\n", array_map(static fn (array $query): string => $query['query'] . ' ' . json_encode($query['bindings']), $log)));
    }

    return [$hashes[0], $hashes[1]];
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

    storageViewSiteDdlChecks($connection, $ddl);

    // The admin DDL is byte for byte what it was before the site shape: the statements
    // recorded before the change, without a shape and with the admin shape (MySQL's are
    // recorded without a server, in pretend mode).
    $sqlite = storageViewAdminDdlLog(
        static function (): Connection {
            $golden = new Capsule();
            $golden->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

            return $golden->getConnection();
        },
        static fn (Connection $connection): StorageViewDdl => new SqliteStorageViewDdl($connection),
    );
    storageViewExpect($sqlite === array_fill(0, 2, 'c95b5d4e78876e428097dfe50a9469529f72418c6cbfc1b34512e7a960535043'), 'the SQLite admin DDL changed: ' . json_encode($sqlite));
    $mysql = storageViewAdminDdlLog(
        static fn (): Connection => new MySqlConnection(new PDO('sqlite::memory:'), 'golden', '', ['driver' => 'mysql', 'name' => 'mysql']),
        static fn (Connection $connection): StorageViewDdl => new MySqlStorageViewDdl($connection),
    );
    storageViewExpect($mysql === array_fill(0, 2, 'f8e43ca148d9c7f207e223fb029a28170ea594a3384df81e5528911d05cf362b'), 'the MySQL admin DDL changed: ' . json_encode($mysql));

    echo "StorageViewDdlTest passed.\n";
}
