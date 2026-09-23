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

larena_storage_role_assert(
    str_contains($source, '$required = [\'constraints\', \'key\', \'label\', \'position\', \'required\', \'type\', \'type_version\', \'visibility\'];'),
    'the eight-key shape the installed base uses is still accepted',
);
larena_storage_role_assert(
    str_contains($source, '$withLocalized = [\'constraints\', \'key\', \'label\', \'localized\', \'position\', \'required\', \'type\', \'type_version\', \'visibility\'];'),
    'and the nine-key shape with localized',
);
larena_storage_role_assert(
    str_contains($source, 'if ($fieldKeys !== $required && $fieldKeys !== $withLocalized) {'),
    'anything else is still refused, so the key set was widened by one entry rather than opened up',
);
larena_storage_role_assert(
    str_contains($source, 'if (array_key_exists(\'localized\', $field) && !is_bool($field[\'localized\'])) {'),
    'a non-boolean localized value is refused: "yes" is not a flag',
);
larena_storage_role_assert(
    str_contains($source, '\'localized\' => $field[\'localized\'] ?? false,'),
    'an omitted key means not localized',
);
// The flag deliberately does *not* reach the storage schema. The schema normalizer
// has a closed key set of its own and rejects an unknown key — the workbench
// integration test caught exactly that on the first attempt — so widening it is a
// separate decision with its own migration of every stored definition. Nothing needs
// it there: the localized value writer is told which fields are localized by its
// caller.
larena_storage_role_assert(
    !str_contains($source, '\'localized\' => $field[\'localized\'],'),
    'the flag stays in the workbench structure descriptor and out of the storage schema',
);
larena_storage_role_assert(
    str_contains($source, 'The localized flag stays in the workbench structure descriptor'),
    'and the reason is written down where the next reader will look',
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
