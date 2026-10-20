<?php

declare(strict_types=1);

use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewFieldOptions;
use Larena\Storage\View\StorageViewLayout;
use Larena\Storage\View\StorageViewLayoutPlanner;
use Larena\Storage\View\StorageViewNaming;
use Larena\Storage\View\StorageViewProjector;

require_once __DIR__ . '/../../vendor/autoload.php';

// The витрина's names, its layout planner (type mapping, row budget, index cap,
// ordinals never reused, DDL diff) and the projection of a record onto a row.

function viewLayoutExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function viewLayoutRejects(callable $call, string $reason): void
{
    try {
        $call();
    } catch (StorageRejected $exception) {
        viewLayoutExpect($exception->reasonCode === $reason, 'expected ' . $reason . ', got ' . $exception->reasonCode);

        return;
    }
    throw new RuntimeException('expected rejection ' . $reason);
}

/** @param array<string, mixed> $constraints @return array<string, mixed> */
function viewField(string $key, string $type, array $constraints = []): array
{
    return ['key' => $key, 'type' => $type, 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => $constraints];
}

// Naming: a fixed prefix and 24 hex of sha256(schema id); the side table adds _m.
$table = StorageViewNaming::table('workbench.scope.alpha.items');
viewLayoutExpect($table === 'larena_storage_view_' . substr(hash('sha256', 'workbench.scope.alpha.items'), 0, 24), 'table name is not derived from the schema id');
viewLayoutExpect(strlen($table) === 44 && StorageViewNaming::isTable($table), 'table name has the wrong shape');
viewLayoutExpect(StorageViewNaming::multiTable($table) === $table . '_m' && StorageViewNaming::isTable($table . '_m'), 'side table name is wrong');
viewLayoutExpect(!StorageViewNaming::isTable('larena_storage_views') && !StorageViewNaming::isTable('larenaXstorageXviewX' . str_repeat('a', 24)), 'a non-витрина table matched');
viewLayoutExpect(StorageViewNaming::table('a.b') !== StorageViewNaming::table('a.c'), 'two schemas share a table');
$index = StorageViewNaming::index($table, 'field:c1');
viewLayoutExpect(preg_match('/\Alsv_[0-9a-f]{24}\z/', $index) === 1 && $index !== StorageViewNaming::index($table . '_m', 'field:c1'), 'index names are not hashed per table');
viewLayoutExpect(StorageViewNaming::tableLikePattern() === 'larena\\_storage\\_view\\_%', 'LIKE pattern does not escape underscores');
viewLayoutExpect(StorageViewNaming::column(7) === 'c7', 'column name is not cN');

// Mapping of every Property type.
$planner = new StorageViewLayoutPlanner();
$fields = [
    viewField('larena_scope_ref', 'string', ['max_length' => 191]),
    viewField('larena_record_state', 'string', ['max_length' => 8]),
    viewField('title', 'string', ['max_length' => 200]),
    viewField('code', 'string', ['max_length' => 40]),
    viewField('body', 'text'),
    viewField('qty', 'integer'),
    viewField('price', 'number'),
    viewField('day', 'date'),
    viewField('at', 'datetime'),
    viewField('flag', 'boolean'),
    viewField('status', 'choice', ['options' => [['value' => 'a', 'label' => 'A']]]),
    viewField('tags', 'choices', ['options' => [['value' => 'a', 'label' => 'A']]]),
    viewField('owner', 'user'),
    viewField('section', 'record', ['target_structure_id' => 'workbench.sections']),
    viewField('doc', 'file'),
    viewField('related', 'relation'),
];
$layout = $planner->plan($fields, 1, null, new StorageViewFieldOptions(['title'], ['code']));
$byKey = [];
foreach ($layout->columns as $column) {
    $byKey[$column->fieldKey] = $column;
}
viewLayoutExpect(!isset($byKey['larena_scope_ref']) && !isset($byKey['larena_record_state']), 'system fields got field columns');
$expected = [
    'title' => [StorageViewColumn::SQL_TEXT, false],
    'code' => [StorageViewColumn::varchar(40), true],
    'body' => [StorageViewColumn::SQL_TEXT, false],
    'qty' => [StorageViewColumn::SQL_BIGINT, true],
    'price' => [StorageViewColumn::SQL_DECIMAL, true],
    'day' => [StorageViewColumn::SQL_DATE, true],
    'at' => [StorageViewColumn::SQL_DATETIME, true],
    'flag' => [StorageViewColumn::SQL_TINYINT, true],
    'status' => [StorageViewColumn::SQL_REF, true],
    'tags' => [StorageViewColumn::SQL_SIDE, false],
    'owner' => [StorageViewColumn::SQL_USER_REF, true],
    'section' => [StorageViewColumn::SQL_REF, true],
    'doc' => [StorageViewColumn::SQL_REF, false],
    'related' => [StorageViewColumn::SQL_REF, false],
];
foreach ($expected as $key => [$sqlType, $indexed]) {
    viewLayoutExpect(isset($byKey[$key]) && $byKey[$key]->sqlType === $sqlType && $byKey[$key]->indexed === $indexed, 'mapping of ' . $key . ' is wrong');
    viewLayoutExpect($byKey[$key]->state === StorageViewColumn::STATE_ADDING, $key . ' is not reserved first');
}
viewLayoutExpect($byKey['tags']->column === null && $byKey['title']->column === 'c1' && $byKey['related']->column === 'c13', 'ordinals do not follow the fields');
viewLayoutExpect($layout->nextOrdinal === 14, 'next ordinal is wrong');
// A string indexed on request needs max_length <= 255; otherwise it stays long text.
viewLayoutExpect(StorageViewLayoutPlanner::sqlType('string', ['max_length' => 256], true) === StorageViewColumn::SQL_TEXT, 'a long string became VARCHAR(255)');
viewLayoutExpect(StorageViewLayoutPlanner::sqlType('string', [], true) === StorageViewColumn::SQL_TEXT, 'a string without max_length became VARCHAR(255)');
viewLayoutExpect(StorageViewLayoutPlanner::sqlType('future_type', []) === StorageViewColumn::SQL_TEXT, 'an unknown type is not kept as text');

// JSON round trip and hash.
$ready = $layout->promoted();
viewLayoutExpect($ready->inState(StorageViewColumn::STATE_ADDING) === [] && count($ready->written()) === 14, 'promotion did not make every column written');
viewLayoutExpect(StorageViewLayout::fromJson($ready->toJson())->hash === $ready->hash, 'layout JSON round trip changed the layout');
viewLayoutRejects(static fn () => StorageViewLayout::fromJson('{"schema_version":1}'), 'storage_view_layout_invalid');

// A changed schema keeps unchanged columns, retires removed and retyped ones, and
// never reuses an ordinal.
$changed = $planner->plan([
    viewField('title', 'string', ['max_length' => 200]),
    viewField('qty', 'number'),
    viewField('day', 'date'),
    viewField('note', 'string'),
], 2, $ready);
$changedByKey = [];
foreach ($changed->columns as $column) {
    $changedByKey[$column->fieldKey . ':' . $column->state] = $column;
}
viewLayoutExpect(($changedByKey['title:ready'] ?? null)?->column === 'c1' && ($changedByKey['day:ready'] ?? null)?->column === 'c6', 'unchanged fields lost their columns');
viewLayoutExpect(($changedByKey['qty:dropping'] ?? null)?->column === 'c4' && ($changedByKey['qty:adding'] ?? null)?->column === 'c14', 'a retyped field did not get a new column');
viewLayoutExpect(($changedByKey['note:adding'] ?? null)?->column === 'c15' && $changed->nextOrdinal === 16, 'an added field reused an ordinal');
viewLayoutExpect(($changedByKey['body:dropping'] ?? null) !== null && ($changedByKey['tags:dropping'] ?? null) !== null, 'removed fields are not dropped');
$ops = array_map(static fn (array $op): string => $op['op'] . ':' . $op['column']->column, $planner->diff($ready, $changed));
viewLayoutExpect(in_array('add_column:c14', $ops, true) && in_array('create_index:c14', $ops, true) && in_array('add_column:c15', $ops, true), 'diff misses the additions');
viewLayoutExpect(in_array('drop_index:c4', $ops, true) && in_array('drop_column:c4', $ops, true) && in_array('drop_column:c3', $ops, true), 'diff misses the drops');
viewLayoutExpect(!in_array('add_column:c1', $ops, true), 'diff re-adds an unchanged column');
$dropped = $changed->promoted()->withoutDropped();
viewLayoutExpect(count($dropped->columns) === 4 && $dropped->nextOrdinal === 16, 'dropped columns were not removed, or the ordinal went back');
$again = $planner->plan([viewField('title', 'string', ['max_length' => 200]), viewField('body', 'text')], 3, $dropped);
viewLayoutExpect(array_values(array_filter($again->columns, static fn ($c) => $c->fieldKey === 'body'))[0]->column === 'c16', 'a re-added field got an old ordinal back');

// At most 40 automatic indexes.
$many = [];
for ($i = 1; $i <= 45; $i++) {
    $many[] = viewField('n' . $i, 'integer');
}
$capped = $planner->plan($many, 1);
viewLayoutExpect(count(array_filter($capped->columns, static fn ($c) => $c->indexed)) === StorageViewLayoutPlanner::MAX_AUTO_INDEXES, 'the automatic index cap is not 40');
viewLayoutExpect($capped->columns[39]->indexed && !$capped->columns[40]->indexed, 'indexes are not given in field order');

// Row budget: indexed strings beyond MySQL's 65 535-byte row turn into long text.
$strings = [];
for ($i = 1; $i <= 40; $i++) {
    $strings[] = viewField('s' . $i, 'string', ['max_length' => 255]);
}
for ($i = 1; $i <= 100; $i++) {
    $strings[] = viewField('f' . $i, 'file');
}
$budgeted = $planner->plan($strings, 1, null, new StorageViewFieldOptions([], array_map(static fn (array $f): string => $f['key'], array_slice($strings, 0, 40))));
$varchars = array_filter($budgeted->columns, static fn ($c) => $c->sqlType === StorageViewColumn::SQL_VARCHAR);
viewLayoutExpect(StorageViewLayoutPlanner::rowBytes($budgeted->columns) <= StorageViewLayoutPlanner::ROW_BYTES_LIMIT, 'the row budget is exceeded');
viewLayoutExpect(count($varchars) > 30 && count($varchars) < 40, 'the budget did not demote only the newest strings');
viewLayoutExpect($budgeted->columns[39]->sqlType === StorageViewColumn::SQL_TEXT && !$budgeted->columns[39]->indexed
    && $budgeted->columns[0]->sqlType === StorageViewColumn::SQL_VARCHAR && $budgeted->columns[0]->indexed, 'the wrong strings were demoted');
viewLayoutExpect(StorageViewLayoutPlanner::rowBytes($planner->plan($fields, 1)->columns) < 8000, 'a typical layout counts too many bytes');

// Projection.
$projector = new StorageViewProjector();
$projected = $projector->project($ready, 'record-' . str_repeat('a', 32), 3, 1, str_repeat('f', 64), [
    'larena_scope_ref' => 'scope:alpha',
    'larena_record_state' => 'active',
    'title' => 'Красный Стол',
    'code' => 'T-1',
    'body' => "Line one\nLine TWO",
    'qty' => 7,
    'price' => '12.50',
    'day' => '2026-10-20',
    'at' => '2026-10-20T08:30:00',
    'flag' => false,
    'status' => 'a',
    'tags' => ['a', 'b'],
    'owner' => 'user:admin:42',
], '2026-10-20 08:00:00', '2026-10-20 09:00:00');
$row = $projected['row'];
viewLayoutExpect($row['scope_ref'] === 'scope:alpha' && $row['state'] === 'active' && $row['revision'] === 3, 'system columns are wrong');
viewLayoutExpect($row['title'] === 'Красный Стол', 'the title field was not picked');
viewLayoutExpect($row['search_text'] === null && $projected['search'] === ['record_id' => 'record-' . str_repeat('a', 32), 'search_text' => ' красный стол'], 'the search row is not the text of the layout search fields');
viewLayoutExpect($row['c4'] === 7 && $row['c5'] === '12.50' && $row['c7'] === '2026-10-20 08:30:00' && $row['c8'] === 0, 'typed values are wrong');
viewLayoutExpect($row['c11'] === null && array_key_exists('c13', $row), 'a missing value is not null');
viewLayoutExpect(array_map(static fn (array $m): string => $m['field_key'] . '=' . $m['value'], $projected['multi']) === ['tags=a', 'tags=b'], 'side rows are wrong');
viewLayoutRejects(static fn () => $projector->project($ready, 'record-' . str_repeat('a', 32), 1, 1, str_repeat('f', 64), ['price' => '1' . str_repeat('0', 35)], 'x', 'x'), 'storage_view_number_out_of_range');
viewLayoutRejects(static fn () => $projector->project($ready, 'record-' . str_repeat('a', 32), 1, 1, str_repeat('f', 64), ['price' => '0.' . str_repeat('1', 31)], 'x', 'x'), 'storage_view_number_out_of_range');
$fits = $projector->project($ready, 'record-' . str_repeat('a', 32), 1, 1, str_repeat('f', 64), ['price' => '-' . str_repeat('9', 35) . '.' . str_repeat('9', 30)], 'x', 'x');
viewLayoutExpect(is_string($fits['row']['c5']), 'the widest DECIMAL(65,30) value was refused');
// Without a title or name field, the first string field is the title.
$untitled = $planner->plan([viewField('qty', 'integer'), viewField('label', 'string'), viewField('other', 'string')], 1)->promoted();
viewLayoutExpect($projector->title($untitled, ['label' => 'First', 'other' => 'Second']) === 'First', 'the first string field is not the title');

echo "StorageViewLayoutTest passed.\n";
