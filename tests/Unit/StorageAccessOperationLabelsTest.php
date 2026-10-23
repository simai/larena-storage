<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Storage\Providers\StorageServiceProvider;

// Every access operation Storage registers is named in both languages, and the names are loaded under the namespace
// its label keys use (acceptance 2026-10-23: a role's rights list showed «storage.workbench.record.read» and the like,
// since the larena-storage namespace was never loaded and some keys had no text).
$registry = new AccessOperationRegistry();
$register = new ReflectionMethod(StorageServiceProvider::class, 'registerAccessOperations');
$register->invoke(null, $registry);
$operations = $registry->all();
if (count($operations) < 40) {
    throw new RuntimeException('Storage registers its access operations, got ' . count($operations));
}

$labels = ['ru' => require __DIR__ . '/../../resources/lang/ru/operations.php', 'en' => require __DIR__ . '/../../resources/lang/en/operations.php'];
foreach ($operations as $operation) {
    if (!str_starts_with($operation->labelKey, 'larena-storage::operations.')) {
        throw new RuntimeException($operation->code . ' is not labelled from the storage operations file');
    }
    $key = substr($operation->labelKey, strlen('larena-storage::operations.'));
    foreach ($labels as $locale => $lines) {
        if (!is_string($lines[$key] ?? null) || trim($lines[$key]) === '') {
            throw new RuntimeException($operation->code . ' has no ' . $locale . ' label (' . $key . ')');
        }
    }
}
$ru = array_keys($labels['ru']);
$en = array_keys($labels['en']);
sort($ru);
sort($en);
if ($ru !== $en) {
    throw new RuntimeException('the ru and en operation names hold the same keys');
}

// The provider loads the names under the namespace the keys use.
$source = (string) file_get_contents((new ReflectionClass(StorageServiceProvider::class))->getFileName());
if (preg_match("~loadTranslationsFrom\\(__DIR__ \\. '/\\.\\./\\.\\./resources/lang', 'larena-storage'\\)~", $source) !== 1) {
    throw new RuntimeException('the provider loads resources/lang as larena-storage');
}

echo 'StorageAccessOperationLabelsTest passed: ' . count($operations) . " operations named in ru and en.\n";
