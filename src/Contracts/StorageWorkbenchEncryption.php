<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The encryption runs of workbench structures (larena-specs docs/architecture/2026-10-22-storage-field-encryption.md,
 * «Turning encryption on and off», «Keys»), beside StorageWorkbench:
 *
 * - Switching encryption on or off a field with values goes through StorageWorkbench::updateStructure or
 *   migrateStructure as any change: the structure switches at once and the returned structure carries the queued
 *   or running run (StorageWorkbenchStructure::$encryptionRun). Switching off needs the descriptor flag
 *   `encryption_disable_confirmed: true` (`storage_field_encryption_disable_unconfirmed` without it).
 * - rotateEncryptionKey(): a new data key for the structure's storage; the run seals again what the old key sealed and
 *   deletes it at the end.
 * - retryEncryptionRun(): a failed run resumes where it stopped.
 *
 * Every call needs `storage.encryption.manage` and the structure's update right in its scope. While a run is open the
 * structure takes no other change (`storage_field_encryption_running`, `storage_field_encryption_failed`).
 */
interface StorageWorkbenchEncryption
{
    /**
     * @throws StorageRejected storage_field_encryption_not_enabled (no data key), storage_field_encryption_running,
     *                         storage_field_encryption_failed, storage_workbench_field_change_running,
     *                         storage_field_encryption_unavailable
     * @throws StorageConflict
     */
    public function rotateEncryptionKey(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure;

    /**
     * @throws StorageRejected storage_field_encryption_run_not_failed
     */
    public function retryEncryptionRun(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure;
}
