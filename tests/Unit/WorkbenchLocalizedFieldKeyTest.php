<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/localized-value-schema.php';

use Larena\Storage\Runtime\DatabaseStorageWorkbench;

/**
 * The workbench asserts an exact field key set, so adding `localized` needed care:
 * every structure the installed base already has omits it. The key is therefore
 * optional, and a field that says nothing is not localized — which is what the whole
 * installed base means today.
 *
 * This is a source-level guard on purpose. The normalizer is private and calls into
 * the schema normalizer, the Property type registry and a database, so exercising it
 * in isolation would mean building the whole workbench graph. The behavioural
 * compatibility proof already exists: tests/Integration/StorageWorkbenchTest.php
 * defines structures with the eight-key shape and runs in the same suite, so if the
 * widened assertion had broken the installed shape that test would fail.
 */
$reflection = new ReflectionClass(DatabaseStorageWorkbench::class);
$source = (string) file_get_contents((string) $reflection->getFileName());

// `hidden` (a presentation flag) was later made optional the same way, so the
// check now names the optional keys once instead of listing every combination.
larena_storage_role_assert(
    str_contains($source, '$optional = array_values(array_intersect($fieldKeys, [\'hidden\', \'localized\']));'),
    'only hidden and localized are optional',
);
larena_storage_role_assert(
    str_contains($source, '$expected = array_merge([\'constraints\', \'key\', \'label\', \'position\', \'required\', \'type\', \'type_version\', \'visibility\'], $optional);'),
    'the eight-key shape the installed base uses is still accepted',
);
larena_storage_role_assert(
    str_contains($source, 'if ($fieldKeys !== $expected) {'),
    'anything else is still refused, so the key set was widened by named entries rather than opened up',
);
larena_storage_role_assert(
    str_contains($source, 'if (!is_bool($field[$flag])) {'),
    'a non-boolean flag is refused: "yes" is not a flag',
);
larena_storage_role_assert(
    str_contains($source, '\'localized\' => $field[\'localized\'] ?? false,'),
    'an omitted key means not localized',
);
// The flag reaches the storage schema, kept only when true, so the localized value
// writer reads it from the schema of the revision instead of trusting its caller,
// and a structure without localized fields keeps the schema it always had.
larena_storage_role_assert(
    str_contains($source, "] + (\$field['localized'] === true ? ['localized' => true] : []), \$normalized);"),
    'the flag goes into the storage schema only when true',
);
larena_storage_role_assert(
    str_contains($source, 'Whether a field is localized may change'),
    'and toggling it on an existing field is an accepted change, with the reason written down',
);

// The integration test that proves the eight-key shape still works is present and
// wired into the suite, because this guard leans on it.
$integration = __DIR__ . '/../Integration/StorageWorkbenchTest.php';
larena_storage_role_assert(is_file($integration), 'the workbench integration test exists');
$integrationSource = (string) file_get_contents($integration);
larena_storage_role_assert(
    str_contains($integrationSource, '\'visibility\' => \'public\'') && !str_contains($integrationSource, '\'localized\''),
    'and it still defines structures without the localized key',
);

echo "Workbench localized field key passed.\n";
