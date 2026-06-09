<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$modulePath = $root . '/module.yaml';
$contextPath = $root . '/.larena/launch-context.json';
$composerPath = $root . '/composer.json';
$errors = [];

$composer = is_file($composerPath) ? json_decode((string) file_get_contents($composerPath), true) : null;
$package = is_array($composer) ? (string) ($composer['name'] ?? basename($root)) : basename($root);
$module = readModuleYaml($modulePath);
$context = is_file($contextPath) ? json_decode((string) file_get_contents($contextPath), true) : null;

if ($module === []) {
    $errors[] = 'module_yaml_missing_or_unreadable';
}

if (!is_array($context)) {
    $errors[] = 'launch_context_missing_or_unreadable';
}

if (is_array($context)) {
    $modulePackage = (string) ($module['package'] ?? '');
    if ($modulePackage !== $package) {
        $errors[] = 'module_package_differs_from_composer_name';
    }

    $moduleStatus = (string) ($module['status'] ?? '');
    $contextStatus = (string) ($context['status'] ?? '');
    if ($contextStatus !== '' && $moduleStatus !== $contextStatus) {
        $errors[] = 'module_status_differs_from_launch_context_status';
    }

    $launchRef = (string) ($context['launch_record_ref'] ?? '');
    $moduleBatch = (string) ($module['batch'] ?? '');
    if ($launchRef !== '' && $moduleBatch !== '' && !str_contains($launchRef, $moduleBatch)) {
        $errors[] = 'module_batch_lags_launch_record';
    }

    $moduleFeatures = array_values($module['features'] ?? []);
    $selectedFeatures = array_values(array_filter(
        $context['selected_features'] ?? [],
        static fn ($feature): bool => isPackageOwnedFeature($package, (string) $feature),
    ));
    $missingFeatures = array_values(array_diff($selectedFeatures, $moduleFeatures));
    if ($missingFeatures !== []) {
        $errors[] = 'module_features_lag_selected_features';
    }

    $evidencePath = (string) ($context['evidence_path'] ?? '');
    if ($evidencePath !== '' && (string) ($module['evidence']['path'] ?? '') !== $evidencePath) {
        $errors[] = 'module_evidence_path_differs_from_launch_context';
    }
}

$report = [
    'schema' => 'larena.package_local_metadata_sync_check.v1',
    'package' => $package,
    'status' => $errors === [] ? 'passed' : 'failed',
    'errors' => $errors,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($errors === [] ? 0 : 1);

/**
 * @return array<string, mixed>
 */
function readModuleYaml(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $package = null;
    $status = null;
    $batch = null;
    $features = [];
    $evidence = [];
    $currentSection = null;

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (preg_match('/^([A-Za-z0-9_.-]+):\s*(.*)$/', $trimmed, $matches) === 1 && !str_starts_with($line, '  ')) {
            $key = $matches[1];
            $value = trim($matches[2], " \"'");

            $currentSection = $value === '' ? $key : null;
            if ($key === 'package') {
                $package = $value;
            } elseif ($key === 'status') {
                $status = $value;
            } elseif ($key === 'batch') {
                $batch = $value;
            }

            continue;
        }

        if ($currentSection === 'features' && str_starts_with($trimmed, '- ')) {
            $features[] = trim(substr($trimmed, 2), " \"'");
            continue;
        }

        if ($currentSection === 'evidence' && str_starts_with($line, '  ') && preg_match('/^([A-Za-z0-9_.-]+):\s*(.*)$/', $trimmed, $matches) === 1) {
            $evidence[$matches[1]] = trim($matches[2], " \"'");
        }
    }

    return [
        'package' => $package,
        'status' => $status,
        'batch' => $batch,
        'features' => $features,
        'evidence' => $evidence,
    ];
}

function isPackageOwnedFeature(string $packageName, string $feature): bool
{
    $slug = basename(str_replace('\\', '/', $packageName));
    $prefixes = [
        $slug . '.',
        str_replace('-', '_', $slug) . '.',
    ];

    foreach (array_unique($prefixes) as $prefix) {
        if (str_starts_with($feature, $prefix)) {
            return true;
        }
    }

    return false;
}
