<?php

declare(strict_types=1);

$files = [];
foreach (['scripts', 'tools'] as $path) {
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);
foreach ($files as $file) {
    passthru('php -l ' . escapeshellarg($file), $exitCode);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}
echo 'Linted ' . count($files) . " PHP file(s).\n";
