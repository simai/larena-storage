<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Moves a storage between sites as a SitePack archive (larena-specs docs/architecture/2026-10-22-storage-sitepack.md):
 * the structure and every record with its live versions, translations, publication states, relations and files. Edit
 * history is not moved.
 *
 * Export (this slice): a durable run, the `storage.sitepack.export` queue job, resumable, cancellable and retryable.
 * Every call needs the `storage.sitepack.export` access operation and, in the storage's scope, the rights to read the
 * structure and list its records. A run, and its archive, belong to the person who started it. The plan and the
 * import join this contract in the next slice.
 *
 * Reasons a call is refused with (StorageRejected): storage_sitepack_export_denied, storage_sitepack_run_unknown,
 * storage_sitepack_field_change_running, storage_sitepack_run_not_done, storage_sitepack_run_finished,
 * storage_sitepack_run_not_retryable, storage_sitepack_archive_missing, storage_workbench_structure_unknown. A run
 * fails with storage_sitepack_files_unavailable (a file value and no file owner composed),
 * storage_sitepack_structure_changed (retryable: the structure changed under the export), or a SitePack limit.
 */
interface StorageSitePack
{
    /**
     * Starts an export of one structure of a scope. Refused while a field change of the structure runs. With
     * $queued the run goes to the queue (when there is one); otherwise the caller runs it with run().
     */
    public function startExport(
        string $scopeRef,
        string $structureId,
        string $actor,
        ?string $correlationId = null,
        bool $queued = true,
    ): StorageSitePackRun;

    /** Runs (or resumes) the run in this process until it ends or fails. */
    public function run(string $runRef, string $actor): StorageSitePackRun;

    public function read(string $runRef, string $actor): StorageSitePackRun;

    /** @return list<StorageSitePackRun> the actor's latest runs, newest first */
    public function recent(string $actor, int $limit = 20): array;

    /** The sealed archive of a finished export; only for the person who started it. Audited as a download. */
    public function archive(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackArchiveFile;

    /** Stops a run that has not ended and discards what it staged. */
    public function cancel(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackRun;

    /**
     * Starts a failed or cancelled run again: a failed one resumes where it stopped (from the start when the
     * structure changed), a cancelled one starts over.
     */
    public function retry(string $runRef, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun;
}
