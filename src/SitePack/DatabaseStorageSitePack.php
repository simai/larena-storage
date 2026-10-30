<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Core\SitePack\SitePackEnvelope;
use Larena\Core\SitePack\SitePackLimits;
use Larena\Core\SitePack\SitePackRejected;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Audit\StorageSitePackAuditEventCatalog;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Encryption\StorageDataKeys;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Encryption\StorageFieldReveal;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\StorageSitePackArchiveFile;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageSitePackPlan;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\NullStorageSecurityEventSink;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageRecordConverter;
use stdClass;
use Throwable;

/**
 * Storage SitePack runs on the application's database (see StorageSitePack).
 *
 * An export walks its phases (prepare → records → files → seal) in one process at a time: the process takes the run
 * (`lease_ref`), and every chunk ends with a save of the position under that lease. A save that finds the lease gone
 * (cancelled, or taken over after the holder stopped saving for LEASE_SECONDS) stops the process at once. Staging
 * lives in `<directory>/<run ref>.staging`, the archive in `<directory>/<run ref>.zip`, both private (0700/0600).
 *
 * A failure that may pass (the structure changed, an interruption, a database error) leaves the run failed with a
 * retryable reason: its queue job retries it, and retry() starts it again. Any other failure ends the run.
 *
 * An import (receive → plan → apply) keeps its private copy of the archive in `<directory>/<run ref>.zip` until the
 * sweep. The plan job (`storage.sitepack.plan`, StorageSitePackPlanner) writes only the run row and its id map; the
 * import job (`storage.sitepack.import`, StorageSitePackImporter) writes the site. A cancelled import, or one that
 * fails for good, discards what it wrote to the site (files it created stay and are reported).
 */
final class DatabaseStorageSitePack implements StorageSitePack
{
    public const string OPERATION = 'storage.sitepack.export';
    public const int CHUNK = 500;
    public const int FILE_CHUNK = 50;
    public const int LEASE_SECONDS = 300;
    public const int KEEP_DAYS = 7;

    public const string REASON_DENIED = 'storage_sitepack_export_denied';
    public const string REASON_UNKNOWN = 'storage_sitepack_run_unknown';
    public const string REASON_FIELD_CHANGE = 'storage_sitepack_field_change_running';
    public const string REASON_STRUCTURE_CHANGED = 'storage_sitepack_structure_changed';
    public const string REASON_INTERRUPTED = 'storage_sitepack_interrupted';
    public const string REASON_BUSY = 'storage_sitepack_run_busy';
    public const string REASON_FILES_UNAVAILABLE = 'storage_sitepack_files_unavailable';
    public const string IMPORT_OPERATION = 'storage.sitepack.import';
    public const string REASON_IMPORT_DENIED = 'storage_sitepack_import_denied';
    public const int IMPORT_CHUNK = 200;

    /** The phases of an import job; a permanent failure in one of them discards what the run wrote. */
    public const array IMPORT_PHASES = [
        StorageSitePackRun::PHASE_STRUCTURE,
        StorageSitePackRun::PHASE_FILES,
        StorageSitePackRun::PHASE_RECORDS,
        StorageSitePackRun::PHASE_RELATIONS,
        StorageSitePackRun::PHASE_PUBLICATIONS,
        StorageSitePackRun::PHASE_FINALIZE,
    ];

    /** Reasons a failed run is retried for by its queue job. */
    public const array RETRYABLE = [
        self::REASON_STRUCTURE_CHANGED,
        self::REASON_INTERRUPTED,
        self::REASON_FIELD_CHANGE,
        self::REASON_BUSY,
        'storage_persistence_failed',
        'storage_sitepack_staging_failed',
    ];

    private const string RUN_PREFIX = 'storage-sitepack-';
    /** The run option of an export that carries encrypted values in plaintext. */
    public const string PLAINTEXT_OPTION = 'include_encrypted_plaintext';

    private readonly StorageSitePackRuns $runs;
    private readonly StorageSitePackFiles $files;
    private readonly StorageSitePackFileImports $fileImports;
    private readonly SitePackLimits $limits;
    private readonly StorageSecurityEventSink $events;
    private readonly SchemaDefinitionNormalizer $normalizer;
    private readonly StorageRecordConverter $converter;
    private readonly string $directory;

    /**
     * @param (Closure(string, int, string): ?string)|null $jobs queues the run's job (run ref, generation, job type); returns the job id, null without a queue
     * @param (Closure(string): string)|null $siteOf the site an actor works in; publication states of that site are exported
     * @param (Closure(): DateTimeImmutable)|null $clock
     * @param (Closure(string): bool)|null $userExists whether a user ref (user@1) names a user of this installation;
     *        without it every user is taken as missing (an optional value is cleared, a required one set to the importer)
     * @param (Closure(string): list<string>)|null $siteLocales the locales a site declares, for an import that names none
     * @param (Closure(string): void)|null $viewRebuilds queues the витрина rebuilds (admin and site) of an imported storage
     */
    public function __construct(
        private readonly Connection $database,
        private readonly StorageWorkbench $workbench,
        private readonly VersionedStorage $storage,
        private readonly PropertyTypeRegistry $propertyTypes,
        private readonly ActorOperationAuthorizer $authorizer,
        private readonly QueryScopeProvider $scopes,
        string $directory,
        ?StorageSitePackFiles $files = null,
        ?SitePackLimits $limits = null,
        ?StorageSecurityEventSink $events = null,
        private readonly ?Closure $jobs = null,
        private readonly ?Closure $siteOf = null,
        private readonly ?Closure $clock = null,
        private readonly int $chunk = self::CHUNK,
        private readonly int $fileChunk = self::FILE_CHUNK,
        ?StorageSitePackFileImports $fileImports = null,
        private readonly ?LocalizedValues $localized = null,
        private readonly ?PublicationLifecycle $publication = null,
        private readonly ?StructureRoleRegistry $roles = null,
        private readonly ?Closure $userExists = null,
        private readonly ?Closure $siteLocales = null,
        private readonly ?Closure $viewRebuilds = null,
        private readonly int $importChunk = self::IMPORT_CHUNK,
        // Opens the encrypted values of an export that asks for them in plaintext (include_encrypted_plaintext);
        // without it such an export is refused (storage_field_encryption_unavailable).
        private readonly ?StorageDataKeys $keys = null,
    ) {
        $this->runs = new StorageSitePackRuns($database);
        $this->files = $files ?? new UnavailableStorageSitePackFiles();
        $this->fileImports = $fileImports ?? ($this->files instanceof StorageSitePackFileImports ? $this->files : new UnavailableStorageSitePackFiles());
        $this->limits = $limits ?? new SitePackLimits();
        $this->events = $events ?? new NullStorageSecurityEventSink();
        $this->normalizer = new SchemaDefinitionNormalizer($propertyTypes);
        $this->converter = new StorageRecordConverter($database, $propertyTypes);
        $this->directory = rtrim($directory, '/');
        if ($this->directory === '' || $chunk < 1 || $fileChunk < 1 || $importChunk < 1) {
            throw new StorageRejected('storage_sitepack_configuration_invalid');
        }
    }

    /**
     * $includeEncryptedPlaintext (beyond the contract): the pack carries the encrypted fields' values in plaintext.
     * It needs `storage.workbench.record.decrypt` besides the export right and a working key; the start is audited
     * (`storage.sitepack.export_decrypted`), the manifest says `contains_decrypted_values: true`, and an import seals
     * the values again with the target's data key.
     */
    public function startExport(
        string $scopeRef,
        string $structureId,
        string $actor,
        ?string $correlationId = null,
        bool $queued = true,
        bool $includeEncryptedPlaintext = false,
    ): StorageSitePackRun {
        $this->assertAccess($actor, $scopeRef);
        $this->assertAvailable();
        if ($includeEncryptedPlaintext) {
            $this->assertPlaintext($actor);
        }
        $structure = $this->workbench->readStructure($scopeRef, $structureId, $actor);
        if ($structure->changing()) {
            throw new StorageRejected(self::REASON_FIELD_CHANGE);
        }
        $siteRef = $this->siteOf($actor);
        $runRef = self::RUN_PREFIX . bin2hex(random_bytes(16));
        $now = $this->now();
        $this->runs->insert([
            'run_ref' => $runRef,
            'kind' => StorageSitePackRun::KIND_EXPORT,
            'scope_ref' => $scopeRef,
            'site_ref' => $siteRef,
            'structure_id' => $structure->structureId,
            'actor' => $actor,
            'state' => StorageSitePackRun::STATE_QUEUED,
            'phase' => StorageSitePackRun::PHASE_PREPARE,
            'cursor' => null,
            'done' => 0,
            'total' => 0,
            'counts_json' => '{}',
            'options_json' => self::json(['generation' => 0] + ($includeEncryptedPlaintext ? [self::PLAINTEXT_OPTION => true] : [])),
            'plan_json' => null,
            'plan_digest' => null,
            'package_id' => null,
            'archive_sha256' => null,
            'archive_bytes' => null,
            'job_id' => null,
            'lease_ref' => null,
            'reason' => null,
            'correlation_id' => $this->correlationId($correlationId),
            'created_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ]);
        if ($includeEncryptedPlaintext) {
            // Who asked for plaintext leaves a trace before anything is read.
            $this->emit(StorageSitePackAuditEventCatalog::EXPORT_DECRYPTED, $this->mustFind($runRef), $correlationId, [
                'contains_decrypted_values' => true,
            ]);
        }
        if ($queued) {
            $this->dispatch($runRef, 0);
        }

        return $this->mustFind($runRef);
    }

    public function run(string $runRef, string $actor): StorageSitePackRun
    {
        $this->own($runRef, $actor);

        return $this->process($runRef);
    }

    public function read(string $runRef, string $actor): StorageSitePackRun
    {
        return $this->own($runRef, $actor);
    }

    public function recent(string $actor, int $limit = 20): array
    {
        try {
            $this->assertOperation($actor);
        } catch (StorageRejected $exception) {
            // A person who may only import sees their import runs.
            if (!$this->allowed($actor, self::IMPORT_OPERATION)) {
                throw $exception;
            }
        }
        $this->assertAvailable();

        return $this->runs->recent($actor, max(1, min(100, $limit)));
    }

    public function archive(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackArchiveFile
    {
        $run = $this->own($runRef, $actor);
        if ($run->kind !== StorageSitePackRun::KIND_EXPORT || !$run->isDone() || $run->archiveSha256 === null || $run->archiveBytes === null || $run->packageId === null) {
            throw new StorageRejected('storage_sitepack_run_not_done');
        }
        $path = $this->archivePath($runRef);
        if (!is_file($path) || is_link($path) || filesize($path) !== $run->archiveBytes) {
            throw new StorageRejected('storage_sitepack_archive_missing');
        }
        $this->emit(StorageSitePackAuditEventCatalog::DOWNLOADED, $run, $correlationId ?? $run->correlationId, [
            'package_id' => $run->packageId,
            'archive_sha256' => $run->archiveSha256,
            'archive_bytes' => $run->archiveBytes,
        ]);
        $date = substr($run->finishedAt ?? $run->createdAt, 0, 10);

        return new StorageSitePackArchiveFile(
            $runRef,
            $path,
            $run->archiveBytes,
            $run->archiveSha256,
            $run->packageId,
            StorageSitePackFormat::structureKey((string) $run->structureId) . '-' . str_replace('-', '', $date) . '.sitepack.zip',
        );
    }

    public function cancel(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackRun
    {
        $run = $this->own($runRef, $actor);
        if (in_array($run->state, [StorageSitePackRun::STATE_DONE, StorageSitePackRun::STATE_CANCELLED], true)) {
            throw new StorageRejected('storage_sitepack_run_finished');
        }
        $now = $this->now();
        $changed = $this->runs->update($runRef, [
            'state' => StorageSitePackRun::STATE_CANCELLED,
            'lease_ref' => null,
            'reason' => null,
            'updated_at' => $now,
            'finished_at' => $now,
        ], null, [StorageSitePackRun::STATE_QUEUED, StorageSitePackRun::STATE_RUNNING, StorageSitePackRun::STATE_FAILED, StorageSitePackRun::STATE_READY]);
        if ($changed !== 1) {
            throw new StorageRejected('storage_sitepack_run_finished');
        }
        $this->discard($runRef);

        return $this->mustFind($runRef);
    }

    public function retry(string $runRef, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        $run = $this->own($runRef, $actor);
        if (!in_array($run->state, [StorageSitePackRun::STATE_FAILED, StorageSitePackRun::STATE_CANCELLED], true)) {
            throw new StorageRejected('storage_sitepack_run_not_retryable');
        }
        $row = $this->runs->row($runRef) ?? throw new StorageRejected(self::REASON_UNKNOWN);
        if ($run->kind === StorageSitePackRun::KIND_IMPORT) {
            return $this->retryImport($run, $row, $queued);
        }
        $options = self::decode($row->options_json);
        $generation = (int) ($options['generation'] ?? 0) + 1;
        $options['generation'] = $generation;
        $changes = [
            'state' => StorageSitePackRun::STATE_QUEUED,
            'lease_ref' => null,
            'reason' => null,
            'finished_at' => null,
            'options_json' => self::json($options),
            'updated_at' => $this->now(),
        ];
        if ($run->state === StorageSitePackRun::STATE_CANCELLED) {
            // A cancelled run discarded what it staged: it starts over.
            $changes += ['phase' => StorageSitePackRun::PHASE_PREPARE, 'cursor' => null, 'done' => 0, 'counts_json' => '{}'];
        }
        if ($this->runs->update($runRef, $changes, null, [$run->state]) !== 1) {
            throw new StorageRejected('storage_sitepack_run_not_retryable');
        }
        if ($queued) {
            $this->dispatch($runRef, $generation);
        }

        return $this->mustFind($runRef);
    }

    public function receive(string $scopeRef, string $archivePath, string $actor, ?string $correlationId = null): StorageSitePackRun
    {
        $this->assertImportAccess($actor, $scopeRef);
        $this->assertAvailable();
        $this->importWorkbench();
        if (!is_file($archivePath) || is_link($archivePath)) {
            throw new StorageRejected('storage_sitepack_archive_missing');
        }
        $siteRef = $this->siteOf($actor);
        $runRef = self::RUN_PREFIX . bin2hex(random_bytes(16));
        StorageSitePackExporter::directory($this->directory);
        $copy = $this->archivePath($runRef);
        if (!@copy($archivePath, $copy)) {
            @unlink($copy);
            throw new StorageRejected('storage_sitepack_staging_failed');
        }
        @chmod($copy, 0600);
        try {
            // The envelope and the catalog, the storage format and the structure line; the rest is read by the plan.
            $source = StorageSitePackSource::open($copy, $this->limits);
            try {
                $packageId = $source->packageId();
                $sourceStructure = (string) $source->structure['id'];
                $records = $source->recordCount();
                $sourceSite = $source->sourceSite();
            } finally {
                $source->close();
            }
            $size = filesize($copy);
            $sha256 = hash_file('sha256', $copy);
            if (!is_int($size) || !is_string($sha256)) {
                throw new StorageRejected('storage_sitepack_staging_failed');
            }
        } catch (Throwable $exception) {
            @unlink($copy);
            throw $exception instanceof StorageRejected ? $exception : new StorageRejected('storage_sitepack_archive_invalid', $exception);
        }
        $now = $this->now();
        $this->runs->insert([
            'run_ref' => $runRef,
            'kind' => StorageSitePackRun::KIND_IMPORT,
            'scope_ref' => $scopeRef,
            'site_ref' => $siteRef,
            'structure_id' => $sourceStructure,
            'actor' => $actor,
            'state' => StorageSitePackRun::STATE_READY,
            'phase' => StorageSitePackRun::PHASE_RECEIVED,
            'cursor' => null,
            'done' => 0,
            'total' => $records,
            'counts_json' => '{}',
            'options_json' => self::json(['generation' => 0, 'source' => ['structure_id' => $sourceStructure, 'site_ref' => $sourceSite]]),
            'plan_json' => null,
            'plan_digest' => null,
            'package_id' => $packageId,
            'archive_sha256' => $sha256,
            'archive_bytes' => $size,
            'job_id' => null,
            'lease_ref' => null,
            'reason' => null,
            'correlation_id' => $this->correlationId($correlationId),
            'created_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,
        ]);

        return $this->mustFind($runRef);
    }

    public function plan(
        string $runRef,
        StorageSitePackImportOptions $options,
        string $actor,
        ?string $correlationId = null,
        bool $queued = true,
    ): StorageSitePackRun {
        $run = $this->own($runRef, $actor);
        if ($run->kind !== StorageSitePackRun::KIND_IMPORT) {
            throw new StorageRejected('storage_sitepack_run_not_import');
        }
        if ($run->state !== StorageSitePackRun::STATE_READY) {
            throw new StorageRejected('storage_sitepack_run_not_plannable');
        }
        if ($options->declaredLocales === null) {
            $options = $options->withDeclaredLocales($this->localesOf((string) $run->siteRef));
        }
        $row = $this->runs->row($runRef) ?? throw new StorageRejected(self::REASON_UNKNOWN);
        $stored = self::decode($row->options_json);
        $generation = (int) ($stored['generation'] ?? 0) + 1;
        $stored['generation'] = $generation;
        $stored['import_options'] = $options->toArray();
        unset($stored['import']);
        $changes = [
            'state' => StorageSitePackRun::STATE_QUEUED,
            'phase' => StorageSitePackRun::PHASE_PLAN,
            'cursor' => null,
            'plan_json' => null,
            'plan_digest' => null,
            'options_json' => self::json($stored),
            'reason' => null,
            'lease_ref' => null,
            'finished_at' => null,
            'updated_at' => $this->now(),
        ] + ($correlationId === null ? [] : ['correlation_id' => $this->correlationId($correlationId)]);
        if ($this->runs->update($runRef, $changes, null, [StorageSitePackRun::STATE_READY]) !== 1) {
            throw new StorageRejected('storage_sitepack_run_not_plannable');
        }
        $this->runs->clearIdsBut($runRef, StorageSitePackRuns::ID_KIND_FILE);
        if ($queued) {
            $this->dispatch($runRef, $generation, StorageSitePackImportJob::PLAN_JOB_TYPE);
        }

        return $this->mustFind($runRef);
    }

    public function apply(
        string $runRef,
        string $planDigest,
        string $actor,
        ?string $correlationId = null,
        bool $queued = true,
    ): StorageSitePackRun {
        $run = $this->own($runRef, $actor);
        if ($run->kind !== StorageSitePackRun::KIND_IMPORT || $run->state !== StorageSitePackRun::STATE_READY
            || $run->phase !== StorageSitePackRun::PHASE_PLANNED || $run->plan === null || $run->planDigest === null) {
            throw new StorageRejected('storage_sitepack_run_not_planned');
        }
        if (!hash_equals($run->planDigest, $planDigest)) {
            throw new StorageRejected('storage_sitepack_plan_digest_mismatch');
        }
        $plan = $run->plan;
        if (!$plan->ok()) {
            throw new StorageRejected('storage_sitepack_plan_has_errors');
        }
        if (($plan->publication['mode'] ?? null) === StorageSitePackImportOptions::PUBLICATION_KEEP
            && (!$this->allowed($actor, 'storage.publication.publish')
                || ((int) ($plan->publication['scheduled'] ?? 0) > 0 && !$this->allowed($actor, 'storage.publication.schedule')))) {
            throw new StorageRejected(self::REASON_IMPORT_DENIED);
        }
        if (($plan->options['copy'] ?? false) !== true && $run->packageId !== null && $this->importedBefore($run->packageId, (string) $run->siteRef, $runRef)) {
            // The same pack went into this site since the plan.
            throw new StorageRejected('storage_sitepack_plan_stale');
        }
        $row = $this->runs->row($runRef) ?? throw new StorageRejected(self::REASON_UNKNOWN);
        $stored = self::decode($row->options_json);
        $generation = (int) ($stored['generation'] ?? 0) + 1;
        $stored['generation'] = $generation;
        $changes = [
            'state' => StorageSitePackRun::STATE_QUEUED,
            'phase' => StorageSitePackRun::PHASE_STRUCTURE,
            'cursor' => null,
            'done' => 0,
            'counts_json' => '{}',
            'options_json' => self::json($stored),
            'reason' => null,
            'lease_ref' => null,
            'finished_at' => null,
            'updated_at' => $this->now(),
        ] + ($correlationId === null ? [] : ['correlation_id' => $this->correlationId($correlationId)]);
        if ($this->runs->update($runRef, $changes, null, [StorageSitePackRun::STATE_READY]) !== 1) {
            throw new StorageRejected('storage_sitepack_run_not_planned');
        }
        if ($queued) {
            $this->dispatch($runRef, $generation, StorageSitePackImportJob::IMPORT_JOB_TYPE);
        }

        return $this->mustFind($runRef);
    }

    /**
     * An import: a failed run with a retryable reason resumes where it stopped; a cancelled run, or one that failed
     * for good (what it wrote is discarded), is planned again with the options it had, or waits to be planned.
     */
    private function retryImport(StorageSitePackRun $run, stdClass $row, bool $queued): StorageSitePackRun
    {
        $options = self::decode($row->options_json);
        $generation = (int) ($options['generation'] ?? 0) + 1;
        $options['generation'] = $generation;
        $changes = ['lease_ref' => null, 'reason' => null, 'finished_at' => null, 'updated_at' => $this->now()];
        $jobType = null;
        if ($run->state === StorageSitePackRun::STATE_FAILED && in_array((string) $run->reason, self::RETRYABLE, true)) {
            $changes['state'] = StorageSitePackRun::STATE_QUEUED;
            $jobType = $run->phase === StorageSitePackRun::PHASE_PLAN ? StorageSitePackImportJob::PLAN_JOB_TYPE : StorageSitePackImportJob::IMPORT_JOB_TYPE;
        } elseif (is_array($options['import_options'] ?? null)) {
            unset($options['import']);
            $changes += ['state' => StorageSitePackRun::STATE_QUEUED, 'phase' => StorageSitePackRun::PHASE_PLAN, 'cursor' => null,
                'done' => 0, 'counts_json' => '{}', 'plan_json' => null, 'plan_digest' => null];
            $jobType = StorageSitePackImportJob::PLAN_JOB_TYPE;
        } else {
            $changes += ['state' => StorageSitePackRun::STATE_READY, 'phase' => StorageSitePackRun::PHASE_RECEIVED, 'cursor' => null];
        }
        $changes['options_json'] = self::json($options);
        if (!is_file($this->archivePath($run->runRef))) {
            throw new StorageRejected('storage_sitepack_archive_missing');
        }
        if ($this->runs->update($run->runRef, $changes, null, [$run->state]) !== 1) {
            throw new StorageRejected('storage_sitepack_run_not_retryable');
        }
        if ($queued && $jobType !== null) {
            $this->dispatch($run->runRef, $generation, $jobType);
        }

        return $this->mustFind($run->runRef);
    }

    /** The plan job: the whole pack read and checked, the run's id map and plan written, nothing else. */
    private function walkPlan(string $runRef, string $lease, ?Closure $checkpoint): StorageSitePackRun
    {
        $row = $this->runs->row($runRef) ?? throw new StorageRejected(self::REASON_UNKNOWN);
        $actor = (string) $row->actor;
        $scopeRef = (string) $row->scope_ref;
        $this->assertImportAccess($actor, $scopeRef);
        $stored = self::decode($row->options_json);
        if (!is_array($stored['import_options'] ?? null)) {
            throw new StorageRejected('storage_sitepack_run_corrupt');
        }
        $options = StorageSitePackImportOptions::fromArray($stored['import_options']);
        $source = $this->openImport($row);
        try {
            $planner = new StorageSitePackPlanner(
                $this->database,
                $this->runs,
                $this->importWorkbench(),
                $this->storage,
                $this->normalizer,
                $this->propertyTypes,
                $this->fileImports,
                $this->authorizer,
                $this->localized,
                $this->publication,
                $this->roles,
                $this->userExists ?? static fn (string $user): bool => false,
                function () use ($runRef, $lease, $checkpoint): void {
                    $this->tick($checkpoint);
                    $this->save($runRef, $lease, []);
                },
                $runRef,
                $scopeRef,
                (string) $row->site_ref,
                $actor,
            );
            $result = $planner->plan($source, $options);
        } finally {
            $source->close();
        }
        $plan = $result['plan'];
        $json = SitePackEnvelope::canonicalJson($plan->toArray());
        $digest = 'sha256:' . hash('sha256', $json);
        $stored['import'] = $result['import'];
        $this->save($runRef, $lease, [
            'state' => StorageSitePackRun::STATE_READY,
            'phase' => StorageSitePackRun::PHASE_PLANNED,
            'cursor' => null,
            'structure_id' => $plan->structure['target_id'] ?? $plan->structure['source_id'],
            'plan_json' => $json,
            'plan_digest' => $digest,
            'options_json' => self::json($stored),
            'lease_ref' => null,
            'reason' => null,
        ]);
        $run = $this->mustFind($runRef);
        $this->emit(StorageSitePackAuditEventCatalog::IMPORT_PLANNED, $run, $run->correlationId, [
            'package_id' => $run->packageId,
            'plan_digest' => $digest,
            'target_structure_id' => is_string($plan->structure['target_id'] ?? null) ? $plan->structure['target_id'] : null,
            'records' => (int) ($plan->records['total'] ?? 0),
            'records_remapped' => (int) ($plan->records['new'] ?? 0),
            'files' => (int) ($plan->files['total'] ?? 0),
            'errors' => count($plan->errors),
            'warnings' => count($plan->warnings),
        ]);

        return $run;
    }

    /** The import job: the plan written to the site, phase by phase, resumable. */
    private function walkImport(string $runRef, string $lease, ?Closure $checkpoint): StorageSitePackRun
    {
        $row = $this->runs->row($runRef) ?? throw new StorageRejected(self::REASON_UNKNOWN);
        $actor = (string) $row->actor;
        $scopeRef = (string) $row->scope_ref;
        $this->assertImportAccess($actor, $scopeRef);
        $run = StorageSitePackRuns::hydrate($row);
        $stored = self::decode($row->options_json);
        $import = $stored['import'] ?? null;
        if ($run->plan === null || !is_array($import) || !in_array($run->phase, self::IMPORT_PHASES, true)) {
            throw new StorageRejected('storage_sitepack_run_corrupt');
        }
        $source = $this->openImport($row);
        try {
            $importer = new StorageSitePackImporter(
                $this->database,
                $this->runs,
                $this->storage,
                $this->importWorkbench(),
                $this->fileImports,
                $this->localized,
                $this->publication,
                $this->roles,
                $run->plan,
                $import,
                $runRef,
                $scopeRef,
                (string) $row->site_ref,
                $actor,
                (string) ($run->correlationId ?? $this->correlationId(null)),
                $this->stagingPath($runRef),
                $this->importChunk,
                $this->fileChunk,
                fn (array $changes) => $this->save($runRef, $lease, $changes),
                fn () => $this->tick($checkpoint),
                function (string $type, array $details) use ($runRef): void {
                    $current = $this->mustFind($runRef);
                    /** @var array<string, int|string|null> $details */
                    $this->emit($type, $current, $current->correlationId, ['package_id' => $current->packageId] + $details);
                },
            );
            $importer->walk($source, $run->phase, self::decode($row->cursor), self::ints(self::decode($row->counts_json)), (int) $row->done);
        } finally {
            $source->close();
        }
        StorageSitePackExporter::wipe($this->stagingPath($runRef));
        if ($this->viewRebuilds !== null && is_string($import['schema_id'] ?? null)) {
            try {
                ($this->viewRebuilds)($import['schema_id']);
            } catch (Throwable) {
                // Best effort: a small storage builds its витрина at its first list, a large one by the rebuild command.
            }
        }

        return $this->mustFind($runRef);
    }

    /** The run's private copy of the pack, checked against the digest it had when it was received. */
    private function openImport(stdClass $row): StorageSitePackSource
    {
        $path = $this->archivePath((string) $row->run_ref);
        if (!is_file($path) || is_link($path) || filesize($path) !== (int) $row->archive_bytes
            || !hash_equals((string) $row->archive_sha256, (string) hash_file('sha256', $path))) {
            throw new StorageRejected('storage_sitepack_archive_missing');
        }

        return StorageSitePackSource::open($path, $this->limits);
    }

    private function importWorkbench(): DatabaseStorageWorkbench
    {
        if (!$this->workbench instanceof DatabaseStorageWorkbench) {
            throw new StorageRejected('storage_sitepack_import_unavailable');
        }

        return $this->workbench;
    }

    private function importedBefore(string $packageId, string $siteRef, string $runRef): bool
    {
        return $this->database->table(StorageSitePackRuns::TABLE)
            ->where('kind', StorageSitePackRun::KIND_IMPORT)
            ->where('state', StorageSitePackRun::STATE_DONE)
            ->where('package_id', $packageId)
            ->where('site_ref', $siteRef)
            ->where('run_ref', '!=', $runRef)
            ->exists();
    }

    /** @return list<string> */
    private function localesOf(string $siteRef): array
    {
        if ($this->siteLocales === null) {
            return [];
        }
        return array_values(array_filter(
            ($this->siteLocales)($siteRef),
            static fn (string $locale): bool => preg_match('/\A[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?\z/D', $locale) === 1,
        ));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, int>
     */
    private static function ints(array $values): array
    {
        $ints = [];
        foreach ($values as $key => $value) {
            if (is_int($value)) {
                $ints[$key] = $value;
            }
        }

        return $ints;
    }

    /**
     * Runs the export as far as it goes: the queue job's entry (no request; the run's own actor is checked again).
     * A run that ended is returned as it is.
     *
     * @param (Closure(): void)|null $checkpoint called between chunks (the job's heartbeat); it may throw to stop
     * @param string|null $jobType the queue job asking: a run that is not that job's (an import job for a run still to
     *        be planned) is returned as it is
     */
    public function process(string $runRef, ?Closure $checkpoint = null, ?string $jobType = null): StorageSitePackRun
    {
        $this->assertAvailable();
        $row = $this->runs->row($runRef) ?? throw new StorageRejected(self::REASON_UNKNOWN);
        $state = (string) $row->state;
        if (in_array($state, [StorageSitePackRun::STATE_DONE, StorageSitePackRun::STATE_CANCELLED, StorageSitePackRun::STATE_READY], true)) {
            return StorageSitePackRuns::hydrate($row);
        }
        $kind = (string) $row->kind;
        $phase = (string) $row->phase;
        $expected = $kind === StorageSitePackRun::KIND_EXPORT
            ? StorageSitePackJob::JOB_TYPE
            : ($phase === StorageSitePackRun::PHASE_PLAN ? StorageSitePackImportJob::PLAN_JOB_TYPE : StorageSitePackImportJob::IMPORT_JOB_TYPE);
        if ($jobType !== null && $jobType !== $expected) {
            return StorageSitePackRuns::hydrate($row);
        }
        if ($state === StorageSitePackRun::STATE_FAILED && !in_array((string) $row->reason, self::RETRYABLE, true)) {
            throw new StorageRejected((string) ($row->reason ?? 'storage_sitepack_failed'));
        }
        $lease = bin2hex(random_bytes(16));
        $now = $this->now();
        if (!$this->runs->take($runRef, $lease, [StorageSitePackRun::STATE_QUEUED, StorageSitePackRun::STATE_RUNNING, StorageSitePackRun::STATE_FAILED], $now, $this->now(-self::LEASE_SECONDS))) {
            throw new StorageConflict(self::REASON_BUSY);
        }
        try {
            if ($kind === StorageSitePackRun::KIND_IMPORT) {
                return $phase === StorageSitePackRun::PHASE_PLAN
                    ? $this->walkPlan($runRef, $lease, $checkpoint)
                    : $this->walkImport($runRef, $lease, $checkpoint);
            }

            return $this->walk($runRef, $lease, $checkpoint);
        } catch (StorageRejected $exception) {
            if ($exception->reasonCode === self::REASON_INTERRUPTED || !$this->held($runRef, $lease)) {
                // Cancelled (or taken over) under the process: whatever it failed on after that is not the run's.
                $this->afterInterruption($runRef);
                throw $exception->reasonCode === self::REASON_INTERRUPTED ? $exception : new StorageRejected(self::REASON_INTERRUPTED, $exception);
            }
            $this->fail($runRef, $lease, $exception->reasonCode);
            throw $exception;
        } catch (SitePackRejected $exception) {
            $this->fail($runRef, $lease, $exception->reasonCode);
            throw new StorageRejected($exception->reasonCode, $exception);
        } catch (Throwable $exception) {
            // The queue's timeout or cancel, or the database: the run resumes from its last save.
            $this->fail($runRef, $lease, self::REASON_INTERRUPTED);
            throw $exception;
        }
    }

    /**
     * Removes the archives, staging and rows of runs older than $days (default KEEP_DAYS). A run still being worked
     * on is left alone.
     *
     * @return int the runs removed
     */
    public function sweep(int $days = self::KEEP_DAYS): int
    {
        if ($days < 1) {
            throw new StorageRejected('storage_sitepack_sweep_invalid');
        }
        $this->assertAvailable();
        $before = $this->now(-$days * 86400);
        $stale = $this->now(-self::LEASE_SECONDS);
        $removed = 0;
        foreach ($this->runs->createdBefore($before, 1000) as $run) {
            if ($run->state === StorageSitePackRun::STATE_RUNNING && $run->updatedAt >= $stale) {
                continue;
            }
            $this->discard($run->runRef, true);
            @unlink($this->archivePath($run->runRef));
            $this->runs->delete($run->runRef);
            $removed++;
        }

        return $removed;
    }

    public function archivePath(string $runRef): string
    {
        return $this->directory . '/' . $runRef . '.zip';
    }

    private function walk(string $runRef, string $lease, ?Closure $checkpoint): StorageSitePackRun
    {
        $row = $this->runs->row($runRef) ?? throw new StorageRejected(self::REASON_UNKNOWN);
        $actor = (string) $row->actor;
        $scopeRef = (string) $row->scope_ref;
        $siteRef = (string) $row->site_ref;
        $this->assertAccess($actor, $scopeRef);
        $structure = $this->workbench->readStructure($scopeRef, (string) $row->structure_id, $actor);
        $options = self::decode($row->options_json);
        $phase = (string) $row->phase;
        if ($phase !== StorageSitePackRun::PHASE_PREPARE && !$this->sameFrame($structure, $options)) {
            throw new StorageRejected(self::REASON_STRUCTURE_CHANGED);
        }
        $plaintext = ($options[self::PLAINTEXT_OPTION] ?? false) === true;
        if ($plaintext) {
            // Checked again for every walk: a right taken away stops the export.
            $this->assertPlaintext($actor);
        }
        StorageSitePackExporter::directory($this->directory);
        $exporter = new StorageSitePackExporter(
            $this->database,
            $this->runs,
            $this->converter,
            $this->normalizer,
            $this->files,
            $this->limits,
            $runRef,
            $scopeRef,
            $siteRef,
            $structure,
            $this->stagingPath($runRef),
            $plaintext ? $this->keys : null,
        );
        $cursor = self::decode($row->cursor);
        $counts = self::decode($row->counts_json);
        $done = (int) $row->done;

        if ($phase === StorageSitePackRun::PHASE_PREPARE) {
            if ($structure->changing()) {
                throw new StorageRejected(self::REASON_FIELD_CHANGE);
            }
            $this->runs->clearIds($runRef);
            $total = $exporter->prepare($this->storage->schemaVersion($structure->schema));
            $options = ['generation' => (int) ($options['generation'] ?? 0)] + ($plaintext ? [self::PLAINTEXT_OPTION => true] : []) + self::frame($structure);
            $cursor = ['after' => null, 'part' => 1, 'lines' => 0, 'bytes' => 0];
            $counts = [];
            $done = 0;
            $phase = StorageSitePackRun::PHASE_RECORDS;
            $this->save($runRef, $lease, [
                'phase' => $phase,
                'cursor' => self::json($cursor),
                'total' => $total,
                'done' => 0,
                'counts_json' => '{}',
                'options_json' => self::json($options),
            ]);
        }

        if ($phase === StorageSitePackRun::PHASE_RECORDS) {
            /** @var array{after: ?string, part: int, lines: int, bytes: int} $records */
            $records = [
                'after' => is_string($cursor['after'] ?? null) ? $cursor['after'] : null,
                'part' => max(1, (int) ($cursor['part'] ?? 1)),
                'lines' => (int) ($cursor['lines'] ?? 0),
                'bytes' => (int) ($cursor['bytes'] ?? 0),
            ];
            $exporter->dropPartsAfter($records['part']);
            do {
                $this->tick($checkpoint);
                $this->assertFrame($structure, $options);
                $result = $exporter->records($records, $this->chunk);
                $records = $result['cursor'];
                $done += $result['records'];
                $counts = self::add($counts, $result['counts']);
                $last = $result['last'];
                $this->database->transaction(function () use ($runRef, $lease, $result, $records, $done, $counts, $last): void {
                    $this->runs->addIds($result['ids']);
                    $this->save($runRef, $lease, [
                        'cursor' => self::json($last ? ['after' => null, 'lines' => 0, 'bytes' => 0, 'records' => $records] : $records),
                        'phase' => $last ? StorageSitePackRun::PHASE_FILES : StorageSitePackRun::PHASE_RECORDS,
                        'done' => $done,
                        'counts_json' => self::json($counts),
                    ]);
                });
            } while (!$last);
            $phase = StorageSitePackRun::PHASE_FILES;
            $cursor = ['after' => null, 'lines' => 0, 'bytes' => 0, 'records' => $records];
        }

        if ($phase === StorageSitePackRun::PHASE_FILES) {
            $recordsCursor = is_array($cursor['records'] ?? null) ? $cursor['records'] : [];
            /** @var array{after: ?string, lines: int, bytes: int} $assets */
            $assets = [
                'after' => is_string($cursor['after'] ?? null) ? $cursor['after'] : null,
                'lines' => (int) ($cursor['lines'] ?? 0),
                'bytes' => (int) ($cursor['bytes'] ?? 0),
            ];
            do {
                $this->tick($checkpoint);
                $this->assertFrame($structure, $options);
                $result = $exporter->files($assets, $this->fileChunk);
                $assets = $result['cursor'];
                $counts = self::add($counts, $result['counts']);
                $last = $result['last'];
                $this->database->transaction(function () use ($runRef, $lease, $result, $assets, $counts, $last, $recordsCursor): void {
                    foreach ($result['updates'] as $update) {
                        $this->runs->updateId($runRef, StorageSitePackRuns::ID_KIND_FILE, $update['source_ref'], ['action' => $update['action'], 'sha256' => $update['sha256']]);
                    }
                    $this->save($runRef, $lease, [
                        'cursor' => self::json($assets + ['records' => $recordsCursor]),
                        'phase' => $last ? StorageSitePackRun::PHASE_SEAL : StorageSitePackRun::PHASE_FILES,
                        'counts_json' => self::json($counts),
                    ]);
                });
            } while (!$last);
            $phase = StorageSitePackRun::PHASE_SEAL;
            $cursor = $assets + ['records' => $recordsCursor];
        }

        if ($phase !== StorageSitePackRun::PHASE_SEAL) {
            throw new StorageRejected('storage_sitepack_run_corrupt');
        }
        $this->tick($checkpoint);
        $this->assertFrame($structure, $options);
        $recordsCursor = is_array($cursor['records'] ?? null) ? $cursor['records'] : [];
        $archive = $this->archivePath($runRef);
        @unlink($archive);
        $sealed = $exporter->seal(
            $archive,
            self::iso((string) $row->created_at),
            ['part' => max(1, (int) ($recordsCursor['part'] ?? 1)), 'bytes' => (int) ($recordsCursor['bytes'] ?? 0), 'lines' => (int) ($recordsCursor['lines'] ?? 0)],
            ['bytes' => (int) ($cursor['bytes'] ?? 0), 'lines' => (int) ($cursor['lines'] ?? 0)],
            fn () => $this->tick($checkpoint),
        );
        @chmod($archive, 0600);
        $size = filesize($archive);
        $sha256 = hash_file('sha256', $archive);
        if (!is_int($size) || !is_string($sha256)) {
            throw new StorageRejected('storage_sitepack_staging_failed');
        }
        $counts['references'] = $sealed['references'];
        $counts['structures'] = 1;
        $now = $this->now();
        $changed = $this->runs->update($runRef, [
            'state' => StorageSitePackRun::STATE_DONE,
            'phase' => StorageSitePackRun::PHASE_DONE,
            'cursor' => null,
            'counts_json' => self::json($counts),
            'package_id' => $sealed['envelope']->packageId,
            'archive_sha256' => $sha256,
            'archive_bytes' => $size,
            'lease_ref' => null,
            'reason' => null,
            'updated_at' => $now,
            'finished_at' => $now,
        ], $lease, [StorageSitePackRun::STATE_RUNNING]);
        if ($changed !== 1) {
            @unlink($archive);
            throw new StorageRejected(self::REASON_INTERRUPTED);
        }
        StorageSitePackExporter::wipe($this->stagingPath($runRef));
        $run = $this->mustFind($runRef);
        $this->emit(StorageSitePackAuditEventCatalog::EXPORTED, $run, $run->correlationId, [
            'package_id' => $run->packageId,
            'archive_sha256' => $sha256,
            'archive_bytes' => $size,
        ] + ($plaintext ? ['contains_decrypted_values' => true] : []) + $counts);

        return $run;
    }

    /** @param array<string, mixed> $changes */
    private function save(string $runRef, string $lease, array $changes): void
    {
        $changes['updated_at'] = $this->now();
        // MySQL counts changed rows: a save that changes nothing (a heartbeat within the same second) counts 0, so a
        // run still held is asked for.
        if ($this->runs->update($runRef, $changes, $lease, [StorageSitePackRun::STATE_RUNNING]) !== 1 && !$this->held($runRef, $lease)) {
            throw new StorageRejected(self::REASON_INTERRUPTED);
        }
    }

    private function fail(string $runRef, string $lease, string $reason): void
    {
        $reason = preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $reason) === 1 ? $reason : 'storage_sitepack_failed';
        $changes = ['state' => StorageSitePackRun::STATE_FAILED, 'reason' => $reason, 'lease_ref' => null, 'updated_at' => $this->now()];
        if (!in_array($reason, self::RETRYABLE, true)) {
            $changes['finished_at'] = $this->now();
        }
        if ($reason === self::REASON_STRUCTURE_CHANGED) {
            // What was written followed the old structure: the retry starts over.
            $changes += ['phase' => StorageSitePackRun::PHASE_PREPARE, 'cursor' => null, 'done' => 0, 'counts_json' => '{}'];
        }
        try {
            $row = $this->runs->row($runRef);
            $import = $row !== null && (string) $row->kind === StorageSitePackRun::KIND_IMPORT;
            if ($import && (string) $row->phase === StorageSitePackRun::PHASE_PLAN && !in_array($reason, self::RETRYABLE, true)) {
                // A plan that cannot be made leaves no id map behind.
                $changes += ['plan_json' => null, 'plan_digest' => null];
            }
            if ($this->runs->update($runRef, $changes, $lease, [StorageSitePackRun::STATE_RUNNING]) === 1) {
                if ($reason === self::REASON_STRUCTURE_CHANGED && !$import) {
                    $this->discard($runRef);
                } elseif ($import && !in_array($reason, self::RETRYABLE, true)) {
                    if ((string) $row->phase === StorageSitePackRun::PHASE_PLAN) {
                        $this->runs->clearIds($runRef);
                    } else {
                        $this->discard($runRef);
                    }
                }
            }
        } catch (Throwable) {
            // The run keeps its lease; a later process takes it over once it is stale.
        }
    }

    /** Whether this process still holds the run. */
    private function held(string $runRef, string $lease): bool
    {
        try {
            $row = $this->runs->row($runRef);

            return $row !== null && (string) $row->state === StorageSitePackRun::STATE_RUNNING && (string) $row->lease_ref === $lease;
        } catch (Throwable) {
            return true;
        }
    }

    /** A process stopped because the run moved on: a cancelled run's staging is removed again (it may have written since). */
    private function afterInterruption(string $runRef): void
    {
        try {
            $row = $this->runs->row($runRef);
            if ($row === null || (string) $row->state === StorageSitePackRun::STATE_CANCELLED) {
                $this->discard($runRef);
            }
        } catch (Throwable) {
        }
    }

    /**
     * Removes what a run staged. An export loses its archive; an import keeps its copy of the pack (a retry plans it
     * again) and discards what it wrote to the site, its id map but the files it created (they stay, reported).
     */
    private function discard(string $runRef, bool $withArchive = false): void
    {
        StorageSitePackExporter::wipe($this->stagingPath($runRef));
        $row = $this->runs->row($runRef);
        if ($row !== null && (string) $row->kind === StorageSitePackRun::KIND_IMPORT) {
            $import = self::decode($row->options_json)['import'] ?? null;
            if (is_array($import) && ($import['merge'] ?? false) === true) {
                // Into an adopted structure: only the records this run wrote go, never those of a run that ended;
                // the structure stays.
                if ((string) $row->state !== StorageSitePackRun::STATE_DONE) {
                    StorageSitePackImporter::discardMerged($this->database, $this->runs, $runRef,
                        is_string($import['schema_id'] ?? null) ? $import['schema_id'] : null, (string) ($row->correlation_id ?? ''));
                }
            } elseif (is_array($import) && $this->workbench instanceof DatabaseStorageWorkbench) {
                StorageSitePackImporter::discard(
                    $this->database,
                    $this->workbench,
                    $runRef,
                    (string) $row->scope_ref,
                    is_string($import['structure_id'] ?? null) ? $import['structure_id'] : null,
                    is_string($import['schema_id'] ?? null) ? $import['schema_id'] : null,
                );
            }
            $this->runs->clearIdsBut($runRef, StorageSitePackRuns::ID_KIND_FILE);
            if ($withArchive) {
                @unlink($this->archivePath($runRef));
                $this->runs->clearIds($runRef);
            }

            return;
        }
        @unlink($this->archivePath($runRef));
        $this->runs->clearIds($runRef);
    }

    private function tick(?Closure $checkpoint): void
    {
        if ($checkpoint !== null) {
            $checkpoint();
        }
    }

    /** @param array<string, mixed> $options */
    private function assertFrame(StorageWorkbenchStructure $structure, array $options): void
    {
        $head = $this->database->table('larena_storage_workbench_structures')
            ->where('scope_ref', $structure->scopeRef)
            ->where('structure_id', $structure->structureId)
            ->first(['current_version', 'current_schema_version']);
        if (!$head instanceof stdClass
            || (int) $head->current_version !== (int) ($options['structure_version'] ?? 0)
            || (int) $head->current_schema_version !== (int) ($options['schema_version'] ?? 0)) {
            throw new StorageRejected(self::REASON_STRUCTURE_CHANGED);
        }
    }

    /** @param array<string, mixed> $options */
    private function sameFrame(StorageWorkbenchStructure $structure, array $options): bool
    {
        return $structure->version === (int) ($options['structure_version'] ?? 0)
            && $structure->schema->version === (int) ($options['schema_version'] ?? 0)
            && $structure->schema->schemaId === ($options['schema_id'] ?? null);
    }

    /** @return array{schema_id: string, structure_version: int, schema_version: int} */
    private static function frame(StorageWorkbenchStructure $structure): array
    {
        return [
            'schema_id' => $structure->schema->schemaId,
            'structure_version' => $structure->version,
            'schema_version' => $structure->schema->version,
        ];
    }

    private function own(string $runRef, string $actor): StorageSitePackRun
    {
        $this->assertActor($actor);
        if (preg_match('/\A' . self::RUN_PREFIX . '[0-9a-f]{32}\z/D', $runRef) !== 1) {
            throw new StorageRejected(self::REASON_UNKNOWN);
        }
        $this->assertAvailable();
        $run = $this->runs->find($runRef);
        // Another person's run is answered as unknown: whether it exists is theirs.
        if ($run === null || $run->actor !== $actor) {
            throw new StorageRejected(self::REASON_UNKNOWN);
        }
        if ($run->kind === StorageSitePackRun::KIND_IMPORT) {
            $this->assertImportAccess($actor, $run->scopeRef);
        } else {
            $this->assertAccess($actor, $run->scopeRef);
        }

        return $run;
    }

    private function mustFind(string $runRef): StorageSitePackRun
    {
        return $this->runs->find($runRef) ?? throw new StorageRejected(self::REASON_UNKNOWN);
    }

    /**
     * An export needs the export operation and, in the scope, the rights to read the structure and list its records.
     */
    private function assertAccess(string $actor, string $scopeRef): void
    {
        $this->assertScope($actor, 'storage.workbench.structure.read', $scopeRef, 'storage.workbench.structure');
        $this->assertScope($actor, 'storage.workbench.record.list', $scopeRef, 'storage.workbench.record');
        $this->assertOperation($actor);
    }

    private function assertOperation(string $actor): void
    {
        $this->assertActor($actor);
        try {
            $this->authorizer->assertAllowed($actor, self::OPERATION);
        } catch (Throwable $exception) {
            throw new StorageRejected(self::REASON_DENIED, $exception);
        }
    }

    private function assertScope(string $actor, string $operation, string $scopeRef, string $resourceType, string $reason = self::REASON_DENIED): void
    {
        $this->assertActor($actor);
        if (preg_match('/^scope:[a-z][a-z0-9_.:-]{0,184}$/', $scopeRef) !== 1) {
            throw new StorageRejected('storage_workbench_scope_ref_invalid');
        }
        try {
            if (!$this->scopes->supports($resourceType, $operation)) {
                throw new StorageRejected($reason);
            }
            $context = ['requested_scope_ref' => $scopeRef, 'resource_type' => $resourceType];
            $decision = $this->scopes->explain($resourceType, $actor, $operation, $context);
            if (!$decision->isAllowed() || $decision->actor !== $actor || $decision->operation !== $operation) {
                throw new StorageRejected($reason);
            }
            $scoped = $this->scopes->scope(['scope_ref' => $scopeRef], $actor, $operation, $context);
            $keys = array_keys($scoped);
            sort($keys, SORT_STRING);
            if ($keys !== ['scope_ref'] || ($scoped['scope_ref'] ?? null) !== $scopeRef) {
                throw new StorageRejected($reason);
            }
            $this->authorizer->assertAllowed($actor, $operation);
        } catch (StorageRejected $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StorageRejected($reason, $exception);
        }
    }

    /**
     * An import needs the import operation (critical, always audited) and, in the scope, the rights to create a
     * structure and its records.
     */
    private function assertImportAccess(string $actor, string $scopeRef): void
    {
        $this->assertScope($actor, 'storage.workbench.structure.create', $scopeRef, 'storage.workbench.structure', self::REASON_IMPORT_DENIED);
        $this->assertScope($actor, 'storage.workbench.record.create', $scopeRef, 'storage.workbench.record', self::REASON_IMPORT_DENIED);
        if (!$this->allowed($actor, self::IMPORT_OPERATION)) {
            throw new StorageRejected(self::REASON_IMPORT_DENIED);
        }
    }

    private function allowed(string $actor, string $operation): bool
    {
        try {
            $this->authorizer->assertAllowed($actor, $operation);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function assertActor(string $actor): void
    {
        if (trim($actor) === '' || strlen($actor) > 191) {
            throw new StorageRejected('storage_sitepack_actor_invalid');
        }
    }

    private function assertAvailable(): void
    {
        if (!$this->runs->available()) {
            throw new StorageRejected('storage_sitepack_unavailable');
        }
    }

    private function siteOf(string $actor): string
    {
        $site = $this->siteOf === null ? 'site:main' : ($this->siteOf)($actor);
        if (preg_match('/\A[a-z][a-z0-9_-]*:[a-z0-9][a-z0-9_.:-]{0,70}\z/D', $site) !== 1) {
            throw new StorageRejected('storage_sitepack_site_invalid');
        }

        return $site;
    }

    private function dispatch(string $runRef, int $generation, string $jobType = StorageSitePackJob::JOB_TYPE): void
    {
        if ($this->jobs === null) {
            return;
        }
        $jobId = ($this->jobs)($runRef, $generation, $jobType);
        if (is_string($jobId) && $jobId !== '') {
            $this->runs->update($runRef, ['job_id' => substr($jobId, 0, 100)]);
        }
    }

    /** An export in plaintext: the decrypt right and a working key, asked before anything. */
    private function assertPlaintext(string $actor): void
    {
        $this->authorizer->assertAllowed($actor, StorageFieldReveal::OPERATION);
        if ($this->keys === null) {
            throw new StorageRejected(StorageEncryptionPolicy::UNAVAILABLE);
        }
        $this->keys->assertAvailable();
    }

    /** @param array<string, int|string|bool|null> $details */
    private function emit(string $type, StorageSitePackRun $run, ?string $correlationId, array $details): void
    {
        $this->events->emit(new StorageSecurityEvent(
            'sitepack',
            $type,
            $run->actor,
            'storage-sitepack:' . $run->runRef,
            $this->correlationId($correlationId),
            [
                'run_ref' => $run->runRef,
                'scope_ref' => $run->scopeRef,
                'site_ref' => $run->siteRef,
                'structure_id' => $run->structureId,
            ] + $details,
        ));
    }

    private function stagingPath(string $runRef): string
    {
        return $this->directory . '/' . $runRef . '.staging';
    }

    private function correlationId(?string $correlationId): string
    {
        if (is_string($correlationId) && trim($correlationId) !== '' && strlen($correlationId) <= 191) {
            return $correlationId;
        }

        return 'storage-sitepack-' . bin2hex(random_bytes(8));
    }

    private function now(int $offsetSeconds = 0): string
    {
        $now = $this->clock === null ? new DateTimeImmutable('now', new DateTimeZone('UTC')) : ($this->clock)();
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        if ($offsetSeconds !== 0) {
            $interval = new DateInterval('PT' . abs($offsetSeconds) . 'S');
            $now = $offsetSeconds > 0 ? $now->add($interval) : $now->sub($interval);
        }

        return $now->format('Y-m-d\TH:i:s\Z');
    }

    private static function iso(string $moment): string
    {
        return (new DateTimeImmutable($moment, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, int> $more
     * @return array<string, int>
     */
    private static function add(array $counts, array $more): array
    {
        foreach ($more as $key => $value) {
            $counts[$key] = (int) ($counts[$key] ?? 0) + $value;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected('storage_sitepack_run_corrupt');
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function json(mixed $value): string
    {
        return json_encode($value === [] ? new stdClass() : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
