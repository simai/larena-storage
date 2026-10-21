<?php

declare(strict_types=1);

use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-sitepack-fixture.php';

// The storage SitePack export on SQLite: a storage with archived records, translations in two locales, published and
// scheduled revisions, relations, files (a fake port) and references to another storage; the archive reads back with
// core's reader, digests checked; history, actor ids and reserved fields stay out; deterministic content; resume after
// an interruption mid-records (a chunk written but not saved is written again, not twice); cancel and retry; the
// queue job; refusals while a field change runs and for access; the structure changing mid-export; the sweep.

$opened = storageViewQueueOpen();
try {
    sitePackScenario($opened['connection'], new SqliteStorageViewDdl($opened['connection']), 'sqlite');
} finally {
    @unlink($opened['path']);
}

echo "Storage SitePack export passed.\n";
