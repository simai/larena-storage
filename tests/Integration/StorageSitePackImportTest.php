<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Container\Container;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-sitepack-import-fixture.php';

// The storage SitePack import on SQLite (slice «Storage B: plan and import»): a round trip into a fresh installation
// keeps every id, files reused by content, created and remapped, publication kept with a key conflict, the role bound;
// into a second scope of the same installation every record id is remapped and record@1 values and relations are
// rewritten (references through an earlier import's id map); replay detection and copy; locale_not_declared; required
// references and users; a corrupt digest and a truncated archive change nothing; cancel leaves no trace (created files
// reported); resume mid-import with the importing structure hidden and closed; the editor refused; the plan and import
// queue jobs; the command; the real archive exported from the stand.

$opened = storageViewQueueOpen();
$freshPath = null;
try {
    $fresh = static function () use (&$freshPath): array {
        $freshPath = tempnam(sys_get_temp_dir(), 'larena-storage-sitepack-fresh-');
        $capsule = new Capsule(new Container());
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $freshPath, 'prefix' => '', 'foreign_key_constraints' => true], 'fresh');
        $connection = $capsule->getConnection('fresh');
        Illuminate\Support\Facades\Schema::swap($connection->getSchemaBuilder());

        return ['connection' => $connection, 'ddl' => new SqliteStorageViewDdl($connection), 'close' => static function () use ($connection, &$freshPath): void {
            $connection->disconnect();
            if (is_string($freshPath)) {
                @unlink($freshPath);
            }
        }];
    };
    sitePackImportScenario($opened['connection'], new SqliteStorageViewDdl($opened['connection']), 'sqlite', $fresh);
} finally {
    @unlink($opened['path']);
}

echo "Storage SitePack import passed.\n";
