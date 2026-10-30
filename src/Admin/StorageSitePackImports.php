<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Core\SitePack\SitePackLimits;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SitePack\StorageSitePackSource;
use Throwable;

/**
 * The Storage section's «Загрузить хранилище» over Storage's SitePack import (larena-specs
 * docs/architecture/2026-10-22-storage-sitepack.md): an uploaded archive received into the person's scope and planned
 * at once with the defaults, planned again with what the person chose, applied with the digest of the plan they saw,
 * cancelled or retried. Storage checks every right on every call and keeps a run private to the person who started
 * it; another person's run is unknown to them. Without a queue Storage leaves a run to its caller, and it runs here,
 * in the request that started it.
 *
 * Runs come out as plain arrays, so the pages need nothing of Storage.
 */
final readonly class StorageSitePackImports
{
    public const string RUN_PATTERN = '/\Astorage-sitepack-[0-9a-f]{32}\z/D';
    public const string STRUCTURE_PATTERN = '/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D';
    private const int SCANNED = 50;

    public function __construct(
        private StorageSitePack $sitePack,
        private ?StorageSitePackImportNotes $notes = null,
        private ?SitePackLimits $limits = null,
    ) {
    }

    /**
     * The person's latest imports, newest first; null when SitePack is unavailable or the person may not import.
     *
     * @return list<array<string, mixed>>|null
     */
    public function runs(string $actor, int $limit = 10): ?array
    {
        try {
            $recent = $this->sitePack->recent($actor, self::SCANNED);
        } catch (StorageRejected) {
            return null;
        }
        $runs = [];
        foreach ($recent as $run) {
            if ($run->kind === StorageSitePackRun::KIND_IMPORT) {
                $runs[] = $this->project($run);
            }
            if (count($runs) >= max(1, $limit)) {
                break;
            }
        }

        return $runs;
    }

    /** @return array<string, mixed>|null the person's own import run, or null */
    public function read(string $actor, string $runRef): ?array
    {
        if (preg_match(self::RUN_PATTERN, $runRef) !== 1) {
            return null;
        }
        try {
            $run = $this->sitePack->read($runRef, $actor);
        } catch (StorageRejected) {
            return null;
        }

        return $run->kind === StorageSitePackRun::KIND_IMPORT ? $this->project($run) : null;
    }

    /**
     * Receives the archive the person uploaded into their scope, keeps what the page shows of it, and plans it with
     * the defaults (the pack's id and label, records as drafts).
     *
     * @return array{run: array<string, mixed>|null, reason: string|null}
     */
    public function receive(string $actor, string $scopeRef, string $archivePath, string $fileName, ?string $correlationId = null): array
    {
        try {
            $run = $this->sitePack->receive($scopeRef, $archivePath, $actor, $correlationId);
        } catch (StorageRejected $exception) {
            return ['run' => null, 'reason' => $exception->reasonCode];
        }
        $this->notes?->keep($actor, $run->runRef, ['name' => $fileName] + $this->describe($archivePath, (string) $run->structureId));
        try {
            return $this->inline($actor, $this->sitePack->plan($run->runRef, new StorageSitePackImportOptions(), $actor, $correlationId));
        } catch (StorageRejected $exception) {
            return ['run' => $this->read($actor, $run->runRef), 'reason' => $exception->reasonCode];
        }
    }

    /**
     * Plans the run again with what the person chose: the storage's id and name, the publication, a local storage
     * for each storage of the source site, a copy.
     *
     * @param array{target_structure_id?: ?string, target_label?: ?string, publication?: string, reference_targets?: array<string, string>, copy?: bool, transfer_domains?: bool} $choices
     * @return array{run: array<string, mixed>|null, reason: string|null}
     */
    public function plan(string $actor, string $runRef, array $choices, ?string $correlationId = null): array
    {
        try {
            $options = new StorageSitePackImportOptions(
                $choices['target_structure_id'] ?? null,
                $choices['target_label'] ?? null,
                $choices['publication'] ?? StorageSitePackImportOptions::PUBLICATION_DRAFT,
                $choices['reference_targets'] ?? [],
                $choices['copy'] ?? false,
                null,
                $choices['transfer_domains'] ?? false,
            );

            return $this->inline($actor, $this->sitePack->plan($runRef, $options, $actor, $correlationId));
        } catch (StorageRejected $exception) {
            return ['run' => null, 'reason' => $exception->reasonCode];
        }
    }

    /** @return array{run: array<string, mixed>|null, reason: string|null} */
    public function apply(string $actor, string $runRef, string $planDigest, ?string $correlationId = null): array
    {
        try {
            return $this->inline($actor, $this->sitePack->apply($runRef, $planDigest, $actor, $correlationId));
        } catch (StorageRejected $exception) {
            return ['run' => null, 'reason' => $exception->reasonCode];
        }
    }

    /** @return array{run: array<string, mixed>|null, reason: string|null} */
    public function cancel(string $actor, string $runRef, ?string $correlationId = null): array
    {
        try {
            return ['run' => $this->project($this->sitePack->cancel($runRef, $actor, $correlationId)), 'reason' => null];
        } catch (StorageRejected $exception) {
            return ['run' => null, 'reason' => $exception->reasonCode];
        }
    }

    /** @return array{run: array<string, mixed>|null, reason: string|null} */
    public function retry(string $actor, string $runRef, ?string $correlationId = null): array
    {
        try {
            return $this->inline($actor, $this->sitePack->retry($runRef, $actor, $correlationId));
        } catch (StorageRejected $exception) {
            return ['run' => null, 'reason' => $exception->reasonCode];
        }
    }

    /**
     * The source site, the storage's name there and the storages of that site its records point at, read from the
     * archive Storage has just accepted; nothing when it cannot be read (Storage's plan then says why).
     *
     * @return array{source_site: ?string, source_label: ?string, external: list<string>}
     */
    private function describe(string $archivePath, string $structureId): array
    {
        $described = ['source_site' => null, 'source_label' => null, 'external' => []];
        try {
            $source = StorageSitePackSource::open($archivePath, $this->limits ?? new SitePackLimits());
        } catch (Throwable) {
            return $described;
        }
        try {
            $described['source_site'] = $source->sourceSite();
            $described['source_label'] = is_string($source->structure['label'] ?? null) ? $source->structure['label'] : null;
            $external = [];
            foreach ($source->references() as $reference) {
                $id = $reference['structure_id'];
                if ($id !== null && $id !== $structureId && preg_match(self::STRUCTURE_PATTERN, $id) === 1) {
                    $external[$id] = true;
                    if (count($external) >= StorageSitePackImportNotes::MAX_EXTERNAL) {
                        break;
                    }
                }
            }
            $ids = array_keys($external);
            sort($ids, SORT_STRING);
            $described['external'] = array_map('strval', $ids);
        } catch (Throwable) {
            // What could be read is kept.
        } finally {
            $source->close();
        }

        return $described;
    }

    /**
     * A run no queue took (no job id) is run here until it waits for its person or ends; a failure is the run's own
     * reason.
     *
     * @return array{run: array<string, mixed>|null, reason: string|null}
     */
    private function inline(string $actor, StorageSitePackRun $run): array
    {
        if ($run->jobId !== null || $run->state !== StorageSitePackRun::STATE_QUEUED) {
            return ['run' => $this->project($run), 'reason' => null];
        }
        try {
            $run = $this->sitePack->run($run->runRef, $actor);
        } catch (Throwable) {
            try {
                $run = $this->sitePack->read($run->runRef, $actor);
            } catch (StorageRejected $exception) {
                return ['run' => null, 'reason' => $exception->reasonCode];
            }
        }

        return ['run' => $this->project($run), 'reason' => null];
    }

    /** @return array<string, mixed> */
    private function project(StorageSitePackRun $run): array
    {
        $active = in_array($run->state, [StorageSitePackRun::STATE_QUEUED, StorageSitePackRun::STATE_RUNNING], true);
        $ready = $run->state === StorageSitePackRun::STATE_READY;
        $plan = $ready && $run->phase === StorageSitePackRun::PHASE_PLANNED && $run->planDigest !== null ? $run->plan : null;

        return [
            'ref' => $run->runRef,
            'structure_id' => $run->structureId,
            'state' => $run->state,
            'phase' => $run->phase,
            'done' => $run->done,
            'total' => $run->total,
            'counts' => $run->counts,
            'package_id' => $run->packageId,
            'bytes' => (int) ($run->archiveBytes ?? 0),
            'reason' => $run->reason,
            'created_at' => $run->createdAt,
            'finished_at' => $run->finishedAt,
            'plan' => $plan?->toArray(),
            'plan_digest' => $plan === null ? null : $run->planDigest,
            'source' => $this->notes?->read($run->actor, $run->runRef),
            'active' => $active,
            'planning' => $active && $run->phase === StorageSitePackRun::PHASE_PLAN,
            'can_plan' => $ready,
            'can_apply' => $plan !== null && $plan->ok(),
            'can_cancel' => !in_array($run->state, [StorageSitePackRun::STATE_DONE, StorageSitePackRun::STATE_CANCELLED], true),
            'can_retry' => in_array($run->state, [StorageSitePackRun::STATE_FAILED, StorageSitePackRun::STATE_CANCELLED], true),
        ];
    }
}
