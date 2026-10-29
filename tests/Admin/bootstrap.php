<?php

declare(strict_types=1);

// The Storage section's tests (Larena\Storage\Admin): larena/admin is a development requirement of Storage, installed
// beside it for these tests only.
require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * The words a section test reads: the admin's own (larena-admin::admin.*) and, under `larena_storage`, Storage's
 * section and workbench words (larena-storage::admin.*), read from the files the pages are served from.
 *
 * @return array<string, mixed>
 */
function storage_admin_words(string $locale): array
{
    $admin = require dirname(__DIR__, 2) . '/vendor/larena/admin/resources/lang/' . $locale . '/admin.php';
    $storage = require dirname(__DIR__, 2) . '/resources/lang/' . $locale . '/admin.php';
    assert(is_array($admin) && is_array($storage) && !isset($admin['larena_storage']));

    return $admin + ['larena_storage' => $storage];
}

/** A key of Storage's words (larena-storage::admin.x) as the tests' translators look it up in storage_admin_words(). */
function storage_admin_key(string $key): string
{
    return str_starts_with($key, 'larena-storage::admin.')
        ? 'larena-admin::admin.larena_storage.' . substr($key, strlen('larena-storage::admin.'))
        : $key;
}
