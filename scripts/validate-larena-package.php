<?php

declare(strict_types=1);

$requiredFiles = [
    '.gitignore',
    '.env.example',
    '.github/workflows/larena-package-ci.yml',
    '.githooks/pre-commit',
    '.githooks/pre-push',
    'composer.json',
    'module.yaml',
    'phpstan.neon.dist',
    '.larena/spec-ref.json',
    '.larena/launch-context.json',
    'tools/larena-scope-check.php',
];
$errors = [];
foreach ($requiredFiles as $file) {
    if (!is_file($file)) {
        $errors[] = "Missing required enforcement file: {$file}";
    }
}
$specRef = is_file('.larena/spec-ref.json')
    ? json_decode((string) file_get_contents('.larena/spec-ref.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
$launchContext = is_file('.larena/launch-context.json')
    ? json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR)
    : [];
if (($specRef['canonical_update_allowed'] ?? null) !== false) {
    $errors[] = '.larena/spec-ref.json must keep canonical_update_allowed=false';
}
if (($launchContext['package'] ?? null) !== 'larena/storage') {
    $errors[] = '.larena/launch-context.json package must be larena/storage';
}
$contractCodingStarted = ($launchContext['coding_started'] ?? false) === true;
if (!str_starts_with((string) ($launchContext['evidence_path'] ?? ''), 'docs/project-management/evidence/')) {
    $errors[] = 'launch-context evidence_path must start with docs/project-management/evidence/';
}
if (!str_starts_with((string) ($launchContext['graph_sync_proposal_path'] ?? ''), (string) ($launchContext['evidence_path'] ?? '__missing__'))) {
    $errors[] = 'graph_sync_proposal_path must be inside evidence_path';
}
foreach (['src', 'config', 'database', 'routes', 'resources', 'tests', 'lang'] as $runtimePath) {
    if (!$contractCodingStarted && is_dir($runtimePath)) {
        $errors[] = "{$runtimePath}/ is not allowed in this clean pre-codegen baseline commit.";
    }
}
if ($contractCodingStarted) {
    foreach ([
        'src/Contracts/StorageSchema.php',
        'src/Contracts/StorageRecord.php',
        'src/Contracts/StorageQuery.php',
        'src/Contracts/StorageMutation.php',
        'src/Contracts/StorageValidationReport.php',
        'src/Contracts/StorageRuntime.php',
        'src/Enums/FieldVisibility.php',
        'src/Enums/MutationType.php',
        'src/Enums/StorageDecisionStatus.php',
        'tests/Unit/StorageSchemaContractTest.php',
        'tests/Unit/StorageFailsClosedTest.php',
    ] as $contractFile) {
        if (!is_file($contractFile)) {
            $errors[] = "Missing contract skeleton file {$contractFile}.";
        }
    }
}
if (!in_array(($launchContext['status'] ?? null), [
    'repository_prepared_pending_review',
    'coding_started',
    'contract_skeleton_review_passed',
], true)) {
    $errors[] = 'launch-context status is not allowed for this repository state.';
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}
echo "Larena Storage coding launch context is valid.\n";
