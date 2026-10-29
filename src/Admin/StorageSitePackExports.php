<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Exceptions\StorageRejected;
use ReflectionMethod;
use Throwable;

/**
 * The Storage section's «Перенос на другой сайт» over Storage's SitePack export (larena-specs
 * docs/architecture/2026-10-22-storage-sitepack.md): the person's runs of one storage, start, cancel, retry and the
 * archive to download. Storage checks every right and keeps a run private to the person who started it; another
 * person's run is unknown to them. Without a queue Storage leaves the run to its caller, and it runs here, in the
 * request that started it.
 *
 * Runs come out as plain arrays, so the pages need nothing of Storage.
 *
 * «Включить зашифрованные поля в открытом виде» is an option of Storage's own SitePack, beyond the contract
 * (`includeEncryptedPlaintext` of DatabaseStorageSitePack::startExport): it is offered and passed only while the bound
 * service takes it, and Storage checks the decrypt right, audits the start and marks the manifest.
 */
final readonly class StorageSitePackExports
{
    public const string RUN_PATTERN = '/\Astorage-sitepack-[0-9a-f]{32}\z/D';
    /** The export option of Storage's own SitePack that puts the encrypted fields' values in the pack in plaintext. */
    public const string PLAINTEXT_OPTION = 'includeEncryptedPlaintext';
    /** The refusals of an export in plaintext the section gives itself. */
    public const string PLAINTEXT_UNSUPPORTED = 'storage_sitepack_plaintext_unsupported';
    public const string PLAINTEXT_DENIED = 'storage_sitepack_plaintext_denied';
    private const int SCANNED = 50;

    public function __construct(private StorageSitePack $sitePack)
    {
    }

    /**
     * The person's latest exports of this storage, newest first; null when SitePack is unavailable or the person
     * may not export.
     *
     * @return list<array<string, mixed>>|null
     */
    public function runs(string $actor, string $scopeRef, string $structureId, int $limit = 5): ?array
    {
        try {
            $recent = $this->sitePack->recent($actor, self::SCANNED);
        } catch (StorageRejected) {
            return null;
        }
        $runs = [];
        foreach ($recent as $run) {
            if ($run->kind === StorageSitePackRun::KIND_EXPORT && $run->scopeRef === $scopeRef && $run->structureId === $structureId) {
                $runs[] = self::project($run);
            }
            if (count($runs) >= max(1, $limit)) {
                break;
            }
        }

        return $runs;
    }

    /** @return array<string, mixed>|null the person's own run, or null */
    public function read(string $actor, string $runRef): ?array
    {
        if (preg_match(self::RUN_PATTERN, $runRef) !== 1) {
            return null;
        }
        try {
            return self::project($this->sitePack->read($runRef, $actor));
        } catch (StorageRejected) {
            return null;
        }
    }

    /**
     * Starts the person's export; with $includeEncryptedPlaintext the pack carries the encrypted fields' values in
     * plaintext, when the bound SitePack takes the option (refused in words otherwise, never exported without them).
     *
     * @return array{run: array<string, mixed>|null, reason: string|null}
     */
    public function start(string $actor, string $scopeRef, string $structureId, ?string $correlationId = null, bool $includeEncryptedPlaintext = false): array
    {
        try {
            if (!$includeEncryptedPlaintext) {
                return $this->inline($actor, $this->sitePack->startExport($scopeRef, $structureId, $actor, $correlationId));
            }
            if (!$this->supportsPlaintext()) {
                return ['run' => null, 'reason' => self::PLAINTEXT_UNSUPPORTED];
            }
            $run = (new ReflectionMethod($this->sitePack, 'startExport'))->invokeArgs($this->sitePack, [
                'scopeRef' => $scopeRef, 'structureId' => $structureId, 'actor' => $actor, 'correlationId' => $correlationId,
                self::PLAINTEXT_OPTION => true,
            ]);
            if (!$run instanceof StorageSitePackRun) {
                return ['run' => null, 'reason' => self::PLAINTEXT_UNSUPPORTED];
            }

            return $this->inline($actor, $run);
        } catch (StorageRejected $exception) {
            return ['run' => null, 'reason' => $exception->reasonCode];
        } catch (AccessMutationRejected) {
            return ['run' => null, 'reason' => $includeEncryptedPlaintext ? self::PLAINTEXT_DENIED : 'storage_sitepack_export_denied'];
        }
    }

    /** Whether the bound SitePack's export takes the plaintext option of encrypted fields (a boolean parameter by that name). */
    public function supportsPlaintext(): bool
    {
        try {
            foreach ((new ReflectionMethod($this->sitePack, 'startExport'))->getParameters() as $parameter) {
                if ($parameter->getName() === self::PLAINTEXT_OPTION) {
                    $type = $parameter->getType();

                    return $type instanceof \ReflectionNamedType && $type->getName() === 'bool';
                }
            }
        } catch (Throwable) {
        }

        return false;
    }

    /** @return array{run: array<string, mixed>|null, reason: string|null} */
    public function cancel(string $actor, string $runRef, ?string $correlationId = null): array
    {
        try {
            return ['run' => self::project($this->sitePack->cancel($runRef, $actor, $correlationId)), 'reason' => null];
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
     * The sealed archive of the person's finished export; null for anything else (another person's run included).
     *
     * @return array{path: string, name: string, size: int, sha256: string}|null
     */
    public function archive(string $actor, string $runRef, ?string $correlationId = null): ?array
    {
        if (preg_match(self::RUN_PATTERN, $runRef) !== 1) {
            return null;
        }
        try {
            $file = $this->sitePack->archive($runRef, $actor, $correlationId);
        } catch (StorageRejected) {
            return null;
        }

        return ['path' => $file->path, 'name' => $file->downloadName, 'size' => $file->sizeBytes, 'sha256' => $file->sha256];
    }

    /**
     * A run no queue took (no job id) is run here until it ends; a failure is the run's own reason.
     *
     * @return array{run: array<string, mixed>|null, reason: string|null}
     */
    private function inline(string $actor, StorageSitePackRun $run): array
    {
        if ($run->jobId !== null || $run->state !== StorageSitePackRun::STATE_QUEUED) {
            return ['run' => self::project($run), 'reason' => null];
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

        return ['run' => self::project($run), 'reason' => null];
    }

    /** @return array<string, mixed> */
    private static function project(StorageSitePackRun $run): array
    {
        $active = in_array($run->state, [StorageSitePackRun::STATE_QUEUED, StorageSitePackRun::STATE_RUNNING], true);

        return [
            'ref' => $run->runRef,
            'structure_id' => $run->structureId,
            'state' => $run->state,
            'phase' => $run->phase,
            'done' => $run->done,
            'total' => $run->total,
            'records' => (int) ($run->counts['records'] ?? 0),
            'files' => (int) ($run->counts['files'] ?? 0),
            'bytes' => $run->archiveBytes ?? (int) ($run->counts['blob_bytes'] ?? 0),
            'reason' => $run->reason,
            'created_at' => $run->createdAt,
            'finished_at' => $run->finishedAt,
            'can_cancel' => $active || $run->state === StorageSitePackRun::STATE_FAILED,
            'can_retry' => in_array($run->state, [StorageSitePackRun::STATE_FAILED, StorageSitePackRun::STATE_CANCELLED], true),
            'can_download' => $run->isDone(),
        ];
    }
}
