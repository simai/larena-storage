<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

/**
 * The port a SitePack import writes files through (the import side of StorageSitePackFiles). Storage holds only
 * logical file refs; the application that composes Storage with Filesystem implements it over its portable file store
 * and core's scope resource bindings:
 *
 * - belongsToSite(): `ScopeResourceBindings::belongsTo('filesystem.logical_file', $logicalRef, $site)` and the file
 *   is live (`PortableLogicalFileStore::snapshot` finds it);
 * - contentMatches(): `PortableLogicalFileStore::contentMatches($sha256, $size, $extension, $belongs)` with $belongs
 *   limiting the matches to files bound to the site;
 * - logicalRefTaken() / publicIdTaken(): `PortableLogicalFileStore::logicalRefTaken()` / `publicIdTaken()` (deleted
 *   files included);
 * - import(): `PortableLogicalFileStore::import()` of the descriptor — the planner already put the identity to use in
 *   it (the pack's, or a new one, as `PortableLogicalFileDescriptor::withIdentity()` would) — and then
 *   `ScopeResourceBindings::bind('filesystem.logical_file', $logicalRef, $site)`.
 *
 * The import decides per file: the same logical ref bound to the site → reuse; another live file of the site with the
 * same content → reuse that one; otherwise create it under the pack's logical ref and public id when both are free,
 * else under new ones (remap). A file it created stays when an import is cancelled; the run reports it.
 *
 * Without an owner of files composed, UnavailableStorageSitePackFiles answers: a pack with files then has the plan
 * error `files_unavailable`; one without any imports as usual.
 */
interface StorageSitePackFileImports
{
    /** Whether files can be imported at all. */
    public function importAvailable(): bool;

    /** Whether the file of this logical ref is live and belongs to the site (e.g. `site:main`). */
    public function belongsToSite(string $siteRef, string $logicalRef): bool;

    /**
     * Logical refs of the live files of the site whose current content is exactly this one, in ref order.
     *
     * @return list<string>
     */
    public function contentMatches(string $siteRef, string $sha256, int $sizeBytes, string $extension): array;

    /** Whether any file, deleted ones included, holds this logical ref. */
    public function logicalRefTaken(string $logicalRef): bool;

    /** Whether any file, deleted ones included, holds this public id. */
    public function publicIdTaken(string $publicId): bool;

    /**
     * Creates the file in the site under the descriptor's identity, its content read from the file's stream, and binds
     * it to the site. Idempotent: when a file of that logical ref with that content already belongs to the site (an
     * import resumed after it was created), nothing is created again. Returns the logical ref. A failure throws.
     */
    public function import(string $siteRef, StorageSitePackFile $file): string;
}
