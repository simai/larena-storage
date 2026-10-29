<?php

declare(strict_types=1);

// The admin's Storage section and storage workbench: larena/storage's own, merged into the admin's configuration
// (larena-admin.storage_section, larena-admin.storage_workbench) only where larena/admin is installed. The keys,
// their environment switches and their defaults are the ones the admin had when the section was its own.
return [
    // The developer storage workbench: on by default only where APP_ENV is local or testing;
    // LARENA_ADMIN_STORAGE_WORKBENCH turns it on or off explicitly in any environment.
    'storage_workbench' => [
        'enabled' => filter_var(
            getenv('LARENA_ADMIN_STORAGE_WORKBENCH') === false
                ? in_array(getenv('APP_ENV') ?: 'production', ['local', 'testing'], true)
                : getenv('LARENA_ADMIN_STORAGE_WORKBENCH'),
            FILTER_VALIDATE_BOOL,
        ),
        'uri' => 'admin/storage-workbench',
        'name_prefix' => 'larena.admin.workbench.',
        'middleware' => [
            'web',
            'larena-auth.entry',
            'larena-auth.admin-required',
            'larena-admin.locale',
            'access:admin.frontend.read',
        ],
        'operation_code' => 'admin.frontend.read',
        'max_payload_bytes' => 1048576,
        'max_upload_bytes' => 10485760,
        'owner_gateway_required' => true,
        'csrf_required' => true,
    ],
    // The product Storage section: storages and their records for every
    // environment. It reads and writes through the same owner gateway as the
    // developer workbench, which stays a local and testing stand.
    'storage_section' => [
        'enabled' => filter_var(getenv('LARENA_ADMIN_STORAGE_SECTION') === false ? true : getenv('LARENA_ADMIN_STORAGE_SECTION'), FILTER_VALIDATE_BOOL),
        'uri' => 'admin/storage',
        'name_prefix' => 'larena.admin.storage.',
        'middleware' => [
            'web',
            'larena-auth.entry',
            'larena-auth.admin-required',
            'larena-admin.locale',
            'access:admin.frontend.read',
        ],
        // A CSV import of at most this many rows is checked in the request and written at once, whole or not at all;
        // a larger one is checked by the queue (storage.import.plan) and, once the person applies its plan, written
        // in chunks (storage.import.apply). Without larena/queue every import is checked and written at once.
        'import_inline_rows' => 1000,
        // The private directory of import uploads and import/export job files; empty means
        // storage/app/private/larena-admin/storage-jobs.
        'jobs_path' => '',
    ],
];
