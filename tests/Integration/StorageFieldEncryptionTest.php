<?php

declare(strict_types=1);

use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-encryption-fixture.php';

// Field encryption (slice storage A) on SQLite: the cipher (round trip, padding, a random nonce, AAD swaps and
// tampering refused, the strict envelope, no material in exceptions); the policy codes; data keys made with the
// structure, wrapped by larena/secret; sealing on create, update, archive, restore, revert and translations, an
// unchanged value keeping its envelope; validation before encryption; values_json, value_json, the витрина and its
// search text without plaintext, the title from a plain field or none; filter, sort and search refused; retype,
// constraint and encryption switches refused on fields with values and allowed on empty ones; reveal with the decrypt
// right, audited with field keys only; explain; fail closed without a key-encryption key or under another APP_KEY,
// a previous APP_KEY still unwrapping; an encrypted key field refused; SitePack leaving encrypted values out; purge
// deleting the data keys.

$opened = storageViewQueueOpen();
try {
    storageEncryptionScenario($opened['connection'], new SqliteStorageViewDdl($opened['connection']), 'sqlite', true);
} finally {
    @unlink($opened['path']);
}

echo "Storage field encryption passed.\n";
