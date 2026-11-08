<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Translation\Translator;
use Larena\Storage\Admin\StorageCreatedChart;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewCreatedPerDay;
use Larena\Storage\View\StorageViewNaming;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// New records per day for the Storage section's chart (SIMAI Chart, sf-chart-line), on SQLite:
// - nothing while the storage's витрина is not ready; the read never builds it;
// - every day of the period, a day without records as 0; the edges of the period; archived records count;
// - the read walks the (state, created_at, record_id) index of the витрина, never the table;
// - the list's right and scope; a period outside 1..90 days is refused;
// - the chart's settings: the days as items, the words from the lang files; no chart without new records.

$path = tempnam(sys_get_temp_dir(), 'larena-storage-created-per-day-');
if (!is_string($path)) {
    throw new RuntimeException('storage_created_per_day_tempfile_failed');
}
$container = new Container();
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $connection->getSchemaBuilder());
Facade::clearResolvedInstances();
Schema::swap($connection->getSchemaBuilder());

try {
    $actor = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';
    storageViewInstall($connection);
    $runtime = storageViewRuntime($connection, new SqliteStorageViewDdl($connection), $actor, $scope);
    $workbench = $runtime['workbench'];
    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.notes', 'label' => 'Notes',
        'fields' => [storageViewField('title', 10, 'string', [], true)]], $actor);
    $ids = [];
    foreach (['One', 'Two', 'Three', 'Four', 'Five', 'Six'] as $title) {
        $ids[] = $workbench->createRecord($scope, $structure->structureId, ['title' => $title], $actor)->recordId;
    }
    $table = StorageViewNaming::table($structure->schema->schemaId);

    // The list makes the витрина ready (a small storage, in the request); the chart only reads it.
    $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, $structure->structureId, [], null, [], 20), $actor);
    storageViewExpect($connection->getSchemaBuilder()->hasTable($table), 'the list did not build the витрина');
    // While the витрина is being built there is no chart, and asking for it builds nothing.
    $connection->table('larena_storage_views')->where('schema_id', $structure->schema->schemaId)->update(['state' => 'building']);
    $runtime['views']->registry()->forget();
    storageViewExpect($workbench->recordsCreatedPerDay($scope, $structure->structureId, $actor, 30, '2026-11-08') === null,
        'the chart answered while the витрина was being built');
    storageViewExpect($connection->table('larena_storage_views')->where('schema_id', $structure->schema->schemaId)->value('state') === 'building',
        'the chart touched the витрина build');
    $connection->table('larena_storage_views')->where('schema_id', $structure->schema->schemaId)->update(['state' => 'ready']);
    $runtime['views']->registry()->forget();

    // Creation times at the edges of a 30-day period ending 2026-11-08 (its first day is 2026-10-10).
    $times = ['2026-10-09 23:59:59', '2026-10-10 00:00:00', '2026-11-01 10:00:00', '2026-11-01 23:59:59', '2026-11-08 23:59:59', '2026-11-09 00:00:00'];
    foreach ($ids as $index => $recordId) {
        $connection->table($table)->where('record_id', $recordId)->update(['created_at' => $times[$index]]);
    }
    // An archived record still counts: it was created that day.
    $connection->table($table)->where('record_id', $ids[3])->update(['state' => 'archived']);

    $days = $workbench->recordsCreatedPerDay($scope, $structure->structureId, $actor, 30, '2026-11-08');
    storageViewExpect(is_array($days) && count($days) === 30, 'the chart did not get 30 days');
    storageViewExpect($days[0] === ['day' => '2026-10-10', 'created' => 1] && $days[29] === ['day' => '2026-11-08', 'created' => 1],
        'the first or last day is wrong: ' . json_encode([$days[0], $days[29]]));
    $byDay = array_column($days, 'created', 'day');
    storageViewExpect($byDay['2026-11-01'] === 2 && array_sum($byDay) === 4, 'the days were counted wrong: ' . json_encode($byDay));
    storageViewExpect($byDay['2026-10-20'] === 0, 'a day without records is not 0');
    $week = $workbench->recordsCreatedPerDay($scope, $structure->structureId, $actor, 7, '2026-11-08');
    storageViewExpect(is_array($week) && count($week) === 7 && $week[0]['day'] === '2026-11-02' && array_sum(array_column($week, 'created')) === 1,
        'a 7-day period is wrong');

    // The read is one walk of the витрина's (state, created_at) index.
    $plan = $connection->select('explain query plan select date(created_at) as day, count(*) as created from "' . $table
        . '" where state in (?, ?) and created_at >= ? and created_at < ? group by date(created_at)', ['active', 'archived', '2026-10-10 00:00:00', '2026-11-09 00:00:00']);
    $details = implode(' | ', array_map(static fn (object $row): string => (string) ($row->detail ?? ''), $plan));
    storageViewExpect(str_contains($details, 'USING COVERING INDEX') && !str_contains($details, 'SCAN ' . $table . ' ') && !str_ends_with($details, 'SCAN ' . $table),
        'the read does not walk the index: ' . $details);

    // Rights, scope and the period.
    storageViewRejects(static fn () => $workbench->recordsCreatedPerDay($scope, $structure->structureId, 'actor:admin:beta', 30, '2026-11-08'), 'storage_workbench_scope_denied');
    storageViewRejects(static fn () => $workbench->recordsCreatedPerDay($scope, $structure->structureId, $actor, 0, '2026-11-08'), 'storage_workbench_activity_days_invalid');
    storageViewRejects(static fn () => $workbench->recordsCreatedPerDay($scope, $structure->structureId, $actor, StorageViewCreatedPerDay::MAX_DAYS + 1, '2026-11-08'), 'storage_workbench_activity_days_invalid');
    storageViewRejects(static fn () => $workbench->recordsCreatedPerDay($scope, $structure->structureId, $actor, 30, '2026-02-30'), 'storage_workbench_activity_days_invalid');
    storageViewRejects(static fn () => $workbench->recordsCreatedPerDay($scope, 'workbench.missing', $actor, 30, '2026-11-08'), 'storage_workbench_structure_unknown');

    // The chart's settings, in the words of the lang files.
    $words = (require __DIR__ . '/../../resources/lang/ru/admin.php')['section']['created_chart'];
    $translator = new class ($words) implements Translator {
        /** @param array<string, string> $words */
        public function __construct(private array $words)
        {
        }

        public function get($key, array $replace = [], $locale = null)
        {
            $prefix = 'larena-storage::admin.section.created_chart.';

            return str_starts_with($key, $prefix) ? ($this->words[substr($key, strlen($prefix))] ?? $key) : $key;
        }

        public function choice($key, $number, array $replace = [], $locale = null)
        {
            return $this->get($key, $replace, $locale);
        }

        public function getLocale()
        {
            return 'ru';
        }

        public function setLocale($locale)
        {
        }
    };
    $chart = new StorageCreatedChart($translator);
    $props = $chart->props($days);
    storageViewExpect($props['label'] === $words['label'] && $props['names'] === ['created' => $words['series']] && $props['y-label'] === $words['axis'],
        'the chart does not speak the lang file\'s words');
    storageViewExpect($props['items'] === $days && $props['x'] === 'day' && $props['y'] === 'created' && $props['x-scale'] === 'time'
        && $props['y-zero'] === true && $props['y-format'] === 'integer', 'the chart\'s settings are wrong');
    $en = (require __DIR__ . '/../../resources/lang/en/admin.php')['section']['created_chart'];
    storageViewExpect(array_keys($en) === array_keys($words), 'the ru and en words of the chart differ in keys');
    // No chart without new records, or without an answer.
    storageViewExpect($chart->markup(array_map(static fn (array $day): array => ['day' => $day['day'], 'created' => 0], $days)) === null
        && $chart->markup(null) === null, 'an empty period drew a chart');
    // With records the pinned runtime draws sf-chart-line, or (a runtime that does not pin the Chart set) nothing.
    $markup = $chart->markup($days);
    storageViewExpect($markup === null || str_contains($markup->toHtml(), '<sf-chart-line'), 'the chart is not sf-chart-line');
} finally {
    Facade::clearResolvedInstances();
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageCreatedPerDayTest passed.\n";
