<?php

declare(strict_types=1);

$composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

$larena = static function (array $dependencies): array {
    $names = array_values(array_filter(array_keys($dependencies), static fn (string $name): bool => str_starts_with($name, 'larena/')));
    sort($names, SORT_STRING);
    return $names;
};

$actualRequire = $larena($composer['require'] ?? []);
$actualRequireDev = $larena($composer['require-dev'] ?? []);
// larena/queue runs the витрина rebuild as a durable job (storage.view.rebuild); larena/secret wraps the data keys
// of encrypted fields (DataKeyWrapper).
$expectedRequire = ['larena/access','larena/core','larena/property','larena/queue','larena/secret'];
// larena/admin is only suggested: the admin's Storage section (src/Admin) is Storage's own and is tested here with the
// admin installed beside it (StorageWithoutAdminSourceTest keeps it out of require).
$expectedRequireDev = ['larena/admin','larena/audit', 'larena/rest'];

if ($actualRequire !== $expectedRequire) {
    throw new RuntimeException('Unexpected storage runtime dependencies: '.json_encode($actualRequire, JSON_THROW_ON_ERROR));
}
if ($actualRequireDev !== $expectedRequireDev) {
    throw new RuntimeException('Unexpected storage compatibility dependencies: '.json_encode($actualRequireDev, JSON_THROW_ON_ERROR));
}

foreach ([
    'src/Providers/StorageServiceProvider.php',
    'src/Runtime/VersionedStorage.php',
    'src/SchemaEvolution/DatabaseStorageSchemaEvolution.php',
] as $mandatoryRuntimeFile) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$mandatoryRuntimeFile);
    if (str_contains($source, 'Larena\\Audit\\')) {
        throw new RuntimeException($mandatoryRuntimeFile.' retains a mandatory Audit runtime import');
    }
}

echo "DependencyContractTest passed.\n";
