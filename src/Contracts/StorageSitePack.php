<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * Moves a storage between sites as a SitePack archive (larena-specs docs/architecture/2026-10-22-storage-sitepack.md):
 * the structure and every record with its live versions, translations, publication states, relations and files. Edit
 * history is not moved.
 *
 * Export: a durable run, the `storage.sitepack.export` queue job, resumable, cancellable and retryable. Every call
 * needs the `storage.sitepack.export` access operation and, in the storage's scope, the rights to read the structure
 * and list its records. A run, and its archive, belong to the person who started it.
 *
 * Import: receive() (the archive copied into the private directory, its envelope and catalog checked), plan() (the
 * `storage.sitepack.plan` job: the whole pack read and checked, a dry run that writes only the run and its id map, the
 * plan and its digest), apply() with that digest (the `storage.sitepack.import` job). Every call needs the critical
 * `storage.sitepack.import` operation and, in the target scope, the rights to create structures and records;
 * `publication: keep` also needs the publish (and, when the pack schedules, the schedule) rights. run(), read(),
 * recent(), cancel() and retry() serve import runs as well: a cancelled import, or one that fails for good, discards
 * what it wrote (files it created stay and are counted); a retry of it plans again.
 *
 * Plan errors (any refuses apply()): locale_not_declared, already_imported (the site has this package; only `copy`
 * imports it again), structure_id_taken, structure_invalid, record_invalid, record_duplicate,
 * reference_required_unresolved, user_required_missing, file_required_missing, files_unavailable,
 * localized_unavailable, publication_unavailable, publication_denied, and for a pack of the site pages (a structure
 * that adopted its schema, imported into the target's adopted one): storage_sitepack_site_pages_not_empty (the target
 * has pages other than the untouched starter Home page, which is replaced), storage_sitepack_site_pages_fields_missing,
 * storage_sitepack_site_pages_not_adopted (both: run larena:storage:adopt-site-pages),
 * storage_sitepack_site_pages_scope_mismatch. Warnings: reference_cleared, relation_dropped, user_missing_cleared,
 * user_missing_set_to_importer, file_missing_cleared, role_unknown, role_binding_skipped, list_target_unresolved.
 *
 * Reasons a call is refused with (StorageRejected): storage_sitepack_export_denied, storage_sitepack_run_unknown,
 * storage_sitepack_field_change_running, storage_sitepack_run_not_done, storage_sitepack_run_finished,
 * storage_sitepack_run_not_retryable, storage_sitepack_archive_missing, storage_workbench_structure_unknown. A run
 * fails with storage_sitepack_files_unavailable (a file value and no file owner composed),
 * storage_sitepack_structure_changed (retryable: the structure changed under the export), or a SitePack limit.
 * Import refusals: storage_sitepack_import_denied, storage_sitepack_archive_missing, storage_sitepack_format_unsupported
 * and core's sitepack_* reasons (a broken or unsafe archive), storage_sitepack_run_not_import,
 * storage_sitepack_run_not_plannable, storage_sitepack_run_not_planned, storage_sitepack_plan_digest_mismatch,
 * storage_sitepack_plan_has_errors, storage_sitepack_plan_stale (the target changed since the plan).
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

    /**
     * Receives an archive for an import into a scope of the person's site: copied into the private directory (0600),
     * its envelope, catalog, storage format and structure line checked. Nothing else is read or written; the run is
     * `ready` in phase `received`. A broken or unsafe archive is refused and leaves no run.
     */
    public function receive(string $scopeRef, string $archivePath, string $actor, ?string $correlationId = null): StorageSitePackRun;

    /**
     * Plans the import (again): the `storage.sitepack.plan` job reads the whole pack, verifies every artifact and
     * decides every id; the run ends `ready` in phase `planned` with its plan and plan digest. With $queued false
     * (or no queue) the caller runs it with run().
     */
    public function plan(
        string $runRef,
        StorageSitePackImportOptions $options,
        string $actor,
        ?string $correlationId = null,
        bool $queued = true,
    ): StorageSitePackRun;

    /**
     * Applies the plan whose digest the person approved: the `storage.sitepack.import` job writes the structure
     * («importing», hidden until the end), files, records, relations and publication states, then finalizes it.
     */
    public function apply(
        string $runRef,
        string $planDigest,
        string $actor,
        ?string $correlationId = null,
        bool $queued = true,
    ): StorageSitePackRun;
}
