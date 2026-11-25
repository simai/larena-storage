<?php

declare(strict_types=1);

// Builds the scenarios' demo packs (SitePacks of the site pages) into core's resources/scenarios/demo, each on a
// disposable SQLite installation: `php scripts/build-scenario-demo-packs.php [<target folder>]`. What each pack holds
// is in tests/Support/storage-scenario-demo-fixture.php; run it again after changing that, then the storage tests.

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/Support/storage-scenario-demo-fixture.php';

$target = $argv[1] ?? dirname(scenarioDemoPackPath('blog'));
if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
    fwrite(STDERR, "The target folder cannot be made.\n");
    exit(1);
}
foreach (scenarioDemoContent() as $scenarioId => $content) {
    $file = rtrim($target, '/') . '/' . $scenarioId . '.zip';
    scenarioDemoBuild($content, $file);
    echo $scenarioId . ': ' . filesize($file) . " bytes\n";
}
