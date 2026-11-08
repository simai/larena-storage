<?php

declare(strict_types=1);

use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\View\StorageViewColumn;
use Larena\Storage\View\StorageViewLayoutPlanner;

require __DIR__ . '/StorageWorkbenchTest.php';

// Documents and forms in a storage (Property money@1, percent@1, items@1, ru.requisites@1; property-view-port 1.18.0):
// Storage itself keeps an amount and a percent as decimal strings and checks every row through Property on each write,
// whoever writes (the API, an import, a site pack), not only the admin's panel.

$documentPath = tempnam(sys_get_temp_dir(), 'larena-workbench-documents-');
if (!is_string($documentPath)) {
    throw new RuntimeException('document_types_tempfile_failed');
}
$documentOpened = workbenchOpen($documentPath);
try {
    workbenchInstall();
    $runtime = workbenchRuntime($documentOpened['connection']);
    $workbench = $runtime['workbench'];
    $actor = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';

    $field = static fn (string $key, int $position, string $type, array $constraints = []): array => [
        'key' => $key, 'label' => ucfirst($key), 'position' => $position, 'type' => $type, 'type_version' => 1,
        'required' => false, 'visibility' => 'admin', 'constraints' => $constraints,
    ];
    $rub = static fn (string $amount): array => ['amount' => $amount, 'currency' => 'RUB'];
    $columns = [
        ['key' => 'name', 'label' => 'Наименование', 'type' => 'string', 'required' => true],
        ['key' => 'qty', 'label' => 'Кол-во', 'type' => 'number'],
        ['key' => 'price', 'label' => 'Цена', 'type' => 'money', 'settings' => ['currency' => 'RUB']],
        ['key' => 'sum', 'label' => 'Сумма', 'type' => 'money', 'settings' => ['currency' => 'RUB'],
            'compute' => ['op' => 'multiply', 'of' => ['qty', 'price']], 'total' => 'sum'],
    ];
    $structure = $workbench->createStructure($scope, [
        'structure_id' => 'workbench.invoice_probe',
        'label' => 'Invoice probe',
        'fields' => [
            $field('title', 10, 'string', ['max_length' => 100]),
            $field('price', 20, 'money'),
            $field('fee', 30, 'money', ['currency' => 'RUB', 'min' => '0']),
            $field('discount', 40, 'percent'),
            $field('lines', 50, 'items', ['columns' => $columns, 'min_rows' => 1, 'max_rows' => 3]),
            $field('party', 60, 'ru.requisites', ['parts' => ['inn', 'kpp']]),
        ],
    ], $actor);

    $create = static fn (array $values) => $workbench->createRecord($scope, $structure->structureId, $values, $actor);
    // The rows as sent: a computed cell the writer got wrong is worked out again; Storage, through Property, is the authority.
    $invoice = $create(['title' => 'Invoice', 'price' => ['amount' => '2499.990', 'currency' => 'usd'], 'fee' => 10, 'discount' => '12.50',
        'lines' => [['name' => 'Ручка', 'qty' => '3', 'price' => $rub('33.33'), 'sum' => $rub('1')], ['name' => 'Тетрадь', 'qty' => 2, 'price' => '45']],
        'party' => ['kpp' => '773601001', 'inn' => '7707 083 893']]);
    workbenchExpect($invoice->values['price'] === ['amount' => '2499.99', 'currency' => 'USD'], 'amount not kept as its canonical decimal string');
    workbenchExpect($invoice->values['fee'] === $rub('10'), 'a bare amount did not take the field\'s currency');
    workbenchExpect($invoice->values['discount'] === '12.5', 'percent not kept as its canonical decimal string');
    workbenchExpect($invoice->values['lines'] === [
        ['name' => 'Ручка', 'qty' => '3', 'price' => $rub('33.33'), 'sum' => $rub('99.99')],
        ['name' => 'Тетрадь', 'qty' => '2', 'price' => $rub('45'), 'sum' => $rub('90')],
    ], 'rows not normalized or a computed cell not worked out: ' . json_encode($invoice->values['lines'], JSON_UNESCAPED_UNICODE));
    workbenchExpect($invoice->values['party'] === ['inn' => '7707083893', 'kpp' => '773601001'], 'requisites not kept in their order');
    // Storage keeps a version as canonical JSON, so an object comes back with its keys in sorted order; the rows, the
    // amounts and their digits are the same.
    $sorted = static function (mixed $value) use (&$sorted): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map($sorted, $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    };
    $read = $workbench->readRecord($scope, $structure->structureId, $invoice->recordId, $actor);
    workbenchExpect($sorted($read->values) === $sorted($invoice->values) && $read->values['discount'] === '12.5'
        && $read->values['lines'][1]['sum'] === $rub('90'), 'read back mismatch');

    // A float is never a decimal: the digits it prints are not the ones written.
    workbenchRejects(static fn () => $create(['title' => 'Float amount', 'price' => ['amount' => 0.1, 'currency' => 'RUB']]), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Float percent', 'discount' => 12.5]), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Too precise', 'price' => $rub('1.005')]), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Wrong currency', 'fee' => ['amount' => '1', 'currency' => 'USD']]), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Over 100', 'discount' => '100.01']), 'storage_record_field_invalid');
    // Rows within min_rows and max_rows, of the field's columns, the required cells filled.
    $line = ['name' => 'Ручка', 'qty' => '1', 'price' => $rub('1')];
    workbenchRejects(static fn () => $create(['title' => 'No rows', 'lines' => []]), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Many rows', 'lines' => [$line, $line, $line, $line]]), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Unknown column', 'lines' => [$line + ['note' => 'x']]]), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Nameless row', 'lines' => [['qty' => '1']]]), 'storage_record_field_invalid');
    workbenchRejects(static fn () => $create(['title' => 'Bad requisites', 'party' => ['inn' => '7707083894']]), 'storage_record_field_invalid');

    // An update goes through the same check.
    $updated = $workbench->updateRecord($scope, $structure->structureId, $invoice->recordId, $invoice->revision,
        ['title' => 'Invoice', 'discount' => '5', 'lines' => [['name' => 'Ручка', 'qty' => '0.5', 'price' => $rub('0.01')]]], $actor);
    workbenchExpect($updated->values['lines'] === [['name' => 'Ручка', 'qty' => '0.5', 'price' => $rub('0.01'), 'sum' => $rub('0.01')]],
        'an update did not work the computed cell out (0.005 rounds half away from zero to 0.01)');
    workbenchRejects(static fn () => $workbench->updateRecord($scope, $structure->structureId, $invoice->recordId, $updated->revision,
        ['title' => 'Invoice', 'lines' => [array_replace($line, ['qty' => 1.5])]], $actor), 'storage_record_field_invalid');

    // A percent sorts and filters by value, not as text («100» after «20»).
    $create(['title' => 'Twenty', 'discount' => '20']);
    $create(['title' => 'Hundred', 'discount' => '100']);
    $titles = static fn (StorageWorkbenchRecordQuery $query): array => array_map(static fn ($record): string => $record->values['title'],
        $workbench->listRecords($query, $actor)->items);
    workbenchExpect($titles(new StorageWorkbenchRecordQuery($scope, $structure->structureId, filters: ['discount' => ['operator' => 'gt', 'value' => '19.5']],
        sort: [['field' => 'discount', 'direction' => 'asc']], limit: 10)) === ['Twenty', 'Hundred'], 'percent filter or sort is not by value');
    workbenchExpect($titles(new StorageWorkbenchRecordQuery($scope, $structure->structureId, filters: ['discount' => ['operator' => 'eq', 'value' => '5.0']],
        limit: 10)) === ['Invoice'], 'a percent filter value is not read as Property reads it');

    // Columns: a percent is a decimal; an amount, rows and requisites are kept as JSON text and are not filtered.
    workbenchExpect(StorageViewLayoutPlanner::sqlType('percent', []) === StorageViewColumn::SQL_DECIMAL
        && StorageViewLayoutPlanner::sqlType('money', []) === StorageViewColumn::SQL_TEXT
        && StorageViewLayoutPlanner::sqlType('items', []) === StorageViewColumn::SQL_TEXT, 'document type columns mismatch');
    workbenchRejects(static fn () => $titles(new StorageWorkbenchRecordQuery($scope, $structure->structureId,
        filters: ['price' => ['operator' => 'eq', 'value' => '1']], limit: 10)), 'storage_query_filter_operator_unsupported');
} finally {
    @unlink($documentPath);
}

echo "StorageDocumentTypesFieldTest passed.\n";
