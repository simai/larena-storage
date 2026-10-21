<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Larena\Property\Contracts\PropertyTypeRegistry as PropertyTypeRegistryContract;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Console\Commands\StorageBenchCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require_once __DIR__ . '/../../vendor/autoload.php';

// `larena:storage:bench` on a tiny throwaway SQLite database: every operation is
// measured (with the time the витрина takes to build), lists above 5000 records work,
// the default connection is refused, and nothing is left behind.
function benchExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'larena-storage-bench-test-');
$json = tempnam(sys_get_temp_dir(), 'larena-storage-bench-json-');
benchExpect(is_string($path) && is_string($json), 'tempfile failed');
$container = new class extends Container {
    public function runningUnitTests(): bool
    {
        return true;
    }
};
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true], 'benchmark');
$capsule->setAsGlobal();
$container->instance(DatabaseManager::class, $capsule->getDatabaseManager());
$container->instance('db', $capsule->getDatabaseManager());
$container->instance(PropertyTypeRegistryContract::class, PropertyTypeRegistry::builtIns());

$run = static function (array $input) use ($container): array {
    $command = new StorageBenchCommand();
    $command->setLaravel($container);
    $output = new BufferedOutput();
    $arguments = new ArrayInput($input);
    $arguments->setInteractive(false);
    $status = $command->run($arguments, $output);

    return [$status, $output->fetch()];
};

try {
    // The default connection is never measured, and nothing is asked of it.
    [$status] = $run(['--connection' => 'default', '--records' => '10']);
    benchExpect($status === 2, 'the default connection was not refused');
    [$status] = $run(['--records' => '10']);
    benchExpect($status === 2, 'a run without a disposable database was not refused');
    [$status] = $run(['--connection' => 'benchmark', '--records' => '0']);
    benchExpect($status === 2, 'a non-positive record count was accepted');

    [$status, $output] = $run(['--connection' => 'benchmark', '--install' => true, '--records' => '200', '--repeat' => '2', '--json' => $json]);
    benchExpect($status === 0, 'the benchmark failed: ' . $output);
    $result = json_decode((string) file_get_contents($json), true, 64, JSON_THROW_ON_ERROR);
    benchExpect($result['schema'] === 'larena.storage.benchmark' && $result['environment']['driver'] === 'sqlite', 'result header mismatch');
    $run200 = $result['runs'][0];
    benchExpect($run200['records'] === 200 && $run200['field_count'] === 20, 'the structure is not the realistic 20-field one');
    foreach ([
        'list_page_1', 'list_deep_page', 'filter_choice', 'filter_number_range', 'filter_date_range',
        'sort_number', 'sort_title', 'sort_title_desc_deep', 'sort_subtitle_unindexed', 'filter_title_prefix', 'filter_sku_eq',
        'search_text', 'search_prefix', 'search_two_words', 'search_common_word', 'search_short_term',
        'matched_count', 'matched_count_exact', 'read_record', 'update_record', 'create_record',
    ] as $operation) {
        $measured = $run200['operations'][$operation] ?? null;
        benchExpect(is_array($measured) && $measured['status'] === 'ok', $operation . ' was not measured');
        benchExpect($measured['samples'] === 2 && $measured['p50_ms'] <= $measured['p95_ms'] && $measured['p95_ms'] <= $measured['max_ms'], $operation . ' percentiles are inconsistent');
        benchExpect($measured['queries'] > 0 && $measured['peak_memory_bytes'] >= 0, $operation . ' has no query count');
    }
    benchExpect($run200['operations']['matched_count']['matched_count'] === 200, 'matched count mismatch');
    // Field changes write no record version: an added field, and a retype checked record by record.
    benchExpect(($run200['field_add']['version_rows_written'] ?? null) === 0 && ($run200['field_add']['state'] ?? null) === 'switched', 'adding a field wrote versions: ' . json_encode($run200['field_add'] ?? null));
    benchExpect(($run200['field_retype']['version_rows_written'] ?? null) === 0 && ($run200['field_retype']['state'] ?? null) === 'switched'
        && ($run200['field_retype']['schema_version'] ?? null) === ($run200['field_add']['schema_version'] ?? 0) + 1, 'the retype did not switch without versions: ' . json_encode($run200['field_retype'] ?? null));
    benchExpect($run200['operations']['matched_count_exact']['matched_count'] === 200, 'exact count mismatch');
    benchExpect($run200['operations']['filter_choice']['matched_count'] === 40, 'choice filter mismatch');
    benchExpect($run200['operations']['search_text']['matched_count'] === 2, 'search mismatch');
    // Subtitle and notes left the search and title and sku became indexed on the filled storage, without rewriting a record.
    benchExpect(is_float($run200['options_backfill_ms']) || is_int($run200['options_backfill_ms']), 'the options backfill was not timed');
    foreach (['search_prefix' => 2, 'search_two_words' => 2, 'search_short_term' => 2, 'filter_sku_eq' => 1] as $operation => $matched) {
        benchExpect($run200['operations'][$operation]['matched_count'] === $matched, $operation . ' mismatch: ' . json_encode($run200['operations'][$operation]));
    }
    benchExpect($run200['operations']['filter_title_prefix']['matched_count'] > 0 && $run200['operations']['search_common_word']['matched_count'] > 0, 'title prefix or common word matched nothing');
    // The public reads: each operation through the exact path and the site витрина, the same answers, fewer queries.
    $site200 = $run200['site'];
    benchExpect($site200['status'] === 'ok' && $site200['records'] === 200 && $site200['identical'] === true, 'the site reads were not measured or differ: ' . json_encode($site200));
    foreach (['site_projection_page_1', 'site_projection_deep', 'site_published_record', 'site_resolve_key_set_silenced'] as $operation) {
        foreach (['legacy', 'site'] as $mode) {
            $measured = $site200['operations'][$operation][$mode] ?? null;
            benchExpect(is_array($measured) && $measured['status'] === 'ok' && $measured['samples'] === 2 && $measured['queries'] > 0, $operation . ' ' . $mode . ' was not measured: ' . json_encode($measured));
        }
    }
    foreach (['site_projection_page_1', 'site_projection_deep', 'site_published_record'] as $operation) {
        benchExpect($site200['operations'][$operation]['site']['queries'] <= $site200['operations'][$operation]['legacy']['queries'], $operation . ' took more queries through the site витрина');
    }
    // A key through the slot costs the same few queries however many records are published.
    benchExpect($site200['operations']['site_resolve_key_set_silenced']['site']['queries'] <= 5, 'a key through the slot took too many queries: ' . json_encode($site200['operations']));
    benchExpect($site200['operations']['site_projection_page_1']['site']['queries'] <= 3, 'a site page took more than three queries');
    // The public lists: answered from the records at this size and from the slot, the same totals; the slot in a few queries.
    foreach (['site_list_filter_choice', 'site_list_sort_number', 'site_list_search', 'site_list_count_auto'] as $operation) {
        $legacyList = $site200['operations'][$operation]['legacy'] ?? null;
        $siteList = $site200['operations'][$operation]['site'] ?? null;
        benchExpect(($legacyList['status'] ?? null) === 'ok' && ($siteList['status'] ?? null) === 'ok' && $legacyList['matched_count'] === $siteList['matched_count'], $operation . ' differs: ' . json_encode([$legacyList, $siteList]));
        benchExpect($siteList['queries'] <= 6, $operation . ' took ' . $siteList['queries'] . ' queries through the slot');
    }
    benchExpect($site200['operations']['site_list_count_auto']['site']['matched_count'] === 80, 'the auto count of two statuses of five is wrong: ' . json_encode($site200['operations']['site_list_count_auto']));
    benchExpect(($site200['cleanup']['method'] ?? null) === 'owned_rows_deleted', 'the site storage was not cleaned up');
    foreach ($run200['cleanup'] as $cleanup) {
        benchExpect($cleanup['method'] === 'engine_purge', 'a small structure was not purged through the engine');
    }
    $connection = $capsule->getConnection('benchmark');
    benchExpect($connection->table('larena_storage_records')->count() === 0, 'records were left behind');
    benchExpect($connection->table('larena_storage_record_versions')->count() === 0, 'versions were left behind');
    benchExpect($connection->table('larena_storage_workbench_structures')->count() === 0, 'structures were left behind');
    benchExpect($connection->table('larena_storage_publication_states')->count() === 0 && $connection->table('larena_storage_site_views')->count() === 0
        && $connection->table('larena_storage_site_view_slots')->count() === 0, 'site rows were left behind');
    benchExpect(preg_grep('/\\Alarena_storage_site_[0-9a-f]{24}/', array_column($connection->getSchemaBuilder()->getTables(), 'name')) === [], 'site tables were left behind');

    // Above 5000 records the list still reads the витрина, and the build was timed.
    [$status, $output] = $run(['--connection' => 'benchmark', '--records' => '5001', '--repeat' => '1', '--json' => $json]);
    benchExpect($status === 0, 'the over-limit run failed: ' . $output);
    $large = json_decode((string) file_get_contents($json), true, 64, JSON_THROW_ON_ERROR)['runs'][0];
    benchExpect(is_float($large['view_build_ms']) || is_int($large['view_build_ms']), 'the витрина build was not timed');
    benchExpect($large['operations']['list_page_1']['status'] === 'ok'
        && $large['operations']['matched_count']['matched_count'] === 5001, 'a list above 5000 records did not work: ' . json_encode($large['operations']['list_page_1']));
    benchExpect($large['operations']['read_record']['status'] === 'ok' && $large['operations']['create_record']['status'] === 'ok', 'single-record operations stopped');
    benchExpect(in_array('owned_rows_deleted', array_column($large['cleanup'], 'method'), true), 'a structure above the purge limit was not cleaned up');
    benchExpect($connection->table('larena_storage_records')->count() === 0, 'over-limit records were left behind');

    // The provider registers the command.
    $provider = (string) file_get_contents(__DIR__ . '/../../src/Providers/StorageServiceProvider.php');
    benchExpect(str_contains($provider, 'StorageBenchCommand::class'), 'the command is not registered');
} finally {
    $capsule->getDatabaseManager()->disconnect('benchmark');
    @unlink($path);
    @unlink($json);
}

echo "Storage bench command passed.\n";
