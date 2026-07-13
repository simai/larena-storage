<?php

declare(strict_types=1);

$context = json_decode((string) file_get_contents('.larena/launch-context.json'), true, 512, JSON_THROW_ON_ERROR);
$evidencePath = rtrim((string) $context['evidence_path'], '/') . '/';
$proposalPath = (string) $context['graph_sync_proposal_path'];
$requiredFiles = $context['required_evidence_files'] ?? [
    'README.md',
    'implementation-summary.md',
    'independent-review.md',
    'tests.md',
    'file-map.json',
    'deviations.json',
    'graph-sync-proposal.json',
];
$errors = [];
if (!is_array($requiredFiles) || $requiredFiles === []) {
    $errors[] = 'launch-context required_evidence_files must be a non-empty list.';
    $requiredFiles = [];
}
foreach ($requiredFiles as $required) {
    if (!is_string($required)
        || $required === ''
        || basename($required) !== $required
        || str_contains($required, '..')) {
        $errors[] = 'Invalid required evidence filename.';
        continue;
    }
    if (!is_file($evidencePath . $required)) {
        $errors[] = "Missing evidence file: {$evidencePath}{$required}";
    }
}
if (!is_file($proposalPath)) {
    $errors[] = "Missing graph sync proposal: {$proposalPath}";
} else {
    $proposal = json_decode((string) file_get_contents($proposalPath), true, 512, JSON_THROW_ON_ERROR);
    if (($proposal['canonical_update_allowed'] ?? null) !== false) {
        $errors[] = 'graph-sync-proposal must keep canonical_update_allowed=false';
    }
}

$forbiddenRestoreSurface = [
    'src/Contracts/VersionedStorage.php' => 'restoreAsNewVersion',
    'src/Runtime/VersionedStorage.php' => 'restoreAsNewVersion',
    'src/Providers/StorageServiceProvider.php' => 'storage.record.restore',
    'src/Audit/StorageVersionAuditEventDescriptor.php' => 'storage.record.restored',
    'access.yaml' => 'storage.record.restore',
    'audit.yaml' => 'storage.record.restored',
];
foreach ($forbiddenRestoreSurface as $file => $needle) {
    $contents = is_file($file) ? (string) file_get_contents($file) : '';
    if (str_contains($contents, $needle)) {
        $errors[] = "Historical restore leaked into the Storage mutation surface: {$file}";
    }
}

foreach (['src/Contracts/VersionedStorage.php', 'src/Runtime/VersionedStorage.php'] as $file) {
    $contents = is_file($file) ? (string) file_get_contents($file) : '';
    if (!str_contains($contents, 'readAdminCurrentVersion')) {
        $errors[] = "Missing actor-checked current-version read: {$file}";
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}
echo "Evidence contract is valid for the current repository state.\n";
