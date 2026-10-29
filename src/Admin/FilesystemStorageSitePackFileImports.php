<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Database\ConnectionResolverInterface;
use Larena\Core\Contracts\ScopeResourceBindings;
use Larena\Core\Scope\ScopeRef;
use Larena\Filesystem\Contracts\PortableLogicalFileStore;
use Larena\Filesystem\Exceptions\PortableLogicalFileFailed;
use Larena\Filesystem\ValueObjects\PortableLogicalFileDescriptor;
use Larena\Filesystem\ValueObjects\PortableLogicalFileImportResult;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SitePack\StorageSitePackFile;
use Larena\Storage\SitePack\StorageSitePackFileImports;
use Throwable;

/**
 * The files a storage's SitePack import writes, through Filesystem's portable file store, each bound to the site it
 * is imported into by core's scope resource bindings (larena-specs docs/architecture/2026-10-22-storage-sitepack.md).
 * The import side of FilesystemStorageSitePackFiles:
 *
 * - a file belongs to a site when the bindings say so and Filesystem still holds it, live and readable;
 * - a content match counts only the live files bound to that site;
 * - an import creates the file under the descriptor's identity and binds it to the site in one transaction; when
 *   either fails nothing stays (a blob it stored is removed again). A file of that ref with that content already in
 *   the site (an import resumed after it was written) is answered with its ref and not written again.
 */
final readonly class FilesystemStorageSitePackFileImports implements StorageSitePackFileImports
{
    public const string FILE = FilesystemStorageSitePackFiles::FILE;

    public function __construct(
        private PortableLogicalFileStore $files,
        private ScopeResourceBindings $resources,
        private ConnectionResolverInterface $connections,
    ) {
    }

    public function importAvailable(): bool
    {
        return true;
    }

    public function belongsToSite(string $siteRef, string $logicalRef): bool
    {
        $site = ScopeRef::tryParse($siteRef);
        if ($site === null || !$this->resources->belongsTo(self::FILE, $logicalRef, $site)) {
            return false;
        }

        return $this->live($logicalRef) !== null;
    }

    public function contentMatches(string $siteRef, string $sha256, int $sizeBytes, string $extension): array
    {
        $site = ScopeRef::tryParse($siteRef);
        if ($site === null) {
            return [];
        }

        return $this->files->contentMatches($sha256, $sizeBytes, $extension,
            fn (string $logicalRef): bool => $this->resources->belongsTo(self::FILE, $logicalRef, $site));
    }

    public function logicalRefTaken(string $logicalRef): bool
    {
        return $this->files->logicalRefTaken($logicalRef);
    }

    public function publicIdTaken(string $publicId): bool
    {
        return $this->files->publicIdTaken($publicId);
    }

    public function import(string $siteRef, StorageSitePackFile $file): string
    {
        $site = ScopeRef::tryParse($siteRef) ?? throw new StorageRejected('storage_sitepack_site_invalid');
        $logicalRef = $file->logicalRef();
        if ($this->resources->belongsTo(self::FILE, $logicalRef, $site)) {
            // Already written into this site (a resumed import): the same content is the same file.
            $live = $this->live($logicalRef);
            if ($live !== null && $live->sha256 === $file->sha256() && $live->sizeBytes === $file->sizeBytes()) {
                return $logicalRef;
            }
            throw new StorageRejected('storage_sitepack_file_import_mismatch');
        }
        $descriptor = $this->descriptor($file);
        $stream = $file->openStream();
        $result = null;
        try {
            $this->connections->connection()->transaction(function () use ($descriptor, $stream, $logicalRef, $site, &$result): void {
                $result = $this->files->import($descriptor, $stream);
                $this->resources->bind(self::FILE, $logicalRef, $site);
            });
        } catch (Throwable $exception) {
            if ($result instanceof PortableLogicalFileImportResult) {
                // The row went with the transaction; the blob it stored goes too.
                $result->compensate();
            }
            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if (!$result instanceof PortableLogicalFileImportResult) {
            throw new StorageRejected('storage_sitepack_file_import_mismatch');
        }

        return $result->logicalRef;
    }

    /** The live file of this ref, or null when Filesystem no longer holds it (deleted, never there, blob gone). */
    private function live(string $logicalRef): ?PortableLogicalFileDescriptor
    {
        try {
            return $this->files->snapshot($logicalRef)->descriptor;
        } catch (PortableLogicalFileFailed $exception) {
            if (in_array($exception->reasonCode, ['filesystem_portable_file_not_found', 'filesystem_portable_blob_unavailable'], true)) {
                return null;
            }
            throw $exception;
        }
    }

    private function descriptor(StorageSitePackFile $file): PortableLogicalFileDescriptor
    {
        $d = $file->descriptor;
        try {
            return new PortableLogicalFileDescriptor(
                logicalRef: (string) $d['logical_ref'],
                publicId: (string) $d['public_id'],
                displayName: (string) $d['display_name'],
                originalName: (string) $d['original_name'],
                mimeType: (string) $d['mime_type'],
                extension: (string) $d['extension'],
                sizeBytes: (int) $d['size_bytes'],
                sha256: (string) $d['sha256'],
                visibility: (string) $d['visibility'],
                altText: $d['alt_text'] === null ? null : (string) $d['alt_text'],
            );
        } catch (PortableLogicalFileFailed $exception) {
            throw new StorageRejected('storage_sitepack_file_descriptor_invalid', $exception);
        }
    }
}
