<?php

declare(strict_types=1);

use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-encryption-run-fixture.php';

// Encryption runs (slice storage B) on SQLite: switching encryption on filled fields seals every revision and every
// translation in place (no plaintext left in any table, hashes, heads and the витрина consistent, reads masked,
// filters refused at once); a queued run refuses other structure changes, field changes, archive and rotation,
// stops after a chunk and resumes, and seals writes made meanwhile; a run that fails under another APP_KEY stays
// failed and resumes on retry; a field change and a run never run side by side; switching off needs its
// confirmation, shows and reveals envelopes meanwhile, opens history in place and gives the field its витрина
// column back; a key rotation seals everything again under a new key (through the queue job) and deletes the old
// one, a reused envelope of the retiring key is sealed again; a rewrap after an APP_KEY change moves every data key
// to the new KEK at once and the health warns until it ran; a SitePack export with the plaintext option is refused
// without decrypt, audited, says so in its manifest and imports sealed under the target's key; a purge deletes the
// runs.

$opened = storageViewQueueOpen();
try {
    storageEncryptionRunScenario($opened['connection'], new SqliteStorageViewDdl($opened['connection']), 'sqlite', true);
} finally {
    @unlink($opened['path']);
}

echo "Storage field encryption runs passed.\n";
