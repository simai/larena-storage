<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Storage\DatabaseQueueStore;
use Larena\Storage\Contracts\PublicationGuard;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StructureRole;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Exceptions\PublicationRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\NullStorageSecurityEventSink;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\SitePack\DatabaseStorageSitePack;
use Larena\Storage\SitePack\StorageSitePackExporter;
use Larena\Storage\SitePack\StorageSitePackFile;
use Larena\Storage\SitePack\StorageSitePackFileImports;
use Larena\Storage\SitePack\StorageSitePackFiles;
use Larena\Storage\SitePack\StorageSitePackImportJob;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewRegistry;
use Larena\Storage\View\StorageViewSync;

require_once __DIR__ . '/storage-sitepack-fixture.php';

/**
 * The storage SitePack import scenario (slice «Storage B: plan and import»), on any connection: SQLite in
 * StorageSitePackImportTest, a throwaway MySQL database in StorageSitePackImportMySqlTest.
 */

/** Every known actor may do everything in every scope, but the operations denied to them. */
final class SitePackImportAccess implements ActorOperationAuthorizer, QueryScopeProvider
{
    /** @param array<string, list<string>> $denied actor => denied operations */
    public function __construct(private array $denied)
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        if (!array_key_exists($actor, $this->denied) || in_array($operation, $this->denied[$actor], true)) {
            throw new RuntimeException('sitepack_import_fixture_denied');
        }
    }

    public function supports(string $resourceType, string $operation): bool
    {
        return true;
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        return $query;
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        $allowed = array_key_exists($actor, $this->denied) && !in_array($operation, $this->denied[$actor], true);

        return $allowed
            ? AccessDecision::allow($operation, $actor, $resourceType . ':any', 'sitepack_import_fixture_allowed')
            : AccessDecision::deny($operation, $actor, $resourceType . ':denied', 'sitepack_import_fixture_denied');
    }
}

/** Files of several sites, in memory: both sides of the SitePack file port. */
final class SitePackImportFiles implements StorageSitePackFiles, StorageSitePackFileImports
{
    /** @var array<string, array{descriptor: array<string, mixed>, bytes: string, site: string}> */
    public array $files = [];

    /** @var list<string> */
    public array $imported = [];

    public function add(string $logicalRef, string $name, string $bytes, string $site = 'site:main', ?string $publicId = null): void
    {
        $this->files[$logicalRef] = ['descriptor' => [
            'logical_ref' => $logicalRef,
            'public_id' => $publicId ?? substr($logicalRef, 0, 35) . 'f',
            'display_name' => ucfirst(pathinfo($name, PATHINFO_FILENAME)),
            'original_name' => $name,
            'mime_type' => 'image/png',
            'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'visibility' => 'public',
            'alt_text' => null,
        ], 'bytes' => $bytes, 'site' => $site];
    }

    public function available(): bool
    {
        return true;
    }

    public function snapshot(string $siteRef, string $logicalRef): ?StorageSitePackFile
    {
        $file = $this->files[$logicalRef] ?? null;
        if ($file === null || $file['site'] !== $siteRef) {
            return null;
        }
        $bytes = $file['bytes'];

        return new StorageSitePackFile($file['descriptor'], static function () use ($bytes) {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $bytes);
            rewind($stream);

            return $stream;
        });
    }

    public function importAvailable(): bool
    {
        return true;
    }

    public function belongsToSite(string $siteRef, string $logicalRef): bool
    {
        return ($this->files[$logicalRef]['site'] ?? null) === $siteRef;
    }

    public function contentMatches(string $siteRef, string $sha256, int $sizeBytes, string $extension): array
    {
        $matches = [];
        foreach ($this->files as $ref => $file) {
            if ($file['site'] === $siteRef && $file['descriptor']['sha256'] === $sha256 && $file['descriptor']['size_bytes'] === $sizeBytes
                && $file['descriptor']['extension'] === $extension) {
                $matches[] = (string) $ref;
            }
        }
        sort($matches, SORT_STRING);

        return $matches;
    }

    public function logicalRefTaken(string $logicalRef): bool
    {
        return isset($this->files[$logicalRef]);
    }

    public function publicIdTaken(string $publicId): bool
    {
        foreach ($this->files as $file) {
            if ($file['descriptor']['public_id'] === $publicId) {
                return true;
            }
        }

        return false;
    }

    public function import(string $siteRef, StorageSitePackFile $file): string
    {
        $ref = $file->logicalRef();
        $existing = $this->files[$ref] ?? null;
        if ($existing !== null) {
            if ($existing['site'] === $siteRef && $existing['descriptor']['sha256'] === $file->sha256()) {
                return $ref;
            }
            throw new RuntimeException('sitepack_import_fixture_file_conflict');
        }
        $stream = $file->openStream();
        $bytes = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($bytes) || hash('sha256', $bytes) !== $file->sha256()) {
            throw new RuntimeException('sitepack_import_fixture_file_digest');
        }
        $this->files[$ref] = ['descriptor' => $file->descriptor, 'bytes' => $bytes, 'site' => $siteRef];
        $this->imported[] = $ref;

        return $ref;
    }
}

/** A published key another record holds: a record titled «Taken» cannot be published. */
final readonly class SitePackImportKeyGuard implements PublicationGuard
{
    public function __construct(private Connection $connection)
    {
    }

    public function assertMayPublish(string $schemaId, string $recordId, string $scopeRef, string $locale, int $revision): void
    {
        $values = $this->connection->table('larena_storage_record_versions')
            ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->value('values_json');
        $decoded = is_string($values) ? json_decode($values, true) : null;
        if (is_array($decoded) && ($decoded['title'] ?? null) === 'Taken') {
            throw new PublicationRejected('key_conflict', 'slug is already published');
        }
    }
}

function sitePackImportInstall(Connection $connection): void
{
    sitePackInstall($connection);
    Schema::swap($connection->getSchemaBuilder());
    (require __DIR__ . '/../../database/migrations/2026_10_23_000001_add_larena_storage_workbench_structure_import_run.php')->up();
}

/**
 * Everything one installation needs: workbench, storage, витрина, translations, publication, roles and the SitePack.
 *
 * @param array<string, list<string>> $denied
 * @param list<string> $users
 * @return array{workbench: DatabaseStorageWorkbench, storage: VersionedStorage, views: StorageViewSync, localized: DatabaseLocalizedValues, publication: DatabasePublicationLifecycle, roles: DatabaseStructureRoleRegistry, sitePack: Closure(array<string, mixed>=): DatabaseStorageSitePack, events: SitePackCapturedEvents, files: SitePackImportFiles, directory: string, rebuilds: ArrayObject<int, string>}
 */
function sitePackImportRuntime(Connection $connection, StorageViewDdl $ddl, array $denied, array $users, string $site = 'site:main', ?Closure $recordValidators = null, bool $carryTranslations = false): array
{
    $properties = PropertyTypeRegistry::builtIns();
    $access = new SitePackImportAccess($denied);
    $events = new SitePackCapturedEvents();
    $views = new StorageViewSync($connection, new StorageViewRegistry($connection, 0.0), $ddl);
    $cursorKey = 'storage-sitepack-import-fixture-cursor-key-32-bytes';
    $storage = new VersionedStorage($connection, $properties, $access, new NullStorageSecurityEventSink(), $access, $cursorKey, null, $views, new StorageMetrics());
    $policies = new StorageSchemaEvolutionOwnerPolicyRegistry();
    $policies->protect(
        'larena/storage',
        static function (StorageSchemaEvolutionOwnerContext $context, ?object $capability): void {
            if (!$capability instanceof DatabaseStorageWorkbench) {
                throw new RuntimeException('capability_invalid');
            }
        },
        'workbench.',
    );
    $policies->seal();
    $localized = new DatabaseLocalizedValues($connection);
    $workbench = new DatabaseStorageWorkbench($connection, $properties, $access, $access, $storage, $policies, $cursorKey, null, $views,
        recordValidators: $recordValidators, translations: $carryTranslations ? static fn (): DatabaseLocalizedValues => $localized : null);
    $publication = new DatabasePublicationLifecycle(
        $connection,
        static fn (string $schemaId, string $recordId, int $revision): bool => $connection->table('larena_storage_record_versions')
            ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->exists(),
        null,
        null,
        new SitePackImportKeyGuard($connection),
    );
    $roles = new DatabaseStructureRoleRegistry($connection);
    if ($roles->read('item_card@v1') === null) {
        $roles->register(new StructureRole('item_card', 1, 'Item card', [['key' => 'title', 'type' => 'string']], [], [], RoleLifecycle::Plain, 'larena/storage'), 'actor:installer');
    }
    $files = new SitePackImportFiles();
    $directory = sys_get_temp_dir() . '/larena-storage-sitepack-import-' . bin2hex(random_bytes(6));
    $clock = new SitePackFixtureClock(new DateTimeImmutable('2026-10-22T09:00:00Z'));
    $rebuilds = new ArrayObject();
    $make = static fn (array $with = []): DatabaseStorageSitePack => new DatabaseStorageSitePack(...array_merge([
        'database' => $connection,
        'workbench' => $workbench,
        'storage' => $storage,
        'propertyTypes' => $properties,
        'authorizer' => $access,
        'scopes' => $access,
        'directory' => $directory,
        'files' => $files,
        'events' => $events,
        'siteOf' => static fn (string $actor): string => $site,
        'clock' => $clock->closure(),
        'chunk' => 2,
        'fileChunk' => 1,
        'localized' => $localized,
        'publication' => $publication,
        'roles' => $roles,
        'userExists' => static fn (string $user): bool => in_array($user, $users, true),
        'siteLocales' => static fn (string $siteRef): array => ['en', 'ru'],
        'viewRebuilds' => static function (string $schemaId) use ($rebuilds): void {
            $rebuilds->append($schemaId);
        },
        'importChunk' => 2,
    ], $with));

    return [
        'workbench' => $workbench,
        'storage' => $storage,
        'views' => $views,
        'localized' => $localized,
        'publication' => $publication,
        'roles' => $roles,
        'sitePack' => $make,
        'events' => $events,
        'files' => $files,
        'directory' => $directory,
        'rebuilds' => $rebuilds,
    ];
}

/** Receives, plans and runs the plan of an archive. */
function sitePackImportPlan(DatabaseStorageSitePack $sitePack, string $scope, string $archive, string $actor, StorageSitePackImportOptions $options = new StorageSitePackImportOptions()): StorageSitePackRun
{
    $run = $sitePack->receive($scope, $archive, $actor, 'corr-import');
    storageViewExpect($run->state === StorageSitePackRun::STATE_READY && $run->phase === StorageSitePackRun::PHASE_RECEIVED, 'the received run is off: ' . json_encode($run->toArray()));
    $sitePack->plan($run->runRef, $options, $actor, null, false);

    return $sitePack->run($run->runRef, $actor);
}

/** Applies a run's plan in this process. */
function sitePackImportApply(DatabaseStorageSitePack $sitePack, StorageSitePackRun $planned, string $actor): StorageSitePackRun
{
    storageViewExpect($planned->plan !== null && $planned->planDigest !== null && $planned->plan->ok(), 'the plan has errors: ' . json_encode($planned->plan?->errors));
    $sitePack->apply($planned->runRef, $planned->planDigest, $actor, null, false);

    return $sitePack->run($planned->runRef, $actor);
}

/** @return array<string, string> structure id => schema id of a scope */
function sitePackImportStructures(Connection $connection, string $scope): array
{
    $map = [];
    foreach ($connection->table('larena_storage_workbench_structures')->where('scope_ref', $scope)->get() as $row) {
        $map[(string) $row->structure_id] = (string) $row->storage_schema_id;
    }

    return $map;
}

/** @return array<string, array{revision: int, values: array<string, mixed>}> current values by record id */
function sitePackImportRecords(Connection $connection, string $schemaId): array
{
    $records = [];
    foreach ($connection->table('larena_storage_records as heads')
        ->join('larena_storage_record_versions as versions', static function ($join): void {
            $join->on('versions.schema_id', '=', 'heads.schema_id')
                ->on('versions.record_id', '=', 'heads.record_id')
                ->on('versions.revision', '=', 'heads.current_revision');
        })
        ->where('heads.schema_id', $schemaId)
        ->get(['heads.record_id', 'heads.current_revision', 'versions.values_json']) as $row) {
        $records[(string) $row->record_id] = ['revision' => (int) $row->current_revision, 'values' => json_decode((string) $row->values_json, true)];
    }
    ksort($records);

    return $records;
}

/** Counts the rows a storage has in the tables an import writes. @return array<string, int> */
function sitePackImportFootprint(Connection $connection, string $schemaId): array
{
    return [
        'records' => $connection->table('larena_storage_records')->where('schema_id', $schemaId)->count(),
        'versions' => $connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->count(),
        'localized' => $connection->table('larena_storage_localized_values')->where('schema_id', $schemaId)->count(),
        'publications' => $connection->table('larena_storage_publication_states')->where('schema_id', $schemaId)->count(),
        'publication_log' => $connection->table('larena_storage_publication_log')->where('schema_id', $schemaId)->count(),
        'relations' => $connection->table('larena_storage_record_relations')->where('schema_id', $schemaId)->count(),
        'schemas' => $connection->table('larena_storage_schemas')->where('schema_id', $schemaId)->count(),
        'bindings' => $connection->table('larena_storage_structure_role_bindings')->where('schema_id', $schemaId)->count(),
        'structures' => $connection->table('larena_storage_workbench_structures')->where('storage_schema_id', $schemaId)->count(),
        'views' => $connection->table('larena_storage_views')->where('schema_id', $schemaId)->count(),
    ];
}

/** Two maps with the same entries, whatever their order. @param array<string, mixed> $actual @param array<string, mixed> $expected */
function sitePackImportEq(array $actual, array $expected): bool
{
    ksort($actual);
    ksort($expected);

    return $actual === $expected;
}

/** @param list<array{code: string}> $issues @return list<string> codes */
function sitePackImportCodes(array $issues): array
{
    return array_values(array_unique(array_map(static fn (array $issue): string => $issue['code'], $issues)));
}

/** Replaces one artifact's bytes in a copy of an archive, keeping its size (the catalog then lies about its digest). */
function sitePackImportCorrupt(string $archive, string $target, string $path): void
{
    copy($archive, $target);
    $zip = new ZipArchive();
    $zip->open($target);
    $bytes = (string) $zip->getFromName($path);
    // A title changes one letter: every line still reads, only the digest tells.
    $position = (int) strpos($bytes, '"title":"') + strlen('"title":"');
    $bytes = substr_replace($bytes, $bytes[$position] === 'X' ? 'Y' : 'X', $position, 1);
    $zip->deleteName($path);
    $zip->addFromString($path, $bytes);
    $zip->close();
}

/**
 * The scenario: a storage of one installation (scope alpha) exported, then imported into a fresh installation
 * ($fresh, when given) and into a second scope of the same installation (beta), with replay, copy, locales, corrupt and
 * truncated archives, cancel, resume, keep-publication with a key conflict, required users and references, the
 * editor refused, the queue jobs and the command.
 *
 * @param (Closure(): array{connection: Connection, ddl: StorageViewDdl, close: Closure(): void})|null $fresh
 */
function sitePackImportScenario(Connection $connection, StorageViewDdl $ddl, string $label, ?Closure $fresh = null): void
{
    $admin = 'user:admin_identity:1';
    $editor = 'user:admin_identity:7';
    $ops = 'actor:ops';
    $alpha = 'scope:tenant-alpha';
    $beta = 'scope:tenant-beta';
    $gamma = 'scope:tenant-gamma';
    $denied = [$admin => [], $ops => [], $editor => [DatabaseStorageSitePack::IMPORT_OPERATION, DatabaseStorageSitePack::OPERATION]];
    sitePackImportInstall($connection);
    $source = sitePackImportRuntime($connection, $ddl, $denied, [$admin, 'user:admin_identity:2', $editor]);
    $workbench = $source['workbench'];
    $localized = $source['localized'];
    // The source publishes without the key guard; the targets have it.
    $publication = new DatabasePublicationLifecycle($connection);
    $files = $source['files'];
    $sitePack = ($source['sitePack'])();
    $site = 'site:main';
    $scratch = sys_get_temp_dir() . '/larena-storage-sitepack-import-scratch-' . bin2hex(random_bytes(6));
    StorageSitePackExporter::directory($scratch);

    try {
        // ===== The source: authors and items in scope alpha, exported. =====
        $authors = $workbench->createStructure($alpha, ['structure_id' => 'workbench.authors', 'label' => 'Authors', 'fields' => [
            storageViewField('name', 10, 'string', ['max_length' => 200], true),
        ]], $admin);
        $authorOne = $workbench->createRecord($alpha, 'workbench.authors', ['name' => 'Ada'], $admin);
        $authorTwo = $workbench->createRecord($alpha, 'workbench.authors', ['name' => 'Grace'], $admin);
        $items = $workbench->createStructure($alpha, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => [
            array_merge(storageViewField('title', 10, 'string', ['max_length' => 200], true), ['indexed' => true]),
            array_merge(storageViewField('headline', 20, 'string', ['max_length' => 200]), ['localized' => true, 'searchable' => true]),
            array_merge(storageViewField('body', 30, 'text'), ['localized' => true, 'hidden' => true]),
            storageViewField('cover', 40, 'file'),
            storageViewField('author', 50, 'record', ['target_structure_id' => 'workbench.authors']),
            storageViewField('parent', 60, 'record', ['target_structure_id' => 'workbench.items']),
            storageViewField('owner', 70, 'user'),
            storageViewField('reviewer', 80, 'user', [], true),
            storageViewField('qty', 90, 'integer'),
        ]], $admin);
        $items = $workbench->setHistoryKeep($alpha, 'workbench.items', 10, $admin);
        $schemaId = $items->schema->schemaId;
        $fileOne = '11111111-1111-4111-8111-111111111111';
        $fileTwo = '22222222-2222-4222-8222-222222222222';
        $fileMissing = '33333333-3333-4333-8333-333333333333';
        $fileFour = '44444444-4444-4444-8444-444444444444';
        $fileFive = '55555555-5555-4555-8555-555555555555';
        $files->add($fileOne, 'cover.png', 'PNG-BYTES-SHARED');
        $files->add($fileTwo, 'copy.png', 'PNG-BYTES-SHARED');
        $files->add($fileFour, 'other.png', 'PNG-BYTES-OTHER');
        $files->add($fileFive, 'fifth.png', 'PNG-BYTES-FIFTH');
        $reviewer = 'user:admin_identity:2';

        // r1: three live revisions (en publishes 1 and schedules 2, ru publishes 3), translations in two locales.
        $r1 = $workbench->createRecord($alpha, 'workbench.items', ['title' => 'One', 'cover' => $fileOne, 'author' => $authorOne->recordId, 'owner' => $admin, 'reviewer' => $reviewer, 'qty' => 1], $admin);
        $localized->write($schemaId, $r1->recordId, 1, 'en', ['headline' => 'Hello one', 'body' => 'Body one'], ['headline', 'body'], $admin);
        $localized->write($schemaId, $r1->recordId, 1, 'ru', ['headline' => 'Привет один'], ['headline', 'body'], $admin);
        $publication->publish($schemaId, $r1->recordId, $site, 'en', 1, $admin);
        $r1 = $workbench->updateRecord($alpha, 'workbench.items', $r1->recordId, 1, ['title' => 'One v2', 'cover' => $fileOne, 'author' => $authorOne->recordId, 'owner' => $admin, 'reviewer' => $reviewer, 'qty' => 2], $admin);
        $publication->schedule($schemaId, $r1->recordId, $site, 'en', 2, '2030-01-01 00:00:00', $admin);
        $r1 = $workbench->updateRecord($alpha, 'workbench.items', $r1->recordId, 2, ['title' => 'One v3', 'cover' => $fileOne, 'author' => $authorOne->recordId, 'owner' => $admin, 'reviewer' => $reviewer, 'qty' => 3], $admin);
        $localized->write($schemaId, $r1->recordId, 3, 'en', ['headline' => 'Hello three'], ['headline', 'body'], $admin);
        $localized->write($schemaId, $r1->recordId, 3, 'ru', ['headline' => 'Привет три'], ['headline', 'body'], $admin);
        $publication->publish($schemaId, $r1->recordId, $site, 'ru', 3, $admin);
        // r2: archived, with a file another site of the target holds under the same ref.
        $r2 = $workbench->createRecord($alpha, 'workbench.items', ['title' => 'Two', 'cover' => $fileFour, 'reviewer' => $reviewer], $admin);
        $r2 = $workbench->archiveRecord($alpha, 'workbench.items', $r2->recordId, 1, $admin);
        // r3: a child of r1, published under a key another record holds; relations inside and out.
        $r3 = $workbench->createRecord($alpha, 'workbench.items', ['title' => 'Taken', 'cover' => $fileTwo, 'parent' => $r1->recordId, 'author' => $authorTwo->recordId, 'reviewer' => $admin], $admin);
        $publication->publish($schemaId, $r3->recordId, $site, 'en', 1, $admin);
        foreach ([
            ['section', 'tree_parent', $r1->recordId, 2, 0],
            ['see_also', 'reference', $authorTwo->recordId, 0, 5],
        ] as [$key, $kind, $to, $depth, $order]) {
            $connection->table('larena_storage_record_relations')->insert([
                'relation_id' => $key . '|' . $r3->recordId . '|' . $to, 'relation_key' => $key, 'schema_id' => $schemaId,
                'from_record_id' => $r3->recordId, 'to_record_id' => $to, 'kind' => $kind, 'tree_child_key' => $kind === 'tree_parent' ? $r3->recordId : null,
                'path' => $kind === 'tree_parent' ? $r1->recordId . '/' . $r3->recordId : null, 'depth' => $depth, 'order_index' => $order,
                'delete_policy' => 'restrict', 'status' => 'active', 'created_by' => $admin, 'correlation_id' => null,
                'created_at' => '2026-10-22 08:00:00', 'updated_at' => '2026-10-22 08:00:00',
            ]);
        }
        // r4: three revisions, only the current one is live; a file only it has.
        $r4 = $workbench->createRecord($alpha, 'workbench.items', ['title' => 'Four draft', 'reviewer' => $reviewer], $admin);
        $r4 = $workbench->updateRecord($alpha, 'workbench.items', $r4->recordId, 1, ['title' => 'Four draft 2', 'reviewer' => $reviewer], $admin);
        $r4 = $workbench->updateRecord($alpha, 'workbench.items', $r4->recordId, 2, ['title' => 'Four', 'cover' => $fileFive, 'reviewer' => $reviewer], $admin);
        // r5: a file that is gone.
        $r5 = $workbench->createRecord($alpha, 'workbench.items', ['title' => 'Five', 'cover' => $fileMissing, 'reviewer' => $reviewer], $admin);
        $source['roles']->bindStructure('item_card@v1', $schemaId, $site, $source['storage']->schemaVersion($items->schema)->fields, $admin);
        $sourceIds = [$r1->recordId, $r2->recordId, $r3->recordId, $r4->recordId, $r5->recordId];
        sort($sourceIds);

        $exported = $sitePack->run($sitePack->startExport($alpha, 'workbench.items', $admin, null, false)->runRef, $admin);
        storageViewExpect($exported->state === StorageSitePackRun::STATE_DONE && $exported->counts['files'] === 4 && $exported->counts['files_missing'] === 1, $label . ': the export is off: ' . json_encode($exported->counts));
        $itemsPack = $scratch . '/items.sitepack.zip';
        copy($sitePack->archive($exported->runRef, $admin)->path, $itemsPack);
        $authorsPack = $scratch . '/authors.sitepack.zip';
        copy($sitePack->archive($sitePack->run($sitePack->startExport($alpha, 'workbench.authors', $admin, null, false)->runRef, $admin)->runRef, $admin)->path, $authorsPack);

        // ===== I1: a fresh installation keeps every id. =====
        if ($fresh !== null) {
            $other = $fresh();
            try {
                sitePackImportFresh($other['connection'], $other['ddl'], $label, $itemsPack, $sourceIds, [
                    'r1' => $r1->recordId, 'r3' => $r3->recordId, 'r4' => $r4->recordId, 'r5' => $r5->recordId, 'r2' => $r2->recordId,
                    'file_one' => $fileOne, 'file_four' => $fileFour, 'file_five' => $fileFive, 'author_one' => $authorOne->recordId,
                ], $denied, $admin, $alpha);
            } finally {
                ($other['close'])();
            }
            Schema::swap($connection->getSchemaBuilder());
        }

        // ===== I2: the same installation, scope beta: every record id is taken, so every one is remapped. =====
        // First the authors, so the items' authors resolve through that import's id map.
        $authorsRun = sitePackImportPlan($sitePack, $beta, $authorsPack, $admin);
        storageViewExpect($authorsRun->plan !== null && sitePackImportEq($authorsRun->plan->records, ['total' => 2, 'keep' => 0, 'new' => 2, 'archived' => 0, 'versions' => 2, 'localized_values' => 0, 'relations' => 0]),
            $label . ': the authors plan is off: ' . json_encode($authorsRun->plan?->records));
        $authorsRun = sitePackImportApply($sitePack, $authorsRun, $admin);
        storageViewExpect($authorsRun->state === StorageSitePackRun::STATE_DONE && $authorsRun->counts['records'] === 2, $label . ': the authors import is off: ' . json_encode($authorsRun->toArray()));
        $betaStructures = sitePackImportStructures($connection, $beta);
        $betaAuthors = sitePackImportRecords($connection, $betaStructures['workbench.authors']);
        storageViewExpect(count($betaAuthors) === 2 && !isset($betaAuthors[$authorOne->recordId]) && !isset($betaAuthors[$authorTwo->recordId]), $label . ': the authors kept taken ids');
        $authorMap = [];
        foreach ($connection->table('larena_storage_sitepack_ids')->where('run_ref', $authorsRun->runRef)->where('kind', 'record')->get() as $row) {
            $authorMap[(string) $row->source_ref] = (string) $row->target_ref;
        }

        $planned = sitePackImportPlan($sitePack, $beta, $itemsPack, $admin);
        $plan = $planned->plan;
        storageViewExpect($plan !== null && $plan->ok(), $label . ': the beta plan has errors: ' . json_encode($plan?->errors));
        storageViewExpect($plan->structure['target_id'] === 'workbench.items' && $plan->structure['kept'] === true
            && $plan->records['total'] === 5 && $plan->records['new'] === 5 && $plan->records['keep'] === 0 && $plan->records['archived'] === 1,
            $label . ': the beta plan records are off: ' . json_encode([$plan->structure, $plan->records]));
        storageViewExpect(sitePackImportEq($plan->files, ['total' => 4, 'reuse' => 4, 'create' => 0, 'remap' => 0, 'missing' => 0, 'bytes' => 0]), $label . ': the beta files are off: ' . json_encode($plan->files));
        storageViewExpect(sitePackImportEq($plan->references, ['total' => 2, 'resolved' => 2, 'unresolved' => 0]) && $plan->locales === ['en', 'ru'] && $plan->publication['mode'] === 'draft'
            && $plan->roles === [['role_ref' => 'item_card@v1', 'action' => 'bind']] && sitePackImportEq($plan->users, ['total' => 2, 'missing' => 0]),
            $label . ': the beta plan is off: ' . json_encode($plan->toArray()));
        storageViewExpect(sitePackImportCodes($plan->warnings) === ['file_missing_cleared'], $label . ': the beta warnings are off: ' . json_encode($plan->warnings));
        storageViewExpect($planned->planDigest === 'sha256:' . hash('sha256', Larena\Core\SitePack\SitePackEnvelope::canonicalJson($plan->toArray())), $label . ': the plan digest is off');
        // Nothing outside the run tables yet.
        storageViewExpect(!isset(sitePackImportStructures($connection, $beta)['workbench.items']), $label . ': the plan wrote a structure');
        sitePackRejects(static fn () => $sitePack->apply($planned->runRef, 'sha256:' . str_repeat('0', 64), $admin, null, false), 'storage_sitepack_plan_digest_mismatch', $label . ': another digest applied');
        $done = sitePackImportApply($sitePack, $planned, $admin);
        storageViewExpect($done->state === StorageSitePackRun::STATE_DONE && $done->counts['records'] === 5 && $done->counts['versions'] === 7
            && ($done->counts['files_reused'] ?? 0) === 4 && ($done->counts['files_created'] ?? 0) === 0 && $done->counts['relations'] === 2,
            $label . ': the beta import counts are off: ' . json_encode($done->counts));
        $betaStructures = sitePackImportStructures($connection, $beta);
        $betaItems = $betaStructures['workbench.items'];
        $map = [];
        foreach ($connection->table('larena_storage_sitepack_ids')->where('run_ref', $done->runRef)->where('kind', 'record')->get() as $row) {
            $map[(string) $row->source_ref] = (string) $row->target_ref;
        }
        $records = sitePackImportRecords($connection, $betaItems);
        storageViewExpect(count($records) === 5 && count(array_intersect(array_keys($records), $sourceIds)) === 0, $label . ': the beta ids were not remapped');
        $one = $records[$map[$r1->recordId]];
        $three = $records[$map[$r3->recordId]];
        storageViewExpect($one['revision'] === 3 && $one['values']['title'] === 'One v3' && $one['values']['author'] === $authorMap[$authorOne->recordId]
            && $one['values']['larena_scope_ref'] === $beta && $one['values']['cover'] === $fileOne && $one['values']['reviewer'] === $reviewer,
            $label . ': r1 was not rewritten: ' . json_encode($one));
        storageViewExpect($three['values']['parent'] === $map[$r1->recordId] && $three['values']['author'] === $authorMap[$authorTwo->recordId],
            $label . ': r3 references were not rewritten: ' . json_encode($three));
        storageViewExpect($records[$map[$r4->recordId]]['revision'] === 1 && $records[$map[$r2->recordId]]['values']['larena_record_state'] === 'archived'
            && !array_key_exists('cover', $records[$map[$r5->recordId]]['values']), $label . ': the revisions, the state or the missing file are off');
        $edges = $connection->table('larena_storage_record_relations')->where('schema_id', $betaItems)->orderBy('relation_key')->get();
        storageViewExpect(count($edges) === 2 && $edges[0]->from_record_id === $map[$r3->recordId] && $edges[0]->to_record_id === $map[$r1->recordId]
            && $edges[0]->path === $map[$r1->recordId] . '/' . $map[$r3->recordId] && $edges[0]->tree_child_key === $map[$r3->recordId]
            && $edges[1]->to_record_id === $authorMap[$authorTwo->recordId], $label . ': the relations were not rewritten: ' . json_encode($edges));
        $revisions = $connection->table('larena_storage_record_versions')->where('schema_id', $betaItems)->where('record_id', $map[$r1->recordId])->orderBy('revision')->pluck('revision')->all();
        storageViewExpect(array_map('intval', $revisions) === [1, 2, 3], $label . ': r1 revisions are not 1..3');
        storageViewExpect($connection->table('larena_storage_localized_values')->where('schema_id', $betaItems)->where('record_id', $map[$r1->recordId])->count() === 5,
            $label . ': the translations are off');
        storageViewExpect($connection->table('larena_storage_publication_states')->where('schema_id', $betaItems)->count() === 0, $label . ': a draft import published');
        storageViewExpect($connection->table('larena_storage_structure_role_bindings')->where('schema_id', $betaItems)->where('role_ref', 'item_card@v1')->where('scope_ref', $site)->count() === 1,
            $label . ': the role was not bound');
        $listed = array_map(static fn ($structure): string => $structure->structureId, $workbench->listStructures($beta, $admin));
        storageViewExpect(in_array('workbench.items', $listed, true), $label . ': the imported structure is not listed');
        $page = $workbench->listRecords(new StorageWorkbenchRecordQuery($beta, 'workbench.items', [], null, [], 50, null, true), $admin);
        storageViewExpect(count($page->items) === 5, $label . ': the imported records do not list: ' . count($page->items));
        $imported = $workbench->readStructure($beta, 'workbench.items', $admin);
        storageViewExpect($imported->historyKeep === 10 && count($imported->fields) === 9, $label . ': the imported structure lost its settings');
        $updated = $workbench->updateRecord($beta, 'workbench.items', $map[$r4->recordId], 1, ['title' => 'Four edited', 'reviewer' => $reviewer], $admin);
        storageViewExpect($updated->revision === 2, $label . ': an imported record cannot be edited');
        storageViewExpect(in_array($betaItems, iterator_to_array($source['rebuilds']), true), $label . ': the витрина rebuild was not queued');
        $planEvents = $source['events']->of('storage.sitepack.import_planned');
        $importEvents = $source['events']->of('storage.sitepack.imported');
        storageViewExpect(count($importEvents) === 2 && $importEvents[1]->payload['records'] === 5 && $importEvents[1]->payload['target_structure_id'] === 'workbench.items'
            && count($planEvents) >= 2 && $planEvents[1]->payload['plan_digest'] === $planned->planDigest, $label . ': the import was not audited');

        // ===== I3: the same pack into the same site again: refused as a replay; copy imports it beside. =====
        $replay = sitePackImportPlan($sitePack, $beta, $itemsPack, $admin);
        storageViewExpect($replay->plan !== null && in_array('already_imported', $replay->plan->errorCodes(), true)
            && ($replay->plan->replay['run_ref'] ?? null) === $done->runRef && ($replay->plan->replay['structure_id'] ?? null) === 'workbench.items'
            && $replay->plan->structure['target_id'] === 'workbench.items_2', $label . ': the replay was not detected: ' . json_encode($replay->plan?->toArray()));
        sitePackRejects(static fn () => $sitePack->apply($replay->runRef, (string) $replay->planDigest, $admin, null, false), 'storage_sitepack_plan_has_errors', $label . ': a replay applied');
        $copy = sitePackImportPlan($sitePack, $beta, $itemsPack, $admin, new StorageSitePackImportOptions(null, 'Items (copy)', 'draft', [], true));
        storageViewExpect($copy->plan !== null && $copy->plan->ok() && $copy->plan->records['new'] === 5 && $copy->plan->structure['target_id'] === 'workbench.items_2',
            $label . ': the copy plan is off: ' . json_encode($copy->plan?->toArray()));
        $copied = sitePackImportApply($sitePack, $copy, $admin);
        storageViewExpect($copied->state === StorageSitePackRun::STATE_DONE && $workbench->readStructure($beta, 'workbench.items_2', $admin)->label === 'Items (copy)', $label . ': the copy was not imported');
        $copiedRecords = sitePackImportRecords($connection, sitePackImportStructures($connection, $beta)['workbench.items_2']);
        storageViewExpect(count(array_intersect(array_keys($copiedRecords), array_keys($records))) === 0, $label . ': the copy reused ids of the first import');
        $copiedThree = null;
        foreach ($copiedRecords as $record) {
            if ($record['values']['title'] === 'Taken') {
                $copiedThree = $record;
            }
        }
        storageViewExpect($copiedThree !== null && isset($copiedRecords[$copiedThree['values']['parent']]), $label . ': the copy did not point the parent at its own records');

        // ===== I4: a locale the site does not declare is a plan error. =====
        $locales = sitePackImportPlan($sitePack, $gamma, $itemsPack, $admin, new StorageSitePackImportOptions(null, null, 'draft', [], true, ['en']));
        storageViewExpect($locales->plan !== null && $locales->plan->errors === [['code' => 'locale_not_declared', 'count' => 1, 'subject' => 'ru']],
            $label . ': locale_not_declared is missing: ' . json_encode($locales->plan?->errors));

        // ===== I5: a required reference that does not resolve, a required user without a user to stand in. =====
        $required = sitePackImportPlan($sitePack, $gamma, $itemsPack, $ops, new StorageSitePackImportOptions(null, null, 'draft', [], true));
        storageViewExpect($required->plan !== null && $required->plan->ok(), $label . ': the gamma plan as ops failed: ' . json_encode($required->plan?->errors));
        $notes = $workbench->createStructure($alpha, ['structure_id' => 'workbench.notes', 'label' => 'Notes', 'fields' => [
            storageViewField('text', 10, 'string', [], true),
            storageViewField('author', 20, 'record', ['target_structure_id' => 'workbench.authors'], true),
            storageViewField('checker', 30, 'user', [], true),
        ]], $admin);
        $workbench->createRecord($alpha, 'workbench.notes', ['text' => 'A note', 'author' => $authorOne->recordId, 'checker' => 'user:admin_identity:9'], $admin);
        $notesPack = $scratch . '/notes.sitepack.zip';
        copy($sitePack->archive($sitePack->run($sitePack->startExport($alpha, 'workbench.notes', $admin, null, false)->runRef, $admin)->runRef, $admin)->path, $notesPack);
        unset($notes);
        $asOps = sitePackImportPlan($sitePack, $gamma, $notesPack, $ops);
        storageViewExpect($asOps->plan !== null && in_array('reference_required_unresolved', $asOps->plan->errorCodes(), true)
            && in_array('user_required_missing', $asOps->plan->errorCodes(), true) && sitePackImportEq($asOps->plan->users, ['total' => 1, 'missing' => 1]),
            $label . ': the required handling as ops is off: ' . json_encode($asOps->plan?->toArray()));
        $mapped = sitePackImportPlan($sitePack, $beta, $notesPack, $admin, new StorageSitePackImportOptions(null, null, 'draft', ['workbench.authors' => 'workbench.authors']));
        storageViewExpect($mapped->plan !== null && $mapped->plan->ok() && sitePackImportCodes($mapped->plan->warnings) === ['user_missing_set_to_importer'],
            $label . ': the mapped notes plan is off: ' . json_encode($mapped->plan?->toArray()));
        $mappedDone = sitePackImportApply($sitePack, $mapped, $admin);
        $note = array_values(sitePackImportRecords($connection, sitePackImportStructures($connection, $beta)['workbench.notes']))[0];
        storageViewExpect($mappedDone->state === StorageSitePackRun::STATE_DONE && $note['values']['author'] === $authorMap[$authorOne->recordId] && $note['values']['checker'] === $admin,
            $label . ': the required user or reference was not handled: ' . json_encode($note));

        // ===== I6: a corrupt digest and a truncated archive change nothing. =====
        $before = $connection->table('larena_storage_workbench_structures')->count();
        $recordsBefore = $connection->table('larena_storage_records')->count();
        $corrupt = $scratch . '/corrupt.sitepack.zip';
        sitePackImportCorrupt($itemsPack, $corrupt, 'artifacts/storage/records/workbench.items/part-000001.jsonl');
        $broken = $sitePack->receive($gamma, $corrupt, $admin);
        $sitePack->plan($broken->runRef, new StorageSitePackImportOptions(), $admin, null, false);
        sitePackRejects(static fn () => $sitePack->run($broken->runRef, $admin), 'sitepack_artifact_digest_mismatch', $label . ': a corrupt pack was planned');
        $failed = $sitePack->read($broken->runRef, $admin);
        storageViewExpect($failed->state === StorageSitePackRun::STATE_FAILED && $failed->plan === null
            && $connection->table('larena_storage_sitepack_ids')->where('run_ref', $broken->runRef)->count() === 0, $label . ': the corrupt plan left something: ' . json_encode($failed->toArray()));
        $truncated = $scratch . '/truncated.sitepack.zip';
        file_put_contents($truncated, substr((string) file_get_contents($itemsPack), 0, (int) (filesize($itemsPack) / 2)));
        $runsBefore = $connection->table('larena_storage_sitepack_runs')->count();
        try {
            $sitePack->receive($gamma, $truncated, $admin);
            throw new LogicException('a truncated pack was received');
        } catch (Larena\Storage\Exceptions\StorageRejected $exception) {
            storageViewExpect(str_starts_with($exception->reasonCode, 'sitepack_archive'), $label . ': the truncated pack was refused with ' . $exception->reasonCode);
        }
        storageViewExpect($connection->table('larena_storage_sitepack_runs')->count() === $runsBefore && $connection->table('larena_storage_workbench_structures')->count() === $before
            && $connection->table('larena_storage_records')->count() === $recordsBefore, $label . ': a broken pack changed something');

        // ===== I7: cancelled mid-import, nothing stays; files it created stay and are counted. =====
        $files->files = array_filter($files->files, static fn (array $file): bool => $file['site'] === 'site:main');
        $cancelFiles = new SitePackImportFiles();
        $cancelling = ($source['sitePack'])(['files' => $cancelFiles, 'siteOf' => static fn (string $actor): string => 'site:second']);
        $planned = sitePackImportPlan($cancelling, $gamma, $itemsPack, $admin, new StorageSitePackImportOptions(null, null, 'draft', [], true));
        storageViewExpect($planned->plan !== null && $planned->plan->files['create'] === 4, $label . ': the second site does not create the files: ' . json_encode($planned->plan?->files));
        $cancelling->apply($planned->runRef, (string) $planned->planDigest, $admin, null, false);
        $ticks = 0;
        sitePackRejects(static function () use ($cancelling, $planned, $admin, &$ticks): void {
            $cancelling->process($planned->runRef, static function () use ($cancelling, $planned, $admin, &$ticks): void {
                if (++$ticks === 7) {
                    $cancelling->cancel($planned->runRef, $admin);
                }
            });
        }, 'storage_sitepack_interrupted', $label . ': the cancelled import went on');
        $cancelled = $cancelling->read($planned->runRef, $admin);
        $gammaSchema = Larena\Storage\Runtime\ScopedStorageWorkbenchSchemaIdentity::derive($gamma, 'workbench.items');
        storageViewExpect($cancelled->state === StorageSitePackRun::STATE_CANCELLED && sitePackImportFootprint($connection, $gammaSchema) === array_fill_keys(['records', 'versions', 'localized', 'publications', 'publication_log', 'relations', 'schemas', 'bindings', 'structures', 'views'], 0),
            $label . ': the cancel left a trace: ' . json_encode(sitePackImportFootprint($connection, $gammaSchema)));
        storageViewExpect(count($cancelFiles->imported) === 4 && ($cancelled->counts['files_created'] ?? 0) >= 1, $label . ': the files the import created are not reported: ' . json_encode($cancelled->counts));
        // Retried, it plans again: the files it created are reused now.
        $retried = $cancelling->retry($planned->runRef, $admin, null, false);
        storageViewExpect($retried->state === StorageSitePackRun::STATE_QUEUED && $retried->phase === 'plan', $label . ': the retry did not plan again');
        $replanned = $cancelling->run($planned->runRef, $admin);
        storageViewExpect($replanned->plan !== null && $replanned->plan->files['reuse'] === 4, $label . ': the re-plan did not reuse the created files: ' . json_encode($replanned->plan?->files));

        // ===== I8: interrupted mid-import, the importing structure is hidden and closed; resumed, it ends once. =====
        $resuming = ($source['sitePack'])(['files' => $cancelFiles, 'siteOf' => static fn (string $actor): string => 'site:second']);
        $resuming->apply($replanned->runRef, (string) $replanned->planDigest, $admin, null, false);
        $ticks = 0;
        try {
            $resuming->process($replanned->runRef, static function () use (&$ticks): void {
                if (++$ticks === 6) {
                    throw new RuntimeException('worker stopped');
                }
            });
            throw new LogicException('the interruption did not stop the import');
        } catch (RuntimeException $exception) {
            storageViewExpect($exception->getMessage() === 'worker stopped', $label . ': the interruption was swallowed');
        }
        $stopped = $resuming->read($replanned->runRef, $admin);
        storageViewExpect($stopped->state === StorageSitePackRun::STATE_FAILED && $stopped->reason === 'storage_sitepack_interrupted' && $stopped->phase === 'records' && $stopped->done === 2,
            $label . ': the interrupted import is off: ' . json_encode($stopped->toArray()));
        storageViewExpect(!in_array('workbench.items', array_map(static fn ($structure): string => $structure->structureId, $workbench->listStructures($gamma, $admin)), true),
            $label . ': an importing structure is listed');
        sitePackRejects(static fn () => $workbench->readStructure($gamma, 'workbench.items', $admin), 'storage_workbench_structure_importing', $label . ': an importing structure was read');
        sitePackRejects(static fn () => $workbench->createRecord($gamma, 'workbench.items', ['title' => 'Sneak', 'reviewer' => $admin], $admin), 'storage_workbench_structure_importing', $label . ': an importing structure took a record');
        $resumed = $resuming->process($replanned->runRef);
        storageViewExpect($resumed->state === StorageSitePackRun::STATE_DONE && $resumed->done === 5 && $resumed->counts['records'] === 5
            && $connection->table('larena_storage_records')->where('schema_id', $gammaSchema)->count() === 5
            && $connection->table('larena_storage_record_versions')->where('schema_id', $gammaSchema)->count() === 7, $label . ': the resumed import is off: ' . json_encode($resumed->toArray()));
        storageViewExpect($workbench->readStructure($gamma, 'workbench.items', $admin)->structureId === 'workbench.items', $label . ': the resumed structure stayed importing');

        // ===== I9: an editor without the import right is refused; another person's run is not theirs. =====
        sitePackRejects(static fn () => $sitePack->receive($beta, $itemsPack, $editor), 'storage_sitepack_import_denied', $label . ': an editor received a pack');
        sitePackRejects(static fn () => $sitePack->read($done->runRef, $editor), 'storage_sitepack_run_unknown', $label . ': an editor read the import');
        sitePackRejects(static fn () => $sitePack->receive($beta, $scratch . '/nothing.zip', $admin), 'storage_sitepack_archive_missing', $label . ': a missing archive was received');

        // ===== I10: the plan and import jobs through the queue. =====
        $queueClock = new StorageViewTestClock(new DateTimeImmutable('2026-10-22T09:00:00Z'));
        $registry = new JobTypeRegistry();
        $store = new DatabaseQueueStore($connection);
        $dispatcher = new DurableQueueDispatcher($registry, $store, $queueClock);
        $queued = ($source['sitePack'])(['jobs' => static fn (string $runRef, int $generation, string $jobType): string => $dispatcher->dispatch(StorageSitePackImportJob::request($jobType, $runRef, $generation))->jobId]);
        foreach ([StorageSitePackImportJob::PLAN_JOB_TYPE, StorageSitePackImportJob::IMPORT_JOB_TYPE] as $jobType) {
            $registry->register(StorageSitePackImportJob::descriptor($jobType), new StorageSitePackImportJob($queued, $jobType));
        }
        $worker = new DurableQueueWorker($registry, $store, $queueClock);
        $run = $queued->receive('scope:tenant-delta', $authorsPack, $admin);
        $run = $queued->plan($run->runRef, new StorageSitePackImportOptions(null, null, 'draft', [], true), $admin);
        storageViewExpect($run->jobId !== null, $label . ': the plan was not queued');
        $job = $worker->runNext('sitepack-import-worker');
        storageViewExpect($job !== null && $job->status === JobStatus::Completed, $label . ': the plan job did not complete');
        $run = $queued->read($run->runRef, $admin);
        $run = $queued->apply($run->runRef, (string) $run->planDigest, $admin);
        $job = $worker->runNext('sitepack-import-worker');
        storageViewExpect($job !== null && $job->status === JobStatus::Completed && $queued->read($run->runRef, $admin)->state === StorageSitePackRun::STATE_DONE, $label . ': the import job did not complete');

        // ===== I11: the command. =====
        $container = new class extends Illuminate\Container\Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $container->instance(Larena\Storage\Contracts\StorageSitePack::class, $sitePack);
        $command = static function (array $input) use ($container): array {
            $command = new Larena\Storage\Console\Commands\StorageSitePackImportCommand();
            $command->setLaravel($container);
            $output = new Symfony\Component\Console\Output\BufferedOutput();
            $arguments = new Symfony\Component\Console\Input\ArrayInput($input);
            $arguments->setInteractive(false);
            $status = $command->run($arguments, $output);

            return [$status, trim($output->fetch())];
        };
        [$status, $printed] = $command(['archive' => $authorsPack, '--scope' => 'scope:tenant-epsilon', '--actor' => $admin, '--plan-only' => true, '--copy' => true]);
        $result = json_decode($printed, true);
        storageViewExpect($status === 0 && is_array($result) && $result['phase'] === 'planned' && str_starts_with((string) $result['plan_digest'], 'sha256:'), $label . ': --plan-only did not plan: ' . $printed);
        [$status, $printed] = $command(['archive' => $authorsPack, '--scope' => 'scope:tenant-epsilon', '--actor' => $admin, '--sync' => true, '--copy' => true, '--target' => 'workbench.people']);
        $result = json_decode($printed, true);
        storageViewExpect($status === 0 && is_array($result) && $result['state'] === 'done' && $result['structure_id'] === 'workbench.people', $label . ': --sync did not import: ' . $printed);
        [$status, $printed] = $command(['archive' => $authorsPack, '--scope' => 'scope:tenant-epsilon', '--actor' => $admin, '--sync' => true, '--target' => 'workbench.people']);
        storageViewExpect($status === 1 && str_contains($printed, 'structure_id_taken'), $label . ': a taken target was imported: ' . $printed);
        [$status] = $command(['archive' => $authorsPack, '--scope' => 'scope:tenant-epsilon']);
        storageViewExpect($status === 2, $label . ': the command ran without an actor');

        // ===== I12: the real archive exported from the stand (450 records, user and record values). =====
        $demo = __DIR__ . '/../fixtures/storage-sitepack-demo.zip';
        $planned = sitePackImportPlan($sitePack, 'scope:tenant-demo', $demo, $admin);
        $plan = $planned->plan;
        storageViewExpect($plan !== null && $plan->ok() && $plan->records['total'] === 450 && $plan->records['keep'] === 450
            && $plan->references['unresolved'] === 7 && $plan->users['total'] === 2 && $plan->users['missing'] === 0,
            $label . ': the demo plan is off: ' . json_encode($plan?->toArray()));
        $demoDone = sitePackImportApply($sitePack, $planned, $admin);
        $demoSchema = sitePackImportStructures($connection, 'scope:tenant-demo')['workbench.demo_solutions'] ?? '';
        storageViewExpect($demoDone->state === StorageSitePackRun::STATE_DONE && $connection->table('larena_storage_records')->where('schema_id', $demoSchema)->count() === 450,
            $label . ': the demo import is off: ' . json_encode($demoDone->counts));
        $linked = 0;
        foreach (sitePackImportRecords($connection, $demoSchema) as $record) {
            $linked += isset($record['values']['linked_solution']) ? 1 : 0;
            storageViewExpect(!isset($record['values']['section']), $label . ': an unresolved demo section stayed');
        }
        storageViewExpect($linked > 0, $label . ': the demo lost its own references');

        // ===== The sweep removes import runs and their copies. =====
        storageViewExpect($sitePack->sweep() === 0, $label . ': the sweep removed fresh runs');
    } finally {
        StorageSitePackExporter::wipe($scratch);
        StorageSitePackExporter::wipe($source['directory']);
    }
}

/**
 * Exports from one installation, imports into a fresh one: every id kept, files reused by content, created or remapped,
 * publication kept with a key conflict, the role bound, the scheduled revision pending.
 *
 * @param list<string> $sourceIds
 * @param array<string, string> $ids
 * @param array<string, list<string>> $denied
 */
function sitePackImportFresh(Connection $connection, StorageViewDdl $ddl, string $label, string $archive, array $sourceIds, array $ids, array $denied, string $admin, string $scope): void
{
    sitePackImportInstall($connection);
    $target = sitePackImportRuntime($connection, $ddl, $denied, [$admin]);
    $files = $target['files'];
    // The content of file one is already in the site under another ref; file four's ref is held by another site.
    $files->add('99999999-9999-4999-8999-999999999999', 'local.png', 'PNG-BYTES-SHARED');
    $files->add($ids['file_four'], 'elsewhere.png', 'SOMETHING-ELSE', 'site:other');
    $sitePack = ($target['sitePack'])();
    try {
        $planned = sitePackImportPlan($sitePack, $scope, $archive, $admin, new StorageSitePackImportOptions(null, null, 'keep'));
        $plan = $planned->plan;
        storageViewExpect($plan !== null && $plan->ok(), $label . ': the fresh plan has errors: ' . json_encode($plan?->errors));
        storageViewExpect(sitePackImportEq($plan->records, ['total' => 5, 'keep' => 5, 'new' => 0, 'archived' => 1, 'versions' => 7, 'localized_values' => 5, 'relations' => 2]),
            $label . ': the fresh records are off: ' . json_encode($plan->records));
        storageViewExpect(sitePackImportEq($plan->files, ['total' => 4, 'reuse' => 2, 'create' => 1, 'remap' => 1, 'missing' => 0, 'bytes' => strlen('PNG-BYTES-OTHER') + strlen('PNG-BYTES-FIFTH')]),
            $label . ': the fresh files are off: ' . json_encode($plan->files));
        storageViewExpect(sitePackImportEq($plan->references, ['total' => 2, 'resolved' => 0, 'unresolved' => 2]) && sitePackImportEq($plan->users, ['total' => 2, 'missing' => 1])
            && sitePackImportEq($plan->publication, ['mode' => 'keep', 'published' => 3, 'scheduled' => 1]), $label . ': the fresh plan is off: ' . json_encode($plan->toArray()));
        $warnings = sitePackImportCodes($plan->warnings);
        sort($warnings);
        storageViewExpect($warnings === ['file_missing_cleared', 'reference_cleared', 'relation_dropped', 'user_missing_set_to_importer'],
            $label . ': the fresh warnings are off: ' . json_encode($plan->warnings));
        $done = sitePackImportApply($sitePack, $planned, $admin);
        storageViewExpect($done->state === StorageSitePackRun::STATE_DONE && ($done->counts['files_created'] ?? 0) === 2 && ($done->counts['files_reused'] ?? 0) === 2
            && ($done->counts['publications'] ?? 0) === 2 && ($done->counts['publication_key_conflicts'] ?? 0) === 1 && ($done->counts['schedules'] ?? 0) === 1
            && ($done->counts['relations'] ?? 0) === 1 && ($done->counts['relations_dropped'] ?? 0) === 1, $label . ': the fresh import counts are off: ' . json_encode($done->counts));
        $schemaId = sitePackImportStructures($connection, $scope)['workbench.items'];
        $records = sitePackImportRecords($connection, $schemaId);
        storageViewExpect(array_keys($records) === $sourceIds, $label . ': the fresh import did not keep the ids');
        $one = $records[$ids['r1']]['values'];
        storageViewExpect($one['cover'] === '99999999-9999-4999-8999-999999999999' && !array_key_exists('author', $one) && $one['owner'] === $admin && $one['reviewer'] === $admin,
            $label . ': r1 was not rewritten in the fresh site: ' . json_encode($one));
        $remapped = $records[$ids['r2']]['values']['cover'];
        storageViewExpect($remapped !== $ids['file_four'] && isset($files->files[$remapped]) && $files->files[$remapped]['site'] === 'site:main'
            && $files->files[$remapped]['bytes'] === 'PNG-BYTES-OTHER' && $records[$ids['r4']]['values']['cover'] === $ids['file_five']
            && isset($files->files[$ids['file_five']]), $label . ': the files were not remapped or created: ' . json_encode([$remapped, array_keys($files->files)]));
        $head = static fn (string $recordId, string $locale) => $target['publication']->head($schemaId, $recordId, 'site:main', $locale);
        storageViewExpect($head($ids['r1'], 'en')?->state->value === 'published' && $head($ids['r1'], 'en')?->publishedRevision === 1
            && $head($ids['r1'], 'en')?->scheduledRevision === 2 && $head($ids['r1'], 'ru')?->publishedRevision === 3,
            $label . ': the publication was not kept: ' . json_encode([$head($ids['r1'], 'en'), $head($ids['r1'], 'ru')]));
        storageViewExpect($head($ids['r3'], 'en') === null, $label . ': a conflicting key was published');
        $edge = $connection->table('larena_storage_record_relations')->where('schema_id', $schemaId)->first();
        storageViewExpect($edge !== null && $edge->relation_key === 'section' && $edge->path === $ids['r1'] . '/' . $ids['r3'], $label . ': the tree edge is off');
        storageViewExpect($connection->table('larena_storage_structure_role_bindings')->where('schema_id', $schemaId)->count() === 1, $label . ': the role was not bound in the fresh site');
        storageViewExpect($target['workbench']->readRecord($scope, 'workbench.items', $ids['r1'], $admin)->values['title'] === 'One v3', $label . ': the fresh record does not read');
    } finally {
        StorageSitePackExporter::wipe($target['directory']);
    }
}
