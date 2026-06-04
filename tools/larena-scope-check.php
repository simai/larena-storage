<?php

declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function normalize_path(string $path): string
{
    $path = str_replace('\\\\', '/', $path);
    $path = preg_replace('#^/+#', '', $path) ?? $path;
    return rtrim($path, '/');
}

function matches_pattern(string $file, string $pattern): bool
{
    $file = normalize_path($file);
    $pattern = normalize_path($pattern);
    if ($file === $pattern) {
        return true;
    }
    if (str_ends_with($pattern, '/*')) {
        return str_starts_with($file, substr($pattern, 0, -1));
    }
    return false;
}

function run_git(array $args): string
{
    $escaped = array_map('escapeshellarg', array_merge(['git'], $args));
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $escaped) . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        fail('Git command failed: git ' . implode(' ', $args) . PHP_EOL . implode(PHP_EOL, $output));
    }
    return trim(implode(PHP_EOL, $output));
}

$context = json_decode((string) file_get_contents('.larena/launch-context.json'), true);
if (!is_array($context)) {
    fail('Invalid launch context JSON.');
}
$baseCommit = getenv('LARENA_SCOPE_BASE') ?: ($context['base_commit'] ?? '');
if (!is_string($baseCommit) || trim($baseCommit) === '') {
    fail('Missing base commit.');
}
$targetCommit = getenv('LARENA_SCOPE_TARGET') ?: 'HEAD';
$allowedFiles = array_map('normalize_path', $context['allowed_files'] ?? []);
$forbiddenFiles = array_map('normalize_path', $context['forbidden_files'] ?? []);
$evidencePath = normalize_path((string) ($context['evidence_path'] ?? ''));
$codingStarted = ($context['coding_started'] ?? false) === true;
$diffOutputs = [
    run_git(['diff', '--name-only', $baseCommit . '..' . $targetCommit]),
    run_git(['diff', '--name-only']),
    run_git(['diff', '--name-only', '--cached']),
    run_git(['ls-files', '--others', '--exclude-standard']),
];
$changedFiles = [];
foreach ($diffOutputs as $diffOutput) {
    if ($diffOutput === '') {
        continue;
    }
    foreach (explode(PHP_EOL, $diffOutput) as $file) {
        $file = trim($file);
        if ($file !== '') {
            $changedFiles[normalize_path($file)] = true;
        }
    }
}
$errors = [];
foreach (array_keys($changedFiles) as $file) {
    $exactlyAllowed = in_array($file, $allowedFiles, true);
    $evidenceAllowed = $evidencePath !== '' && str_starts_with($file, $evidencePath . '/');
    foreach (['src/', 'config/', 'database/', 'routes/', 'resources/', 'tests/', 'lang/'] as $runtimeRoot) {
        if (str_starts_with($file, $runtimeRoot) && !$codingStarted) {
            $errors[] = $file . ' changes runtime path before coding_started transition';
            continue 2;
        }
    }
    foreach ($forbiddenFiles as $pattern) {
        if (matches_pattern($file, $pattern) && !$exactlyAllowed) {
            $errors[] = $file . ' matches forbidden pattern ' . $pattern;
            continue 2;
        }
    }
    if (!$exactlyAllowed && !$evidenceAllowed) {
        $errors[] = $file . ' is outside allowed_files and evidence_path';
    }
}
if ($errors !== []) {
    fail("Larena scope check failed:\n- " . implode("\n- ", $errors));
}
echo 'Larena scope check ok: ' . count($changedFiles) . ' changed file(s).' . PHP_EOL;
