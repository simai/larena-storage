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
$expectedRequire = ['larena/access','larena/core','larena/property'];
$expectedRequireDev = ['larena/audit'];

if ($actualRequire !== $expectedRequire) {
    throw new RuntimeException('Unexpected storage runtime dependencies: '.json_encode($actualRequire, JSON_THROW_ON_ERROR));
}
if ($actualRequireDev !== $expectedRequireDev) {
    throw new RuntimeException('Unexpected storage compatibility dependencies: '.json_encode($actualRequireDev, JSON_THROW_ON_ERROR));
}

echo "MinimalCmsDependencyContractTest passed.\n";
