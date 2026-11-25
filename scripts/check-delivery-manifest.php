<?php

declare(strict_types=1);

/*
 * Checks the `delivery` block of module.yaml: what the package gives, whether it is open or closed, what core and
 * packages it works with, and what stays when it is removed (owner decision 2026-11-25, variant D: sets plus
 * updates per package; paid or free is catalog data, not code).
 *
 * The same file sits in every package. It reads only this package, its composer.json and, when the Specs checkout
 * is found next to the workspace (or at LARENA_SPECS_ROOT), the package's Specs distribution and the capability
 * registry. Without Specs (package CI) the Specs comparisons are reported as skipped; the local hook always has it.
 *
 * There is no `version` key on purpose: a package's version comes from its release tag, not from the manifest.
 */

$root = dirname(__DIR__);
$errors = [];

$composer = json_decode((string) @file_get_contents($root . '/composer.json'), true);
if (!is_array($composer)) {
    $errors[] = 'composer_json_missing_or_unreadable';
    $composer = [];
}
$package = (string) ($composer['name'] ?? '');
$slug = basename(str_replace('\\', '/', $package));

$delivery = readDeliveryBlock($root . '/module.yaml', $errors);

const DELIVERY_KEYS = [
    'publisher', 'distribution', 'license', 'requires', 'capabilities', 'requires_capabilities',
    'data_on_removal', 'migrations_reversible',
];
const DATA_ON_REMOVAL = ['kept_read_only', 'kept', 'none'];
// Specs keeps human licence labels; module.yaml and composer.json keep the one SPDX value they stand for.
const SPECS_LICENSE_SPDX = [
    'Apache-2.0' => 'Apache-2.0',
    'SIMAI Commercial' => 'LicenseRef-SIMAI-Commercial',
    'SIMAI Proprietary' => 'LicenseRef-SIMAI-Proprietary',
];

if ($delivery !== null) {
    foreach (DELIVERY_KEYS as $key) {
        if (!array_key_exists($key, $delivery)) {
            $errors[] = 'delivery_key_missing:' . $key;
        }
    }
    foreach (array_keys($delivery) as $key) {
        if ($key === 'version') {
            $errors[] = 'delivery_version_not_allowed:the version comes from the release tag';
        } elseif (!in_array($key, DELIVERY_KEYS, true)) {
            $errors[] = 'delivery_key_unknown:' . $key;
        }
    }

    $publisher = $delivery['publisher'] ?? null;
    if (!is_string($publisher) || preg_match('/^[a-z0-9][a-z0-9-]*$/', $publisher) !== 1) {
        $errors[] = 'delivery_publisher_invalid';
    }

    $distribution = $delivery['distribution'] ?? null;
    if (!in_array($distribution, ['open', 'closed'], true)) {
        $errors[] = 'delivery_distribution_invalid:open or closed';
    }

    $license = $delivery['license'] ?? null;
    $composerLicense = $composer['license'] ?? null;
    if (is_array($composerLicense)) {
        $composerLicense = count($composerLicense) === 1 ? (string) reset($composerLicense) : null;
    }
    if (!is_string($license) || preg_match('/^(LicenseRef-[A-Za-z0-9.-]+|[A-Za-z0-9.+-]+)$/', $license) !== 1) {
        $errors[] = 'delivery_license_not_one_spdx_value';
    } else {
        if ($license !== $composerLicense) {
            $errors[] = 'delivery_license_differs_from_composer_license';
        }
        $isRef = str_starts_with($license, 'LicenseRef-');
        if ($distribution === 'open' && $isRef) {
            $errors[] = 'delivery_open_package_has_closed_license';
        }
        if ($distribution === 'closed' && !$isRef) {
            $errors[] = 'delivery_closed_package_has_open_license';
        }
    }

    $requires = $delivery['requires'] ?? null;
    $composerLarena = [];
    foreach (($composer['require'] ?? []) as $name => $constraint) {
        if (str_starts_with((string) $name, 'larena/')) {
            $composerLarena[(string) $name] = (string) $constraint;
        }
    }
    if (!is_array($requires)) {
        $errors[] = 'delivery_requires_missing';
    } else {
        foreach (array_keys($requires) as $key) {
            if (!in_array($key, ['larena', 'packages'], true)) {
                $errors[] = 'delivery_requires_key_unknown:' . $key;
            }
        }
        $core = $requires['larena'] ?? null;
        if (!is_string($core) || trim($core) === '') {
            $errors[] = 'delivery_requires_larena_missing';
        } elseif (isset($composerLarena['larena/core']) && $composerLarena['larena/core'] !== $core) {
            $errors[] = 'delivery_requires_larena_differs_from_composer_core_constraint';
        }
        $packages = $requires['packages'] ?? null;
        if (!is_array($packages)) {
            $errors[] = 'delivery_requires_packages_missing';
        } else {
            $expected = $composerLarena;
            unset($expected['larena/core']);
            ksort($expected);
            $declared = array_map('strval', $packages);
            ksort($declared);
            if ($declared !== $expected) {
                $errors[] = 'delivery_requires_packages_differ_from_composer_require';
            }
        }
    }

    $capabilities = $delivery['capabilities'] ?? null;
    $required = $delivery['requires_capabilities'] ?? null;
    if (!isStringList($capabilities)) {
        $errors[] = 'delivery_capabilities_not_a_list';
        $capabilities = [];
    } elseif (count(array_unique($capabilities)) !== count($capabilities)) {
        $errors[] = 'delivery_capabilities_duplicate';
    }
    if (!isStringList($required)) {
        $errors[] = 'delivery_requires_capabilities_not_a_list';
        $required = [];
    } else {
        if (array_diff($required, $capabilities) !== []) {
            $errors[] = 'delivery_requires_capabilities_not_declared_by_package';
        }
        if ($distribution === 'open' && $required !== []) {
            $errors[] = 'delivery_open_package_requires_capabilities';
        }
        if ($distribution === 'closed' && $required === []) {
            $errors[] = 'delivery_closed_package_without_required_capability';
        }
    }

    $migrations = glob($root . '/database/migrations/{,*/,*/*/}*.php', GLOB_BRACE) ?: [];
    $dataOnRemoval = $delivery['data_on_removal'] ?? null;
    if (!in_array($dataOnRemoval, DATA_ON_REMOVAL, true)) {
        $errors[] = 'delivery_data_on_removal_invalid:' . implode('|', DATA_ON_REMOVAL);
    } elseif ($dataOnRemoval === 'none' && $migrations !== []) {
        $errors[] = 'delivery_data_on_removal_none_but_package_has_tables';
    }

    $reversible = $delivery['migrations_reversible'] ?? null;
    if (!is_bool($reversible)) {
        $errors[] = 'delivery_migrations_reversible_not_boolean';
    } elseif ($reversible) {
        foreach ($migrations as $migration) {
            if (preg_match('/function\s+down\s*\(/', (string) file_get_contents($migration)) !== 1) {
                $errors[] = 'delivery_migration_without_down:' . substr($migration, strlen($root) + 1);
            }
        }
    }

    $specs = findSpecs($root);
    $specsChecks = 'skipped_specs_not_found';
    if ($specs !== null) {
        $specsChecks = 'passed';
        $registry = json_decode((string) file_get_contents($specs . '/specs/capabilities/registry.json'), true);
        $byId = [];
        $own = [];
        $boundary = null;
        foreach (($registry['capabilities'] ?? []) as $entry) {
            $byId[(string) $entry['id']] = $entry;
            if (($entry['package'] ?? null) === $package) {
                $own[] = (string) $entry['id'];
            }
        }
        foreach (($registry['packages'] ?? []) as $entry) {
            if (($entry['package_id'] ?? null) === $package) {
                $boundary = (string) ($entry['boundary_kind'] ?? '');
            }
        }
        foreach ($capabilities as $id) {
            if (!isset($byId[$id])) {
                $errors[] = 'delivery_capability_not_in_registry:' . $id;
            } elseif (($byId[$id]['package'] ?? null) !== $package) {
                $errors[] = 'delivery_capability_owned_by_other_package:' . $id;
            }
        }
        sort($own);
        $sortedCapabilities = $capabilities;
        sort($sortedCapabilities);
        if ($own !== $sortedCapabilities) {
            $errors[] = 'delivery_capabilities_differ_from_registry';
        }
        foreach ($required as $id) {
            if (isset($byId[$id]) && ($byId[$id]['default_tier'] ?? null) === 'free') {
                $errors[] = 'delivery_required_capability_is_free:' . $id;
            }
        }
        if ($boundary !== null) {
            $closedBoundary = in_array($boundary, ['commercial_artifact', 'server_authority'], true);
            if (($distribution === 'closed') !== $closedBoundary) {
                $errors[] = 'delivery_distribution_differs_from_registry_boundary:' . $boundary;
            }
        }

        $specsPackage = json_decode((string) @file_get_contents($specs . '/specs/packages/' . $slug . '/package.json'), true);
        if (!is_array($specsPackage)) {
            $specsChecks = 'passed_package_not_in_specs';
        } else {
            $specsLicense = (string) ($specsPackage['distribution']['license'] ?? '');
            if ((SPECS_LICENSE_SPDX[$specsLicense] ?? null) !== $license) {
                $errors[] = 'delivery_license_differs_from_specs:' . $specsLicense;
            }
            $visibility = (string) ($specsPackage['distribution']['repository_visibility'] ?? '');
            if (($visibility === 'public') !== ($distribution === 'open')) {
                $errors[] = 'delivery_distribution_differs_from_specs_repository_visibility:' . $visibility;
            }
        }
    }
}

$report = [
    'schema' => 'larena.package_delivery_manifest_check',
    'package' => $package,
    'status' => $errors === [] ? 'passed' : 'failed',
    'specs_checks' => $specsChecks ?? 'not_run',
    'errors' => $errors,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($errors === [] ? 0 : 1);

function isStringList(mixed $value): bool
{
    if (!is_array($value) || !array_is_list($value)) {
        return false;
    }
    foreach ($value as $item) {
        if (!is_string($item) || $item === '') {
            return false;
        }
    }

    return true;
}

function findSpecs(string $root): ?string
{
    foreach ([getenv('LARENA_SPECS_ROOT') ?: '', dirname($root, 3) . '/larena-specs', dirname($root, 2) . '/larena-specs'] as $candidate) {
        if ($candidate !== '' && is_file($candidate . '/specs/capabilities/registry.json')) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Reads the top-level `delivery:` block: two-space indentation, scalars, `- item` lists, `[]` and `{}`.
 *
 * @param list<string> $errors
 * @return array<string, mixed>|null
 */
function readDeliveryBlock(string $path, array &$errors): ?array
{
    $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
    $block = [];
    $inside = false;
    foreach ($lines as $line) {
        if (!$inside) {
            if (rtrim($line) === 'delivery:') {
                $inside = true;
            }
            continue;
        }
        if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
            continue;
        }
        if (!str_starts_with($line, ' ')) {
            break;
        }
        $block[] = $line;
    }
    if (!$inside) {
        $errors[] = 'delivery_block_missing';

        return null;
    }
    $position = 0;
    $value = parseNode($block, $position, 2, $errors);
    if ($position < count($block)) {
        $errors[] = 'delivery_block_unparsed_line:' . trim($block[$position]);
    }

    if (!is_array($value) || ($value !== [] && array_is_list($value))) {
        $errors[] = 'delivery_block_not_a_mapping';

        return null;
    }

    return $value;
}

/**
 * @param list<string> $lines
 * @param list<string> $errors
 */
function parseNode(array $lines, int &$position, int $indent, array &$errors): mixed
{
    $result = [];
    $isList = null;
    while ($position < count($lines)) {
        $line = $lines[$position];
        $current = strlen($line) - strlen(ltrim($line, ' '));
        if ($current < $indent) {
            break;
        }
        if ($current > $indent) {
            $errors[] = 'delivery_block_bad_indentation:' . trim($line);
            $position++;
            continue;
        }
        $text = trim($line);
        if (str_starts_with($text, '- ')) {
            if ($isList === false) {
                $errors[] = 'delivery_block_mixed_list_and_mapping:' . $text;
            }
            $isList = true;
            $result[] = scalar(substr($text, 2));
            $position++;
            continue;
        }
        if (preg_match('/^([A-Za-z0-9_\/.-]+):(?:\s+(.*))?$/', $text, $matches) !== 1) {
            $errors[] = 'delivery_block_unreadable_line:' . $text;
            $position++;
            continue;
        }
        if ($isList === true) {
            $errors[] = 'delivery_block_mixed_list_and_mapping:' . $text;
        }
        $isList = false;
        $position++;
        $raw = $matches[2] ?? '';
        $result[$matches[1]] = $raw === '' ? parseNode($lines, $position, $indent + 2, $errors) : scalar($raw);
    }

    return $result;
}

function scalar(string $raw): mixed
{
    $raw = trim(preg_replace('/\s+#.*$/', '', $raw) ?? $raw);
    if ($raw === '[]' || $raw === '{}') {
        return [];
    }
    if ($raw === 'true' || $raw === 'false') {
        return $raw === 'true';
    }
    if (strlen($raw) >= 2 && ($raw[0] === '"' || $raw[0] === "'") && $raw[-1] === $raw[0]) {
        return substr($raw, 1, -1);
    }

    return $raw;
}
