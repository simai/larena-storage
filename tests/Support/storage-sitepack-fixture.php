<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Core\SitePack\SitePackArchiveReader;
use Larena\Core\SitePack\SitePackEnvelope;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Storage\DatabaseQueueStore;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\SitePack\DatabaseStorageSitePack;
use Larena\Storage\SitePack\StorageSitePackFile;
use Larena\Storage\SitePack\StorageSitePackFiles;
use Larena\Storage\SitePack\StorageSitePackFormat;
use Larena\Storage\SitePack\StorageSitePackJob;
use Larena\Storage\View\StorageViewDdl;

require_once __DIR__ . '/storage-view-fixture.php';
require_once __DIR__ . '/storage-view-queue-fixture.php';

/**
 * The storage SitePack export scenario, on any connection (SQLite in StorageSitePackExportTest, a throwaway MySQL
 * database in StorageSitePackExportMySqlTest).
 */

/** Workbench rights for $actors; the export operation only for $exporters. */
final readonly class SitePackFixtureAccess implements ActorOperationAuthorizer, QueryScopeProvider
{
    /** @param list<string> $actors @param list<string> $exporters */
    public function __construct(private array $actors, private array $exporters, private string $scopeRef)
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        $allowed = $operation === DatabaseStorageSitePack::OPERATION ? $this->exporters : $this->actors;
        if (!in_array($actor, $allowed, true)) {
            throw new RuntimeException('sitepack_fixture_denied');
        }
    }

    public function supports(string $resourceType, string $operation): bool
    {
        return true;
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        return ['scope_ref' => $this->scopeRef];
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        return in_array($actor, $this->actors, true)
            ? AccessDecision::allow($operation, $actor, $resourceType . ':' . $this->scopeRef, 'sitepack_fixture_allowed')
            : AccessDecision::deny($operation, $actor, $resourceType . ':denied', 'sitepack_fixture_denied');
    }
}

/** Files by logical ref, in memory. */
final class SitePackFakeFiles implements StorageSitePackFiles
{
    /** @var array<string, array{descriptor: array<string, mixed>, bytes: string}> */
    public array $files = [];

    /** @var list<string> */
    public array $asked = [];

    public function add(string $logicalRef, string $name, string $bytes): void
    {
        $this->files[$logicalRef] = ['descriptor' => [
            'logical_ref' => $logicalRef,
            'public_id' => substr($logicalRef, 0, 35) . 'f',
            'display_name' => ucfirst(pathinfo($name, PATHINFO_FILENAME)),
            'original_name' => $name,
            'mime_type' => 'image/png',
            'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'visibility' => 'public',
            'alt_text' => null,
        ], 'bytes' => $bytes];
    }

    public function available(): bool
    {
        return true;
    }

    public function snapshot(string $siteRef, string $logicalRef): ?StorageSitePackFile
    {
        if ($siteRef !== 'site:main') {
            return null;
        }
        $this->asked[] = $logicalRef;
        $file = $this->files[$logicalRef] ?? null;
        if ($file === null) {
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
}

final class SitePackCapturedEvents implements StorageSecurityEventSink
{
    /** @var list<StorageSecurityEvent> */
    public array $events = [];

    public function emit(StorageSecurityEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<StorageSecurityEvent> */
    public function of(string $type): array
    {
        return array_values(array_filter($this->events, static fn (StorageSecurityEvent $event): bool => $event->type === $type));
    }
}

final class SitePackFixtureClock
{
    public function __construct(public DateTimeImmutable $now)
    {
    }

    public function closure(): Closure
    {
        return fn (): DateTimeImmutable => $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->add(new DateInterval('PT' . $seconds . 'S'));
    }
}

function sitePackRejects(callable $call, string $reason, string $message): void
{
    try {
        $call();
    } catch (StorageRejected $exception) {
        storageViewExpect($exception->reasonCode === $reason, $message . ': expected ' . $reason . ', got ' . $exception->reasonCode);

        return;
    }
    throw new RuntimeException($message . ': expected the rejection ' . $reason);
}

/** Every artifact's bytes of an archive, by path, read through core's reader (digests checked). @return array<string, string> */
function sitePackArtifacts(string $archive): array
{
    $reader = SitePackArchiveReader::open($archive);
    try {
        $bytes = [];
        foreach ($reader->envelope->entries as $entry) {
            $bytes[$entry->path] = implode('', iterator_to_array($reader->stream($entry->path), false));
        }

        return $bytes;
    } finally {
        $reader->close();
    }
}

/** @return list<array<string, mixed>> */
function sitePackLines(string $archive, string $path): array
{
    $reader = SitePackArchiveReader::open($archive);
    try {
        return iterator_to_array($reader->lines($path), false);
    } finally {
        $reader->close();
    }
}

/** @return array<string, string> artifact path => digest */
function sitePackCatalog(string $archive): array
{
    $reader = SitePackArchiveReader::open($archive);
    try {
        $digests = [];
        foreach ($reader->envelope->entries as $entry) {
            $digests[$entry->path] = $entry->digest;
        }

        return $digests;
    } finally {
        $reader->close();
    }
}

function sitePackInstall(Connection $connection): void
{
    storageViewQueueInstall($connection);
    $root = __DIR__ . '/../../database/migrations';
    foreach ([
        '/platform/2026_09_26_000001_create_larena_storage_structure_role_tables.php',
        '/platform/2026_09_27_000001_create_larena_storage_record_relations_table.php',
        '/platform/2026_09_28_000001_create_larena_storage_localized_values_table.php',
        '/platform/2026_09_29_000001_create_larena_storage_publication_tables.php',
        '/platform/2026_10_22_000002_add_larena_storage_publication_scheduled_revision.php',
        '/platform/2026_10_22_100001_create_larena_storage_sitepack_tables.php',
    ] as $file) {
        (require $root . $file)->up();
    }
}

/**
 * Exports a storage with archived records, two locales, published and scheduled revisions, relations, files and
 * references to another storage, and checks the archive, resume, cancel, retry, the queue job, refusals, access and
 * the sweep.
 */
function sitePackScenario(Connection $connection, StorageViewDdl $ddl, string $label): void
{
    $actor = 'actor:admin:alpha';
    $editor = 'actor:editor:beta';
    $scope = 'scope:tenant-alpha';
    $site = 'site:main';
    sitePackInstall($connection);
    $fieldChanges = [];
    // Above two records a field change runs in the background; the closure only notes it (no worker runs it).
    $runtime = storageViewRuntime($connection, $ddl, $actor, $scope, null, null, static function (string $changeRef) use (&$fieldChanges): bool {
        $fieldChanges[] = $changeRef;

        return true;
    }, 2);
    $workbench = $runtime['workbench'];
    $storage = $runtime['storage'];
    $localized = new DatabaseLocalizedValues($connection);
    $publication = new DatabasePublicationLifecycle($connection, static fn (string $schemaId, string $recordId, int $revision): bool => $connection
        ->table('larena_storage_record_versions')->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->exists());
    $directory = sys_get_temp_dir() . '/larena-storage-sitepack-' . bin2hex(random_bytes(6));
    $access = new SitePackFixtureAccess([$actor, $editor], [$actor], $scope);
    $events = new SitePackCapturedEvents();
    $clock = new SitePackFixtureClock(new DateTimeImmutable('2026-10-22T09:00:00Z'));
    $files = new SitePackFakeFiles();
    $make = static fn (?StorageSitePackFiles $port = null, ?Closure $jobs = null, int $chunk = 2): DatabaseStorageSitePack => new DatabaseStorageSitePack(
        $connection, $workbench, $storage, PropertyTypeRegistry::builtIns(), $access, $access, $directory,
        $port ?? $files, null, $events, $jobs, static fn (string $who): string => $site, $clock->closure(), $chunk, 1,
    );
    $sitePack = $make();

    try {
        // --- Two storages: authors (no files) and items, which references authors. ---
        $authors = $workbench->createStructure($scope, ['structure_id' => 'workbench.authors', 'label' => 'Authors', 'fields' => [
            storageViewField('name', 10, 'string', ['max_length' => 200], true),
        ]], $actor);
        $authorOne = $workbench->createRecord($scope, 'workbench.authors', ['name' => 'Ada'], $actor);
        $authorTwo = $workbench->createRecord($scope, 'workbench.authors', ['name' => 'Grace'], $actor);
        $itemFields = [
            array_merge(storageViewField('title', 10, 'string', ['max_length' => 200], true), ['indexed' => true]),
            array_merge(storageViewField('headline', 20, 'string', ['max_length' => 200]), ['localized' => true, 'searchable' => true]),
            array_merge(storageViewField('body', 30, 'text'), ['localized' => true, 'hidden' => true]),
            storageViewField('cover', 40, 'file'),
            storageViewField('author', 50, 'record', ['target_structure_id' => 'workbench.authors']),
            storageViewField('parent', 60, 'record', ['target_structure_id' => 'workbench.items']),
            storageViewField('qty', 70, 'integer'),
        ];
        $items = $workbench->createStructure($scope, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $itemFields], $actor);
        $items = $workbench->setHistoryKeep($scope, 'workbench.items', 10, $actor);
        $schemaId = $items->schema->schemaId;
        $fileOne = '11111111-1111-4111-8111-111111111111';
        $fileTwo = '22222222-2222-4222-8222-222222222222';
        $fileMissing = '33333333-3333-4333-8333-333333333333';
        $fileFour = '44444444-4444-4444-8444-444444444444';
        $files->add($fileOne, 'cover.png', 'PNG-BYTES-SHARED');
        $files->add($fileTwo, 'copy.png', 'PNG-BYTES-SHARED');
        $files->add($fileFour, 'other.png', 'PNG-BYTES-OTHER');

        // r1: three revisions; en publishes 1 and schedules 2, ru publishes 3; translations in two locales.
        $r1 = $workbench->createRecord($scope, 'workbench.items', ['title' => 'One', 'cover' => $fileOne, 'author' => $authorOne->recordId, 'qty' => 1], $actor);
        $localized->write($schemaId, $r1->recordId, 1, 'en', ['headline' => 'Hello one', 'body' => 'Body one'], ['headline', 'body'], $actor);
        $localized->write($schemaId, $r1->recordId, 1, 'ru', ['headline' => 'Привет один'], ['headline', 'body'], $actor);
        $publication->publish($schemaId, $r1->recordId, $site, 'en', 1, $actor);
        $r1 = $workbench->updateRecord($scope, 'workbench.items', $r1->recordId, 1, ['title' => 'One v2', 'cover' => $fileOne, 'author' => $authorOne->recordId, 'qty' => 2], $actor);
        $publication->schedule($schemaId, $r1->recordId, $site, 'en', 2, '2030-01-01 00:00:00', $actor);
        $r1 = $workbench->updateRecord($scope, 'workbench.items', $r1->recordId, 2, ['title' => 'One v3', 'cover' => $fileOne, 'author' => $authorOne->recordId, 'qty' => 3], $actor);
        $localized->write($schemaId, $r1->recordId, 3, 'en', ['headline' => 'Hello three'], ['headline', 'body'], $actor);
        $localized->write($schemaId, $r1->recordId, 3, 'ru', ['headline' => 'Привет три'], ['headline', 'body'], $actor);
        $publication->publish($schemaId, $r1->recordId, $site, 'ru', 3, $actor);
        // A publication in another site is not this export's.
        $publication->publish($schemaId, $r1->recordId, 'site:other', 'en', 3, $actor);
        // r2: archived, with another file.
        $r2 = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Two', 'cover' => $fileFour], $actor);
        $r2 = $workbench->archiveRecord($scope, 'workbench.items', $r2->recordId, 1, $actor);
        // r3: a parent in the same storage, relations inside and out.
        $r3 = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Three', 'parent' => $r1->recordId, 'author' => $authorOne->recordId], $actor);
        foreach ([
            ['section', 'tree', $r1->recordId, 1, 0],
            ['see_also', 'reference', $authorTwo->recordId, 0, 5],
        ] as [$key, $kind, $to, $depth, $order]) {
            $connection->table('larena_storage_record_relations')->insert([
                'relation_id' => $key . ':' . $r3->recordId . ':' . $to, 'relation_key' => $key, 'schema_id' => $schemaId,
                'from_record_id' => $r3->recordId, 'to_record_id' => $to, 'kind' => $kind, 'tree_child_key' => null,
                'path' => null, 'depth' => $depth, 'order_index' => $order, 'delete_policy' => 'restrict', 'status' => 'active',
                'created_by' => $actor, 'correlation_id' => 'corr-relation', 'created_at' => '2026-10-22 08:00:00', 'updated_at' => '2026-10-22 08:00:00',
            ]);
        }
        $connection->table('larena_storage_record_relations')->insert([
            'relation_id' => 'gone:' . $r3->recordId, 'relation_key' => 'gone', 'schema_id' => $schemaId,
            'from_record_id' => $r3->recordId, 'to_record_id' => $r2->recordId, 'kind' => 'reference', 'tree_child_key' => null,
            'path' => null, 'depth' => 0, 'order_index' => 0, 'delete_policy' => 'restrict', 'status' => 'deleted',
            'created_by' => $actor, 'correlation_id' => null, 'created_at' => '2026-10-22 08:00:00', 'updated_at' => '2026-10-22 08:00:00',
        ]);
        // r4: three revisions, only the current one is live: the older two never leave the site.
        $r4 = $workbench->createRecord($scope, 'workbench.items', ['title' => 'R4 first draft SECRET-OLD'], $actor);
        $r4 = $workbench->updateRecord($scope, 'workbench.items', $r4->recordId, 1, ['title' => 'R4 second draft SECRET-OLD'], $actor);
        $r4 = $workbench->updateRecord($scope, 'workbench.items', $r4->recordId, 2, ['title' => 'Four', 'cover' => $fileTwo], $actor);
        // r5: a file that is gone.
        $r5 = $workbench->createRecord($scope, 'workbench.items', ['title' => 'Five', 'cover' => $fileMissing], $actor);
        $connection->table('larena_storage_structure_role_bindings')->insert([
            'binding_id' => 'site_node@v1|' . $schemaId . '|' . $site, 'role_ref' => 'site_node@v1', 'schema_id' => $schemaId, 'scope_ref' => $site,
            'status' => 'active', 'conformance_checked_at' => null, 'bound_by' => $actor, 'correlation_id' => null,
            'created_at' => '2026-10-22 08:00:00', 'updated_at' => '2026-10-22 08:00:00',
        ]);
        $ids = [$r1->recordId, $r2->recordId, $r3->recordId, $r4->recordId, $r5->recordId];
        sort($ids);

        // --- E1: the export, in this process, two records a chunk. ---
        $started = $sitePack->startExport($scope, 'workbench.items', $actor, 'corr-export-one', false);
        storageViewExpect($started->state === StorageSitePackRun::STATE_QUEUED && $started->siteRef === $site && $started->jobId === null, $label . ': the export did not start queued');
        sitePackRejects(static fn () => $sitePack->archive($started->runRef, $actor), 'storage_sitepack_run_not_done', $label . ': an unfinished run gave an archive');
        $one = $sitePack->run($started->runRef, $actor);
        storageViewExpect($one->state === StorageSitePackRun::STATE_DONE && $one->done === 5 && $one->total === 5, $label . ': the export did not finish: ' . json_encode($one->toArray()));
        storageViewExpect($one->counts['records'] === 5 && $one->counts['archived_records'] === 1 && $one->counts['versions'] === 7
            && $one->counts['files'] === 3 && $one->counts['files_missing'] === 1 && $one->counts['blobs'] === 2 && $one->counts['references'] === 2
            && $one->counts['relations'] === 2 && $one->counts['publications'] === 2, $label . ': the counts are off: ' . json_encode($one->counts));
        $archive = $sitePack->archive($one->runRef, $actor, 'corr-download');
        storageViewExpect($archive->sha256 === hash_file('sha256', $archive->path) && $archive->sizeBytes === filesize($archive->path)
            && $archive->packageId === $one->packageId && str_starts_with($archive->downloadName, 'workbench.items-'), $label . ': the archive file does not match the run');
        storageViewExpect((fileperms($archive->path) & 0o777) === 0o600 && (fileperms($directory) & 0o777) === 0o700, $label . ': the archive is not private');
        storageViewExpect(!is_dir($directory . '/' . $one->runRef . '.staging'), $label . ': the staging stayed after the seal');

        // The archive reads back with core's reader; every digest checks.
        $reader = SitePackArchiveReader::open($archive->path);
        $envelope = $reader->envelope;
        $reader->close();
        storageViewExpect($envelope->annotationsAre(StorageSitePackFormat::SCHEMA_NAME, StorageSitePackFormat::SCHEMA_VERSION), $label . ': the schema stamp is missing');
        storageViewExpect(($envelope->annotations['adapter'] ?? null) === 'larena/storage'
            && ($envelope->annotations['includes'] ?? null) === ['history' => false, 'archived_records' => true]
            && ($envelope->annotations['source'] ?? null) === ['site_ref' => $site, 'structure_ids' => ['workbench.items'], 'exported_at' => '2026-10-22T09:00:00Z'],
            $label . ': the annotations are off: ' . json_encode($envelope->annotations));
        storageViewExpect(str_starts_with($envelope->packageId, 'larena-storage-') && $envelope->profiles === ['storage', 'storage+assets'], $label . ': the package id or profiles are off');
        $artifacts = sitePackArtifacts($archive->path);
        $paths = array_keys($artifacts);
        sort($paths);
        storageViewExpect($paths === [
            'artifacts/assets/blobs/sha256/' . hash('sha256', 'PNG-BYTES-OTHER'),
            'artifacts/assets/blobs/sha256/' . hash('sha256', 'PNG-BYTES-SHARED'),
            'artifacts/assets/index.jsonl',
            'artifacts/storage/records/workbench.items/part-000001.jsonl',
            'artifacts/storage/references.jsonl',
            'artifacts/storage/structures.jsonl',
        ], $label . ': the artifacts are off: ' . json_encode($paths));
        $everything = implode("\n", $artifacts);
        foreach (['actor:', 'corr-', 'larena_scope_ref', 'larena_record_state', 'created_by', 'correlation', 'SECRET-OLD', 'site:other', $scope] as $never) {
            storageViewExpect(!str_contains($everything, $never), $label . ': the pack carries ' . $never);
        }

        $structures = sitePackLines($archive->path, StorageSitePackFormat::STRUCTURES);
        storageViewExpect(count($structures) === 1, $label . ': one structure line expected');
        $line = $structures[0];
        storageViewExpect($line['id'] === 'workbench.items' && $line['label'] === 'Items' && $line['history_keep'] === 10
            && count($line['fields']) === 7 && $line['role_bindings'] === [['role_ref' => 'site_node@v1']]
            && $line['source'] === ['structure_version' => $items->version, 'schema_version' => $items->schema->version], $label . ': the structure line is off: ' . json_encode($line));
        $byKey = array_column($line['fields'], null, 'key');
        storageViewExpect($byKey['headline']['localized'] === true && $byKey['headline']['searchable'] === true && $byKey['body']['hidden'] === true
            && $byKey['title']['indexed'] === true && $byKey['author']['constraints'] === ['target_structure_id' => 'workbench.authors']
            && $byKey['title']['required'] === true && $byKey['cover']['type'] === 'file' && $byKey['cover']['type_version'] === 1
            && $byKey['qty']['position'] === 70 && $byKey['title']['visibility'] === 'admin', $label . ': a field lost its options: ' . json_encode($byKey));
        $schemaKeys = array_column($line['schema']['fields'], 'key');
        storageViewExpect(!in_array('larena_scope_ref', $schemaKeys, true) && in_array('headline', $schemaKeys, true) && $line['schema']['owner_package'] === 'larena/storage',
            $label . ': the schema definition is off');

        $records = sitePackLines($archive->path, StorageSitePackFormat::recordsPart('workbench.items', 1));
        storageViewExpect(array_column($records, 'id') === $ids, $label . ': the records are not in id order');
        $byId = array_column($records, null, 'id');
        $first = $byId[$r1->recordId];
        storageViewExpect($first['state'] === 'active' && array_column($first['versions'], 'revision') === [1, 2, 3], $label . ': r1 lost a live version: ' . json_encode(array_column($first['versions'], 'revision')));
        [$v1, $v2, $v3] = $first['versions'];
        storageViewExpect($v1['published_in'] === ['en'] && $v2['published_in'] === [] && $v3['published_in'] === ['ru'] && $v3['current'] === true && $v1['current'] === false,
            $label . ': published_in is off');
        storageViewExpect($v1['values'] === ['author' => $authorOne->recordId, 'cover' => $fileOne, 'qty' => 1, 'title' => 'One'] && $v1['schema_version'] === 1, $label . ': the values of revision 1 are off: ' . json_encode($v1['values']));
        storageViewExpect($v1['localized'] === ['en' => ['body' => 'Body one', 'headline' => 'Hello one'], 'ru' => ['headline' => 'Привет один']]
            && $v2['localized'] === [] && $v3['localized'] === ['en' => ['headline' => 'Hello three'], 'ru' => ['headline' => 'Привет три']], $label . ': the translations are off: ' . json_encode([$v1['localized'], $v3['localized']]));
        foreach ($first['versions'] as $version) {
            $expected = 'sha256:' . hash('sha256', SitePackEnvelope::canonicalJson([
                'localized' => $version['localized'] === [] ? new stdClass() : $version['localized'],
                'values' => $version['values'] === [] ? new stdClass() : $version['values'],
            ]));
            storageViewExpect($version['content_hash'] === $expected, $label . ': a content hash does not match its version');
        }
        storageViewExpect($first['publication'] === [
            ['locale' => 'en', 'state' => 'published', 'revision' => 1, 'published_at' => $first['publication'][0]['published_at'], 'scheduled_revision' => 2, 'scheduled_at' => '2030-01-01T00:00:00Z'],
            ['locale' => 'ru', 'state' => 'published', 'revision' => 3, 'published_at' => $first['publication'][1]['published_at'], 'scheduled_revision' => null, 'scheduled_at' => null],
        ] && preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', (string) $first['publication'][0]['published_at']) === 1, $label . ': the publication states are off: ' . json_encode($first['publication']));
        storageViewExpect($byId[$r2->recordId]['state'] === 'archived' && array_column($byId[$r2->recordId]['versions'], 'revision') === [2], $label . ': the archived record is off');
        storageViewExpect($byId[$r3->recordId]['relations'] === [
            ['relation_key' => 'section', 'kind' => 'tree', 'to' => $r1->recordId, 'order_index' => 0, 'depth' => 1],
            ['relation_key' => 'see_also', 'kind' => 'reference', 'to' => $authorTwo->recordId, 'order_index' => 5, 'depth' => 0],
        ], $label . ': the relations are off: ' . json_encode($byId[$r3->recordId]['relations']));
        storageViewExpect(array_column($byId[$r4->recordId]['versions'], 'revision') === [3] && $byId[$r4->recordId]['versions'][0]['values'] === ['cover' => $fileTwo, 'title' => 'Four'],
            $label . ': history was not excluded');
        $expectedReferences = [
            ['id' => $authorOne->recordId, 'structure_id' => 'workbench.authors'],
            ['id' => $authorTwo->recordId, 'structure_id' => 'workbench.authors'],
        ];
        usort($expectedReferences, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        storageViewExpect(sitePackLines($archive->path, StorageSitePackFormat::REFERENCES) === $expectedReferences, $label . ': the references are off');
        $assets = sitePackLines($archive->path, StorageSitePackFormat::ASSET_INDEX);
        storageViewExpect(array_column($assets, 'key') === ['asset:' . $fileOne, 'asset:' . $fileTwo, 'asset:' . $fileFour], $label . ': the asset index is off: ' . json_encode(array_column($assets, 'key')));
        storageViewExpect($assets[0]['blob'] === $assets[1]['blob'] && $assets[0]['sha256'] === hash('sha256', 'PNG-BYTES-SHARED')
            && $assets[0]['original_name'] === 'cover.png' && array_key_exists('alt_text', $assets[0]) && !array_key_exists('storage_key', $assets[0]),
            $label . ': an asset line is off');
        storageViewExpect($artifacts[$assets[2]['blob']] === 'PNG-BYTES-OTHER', $label . ': a blob is not the file content');

        // Audit: exported and downloaded, with the digest and counts only.
        $exported = $events->of('storage.sitepack.exported');
        $downloaded = $events->of('storage.sitepack.downloaded');
        storageViewExpect(count($exported) === 1 && $exported[0]->stream === 'sitepack' && $exported[0]->payload['archive_sha256'] === $archive->sha256
            && $exported[0]->payload['records'] === 5 && $exported[0]->correlationId === 'corr-export-one', $label . ': the export was not audited');
        storageViewExpect(count($downloaded) === 1 && $downloaded[0]->correlationId === 'corr-download' && $downloaded[0]->payload['package_id'] === $archive->packageId, $label . ': the download was not audited');
        foreach ($events->events as $event) {
            storageViewExpect(array_intersect(array_keys($event->payload), ['values', 'fields', 'value', 'content', 'payload']) === [], $label . ': an audit payload carries values');
        }

        // --- E2: the same data gives the same content (only the manifest's time may differ). ---
        $clock->advance(60);
        $two = $sitePack->run($sitePack->startExport($scope, 'workbench.items', $actor, null, false)->runRef, $actor);
        storageViewExpect($two->packageId === $one->packageId && sitePackCatalog($sitePack->archivePath($two->runRef)) === sitePackCatalog($archive->path), $label . ': two exports of the same data differ');

        // --- E3: interrupted after two chunks, a chunk written but not saved, then resumed. ---
        $ticks = 0;
        $three = $make();
        $run = $three->startExport($scope, 'workbench.items', $actor, null, false);
        try {
            $three->process($run->runRef, static function () use (&$ticks): void {
                if (++$ticks === 3) {
                    throw new RuntimeException('worker stopped');
                }
            });
            throw new LogicException('the interruption did not stop the export');
        } catch (RuntimeException $exception) {
            storageViewExpect($exception->getMessage() === 'worker stopped', $label . ': the interruption was swallowed');
        }
        $stopped = $three->read($run->runRef, $actor);
        storageViewExpect($stopped->state === StorageSitePackRun::STATE_FAILED && $stopped->reason === 'storage_sitepack_interrupted' && $stopped->done === 4 && $stopped->phase === 'records',
            $label . ': the interrupted run is off: ' . json_encode($stopped->toArray()));
        $part = $directory . '/' . $run->runRef . '.staging/records/part-000001.jsonl';
        file_put_contents($part, "{\"junk\":true}\n", FILE_APPEND);
        file_put_contents($directory . '/' . $run->runRef . '.staging/records/part-000002.jsonl', "{\"junk\":true}\n");
        $resumed = $three->process($run->runRef);
        storageViewExpect($resumed->state === StorageSitePackRun::STATE_DONE && $resumed->done === 5, $label . ': the resumed export did not finish');
        storageViewExpect(sitePackCatalog($three->archivePath($run->runRef)) === sitePackCatalog($archive->path), $label . ': the resumed export differs from a whole one');

        // --- E4: cancelled while it runs, then retried. ---
        $run = $sitePack->startExport($scope, 'workbench.items', $actor, null, false);
        $ticks = 0;
        sitePackRejects(static function () use ($sitePack, $run, $actor, &$ticks): void {
            $sitePack->process($run->runRef, static function () use ($sitePack, $run, $actor, &$ticks): void {
                if (++$ticks === 2) {
                    $sitePack->cancel($run->runRef, $actor);
                }
            });
        }, 'storage_sitepack_interrupted', $label . ': the cancelled export went on');
        $cancelled = $sitePack->read($run->runRef, $actor);
        storageViewExpect($cancelled->state === StorageSitePackRun::STATE_CANCELLED && !is_dir($directory . '/' . $run->runRef . '.staging')
            && !is_file($sitePack->archivePath($run->runRef)) && $connection->table('larena_storage_sitepack_ids')->where('run_ref', $run->runRef)->count() === 0,
            $label . ': the cancel left something behind');
        sitePackRejects(static fn () => $sitePack->cancel($run->runRef, $actor), 'storage_sitepack_run_finished', $label . ': a cancelled run was cancelled again');
        $retried = $sitePack->retry($run->runRef, $actor, null, false);
        storageViewExpect($retried->state === StorageSitePackRun::STATE_QUEUED && $retried->phase === 'prepare' && $retried->done === 0, $label . ': the retry did not start over');
        storageViewExpect($sitePack->run($run->runRef, $actor)->packageId === $one->packageId, $label . ': the retried export differs');
        sitePackRejects(static fn () => $sitePack->retry($run->runRef, $actor), 'storage_sitepack_run_not_retryable', $label . ': a finished run was retried');

        // --- E5: through the queue: the storage.sitepack.export job runs it. ---
        $queueClock = new StorageViewTestClock(new DateTimeImmutable('2026-10-22T09:00:00Z'));
        $registry = new JobTypeRegistry();
        $store = new DatabaseQueueStore($connection);
        $dispatcher = new DurableQueueDispatcher($registry, $store, $queueClock);
        $queued = $make(null, static fn (string $runRef, int $generation): string => $dispatcher->dispatch(StorageSitePackJob::request($runRef, $generation))->jobId, 3);
        $registry->register(StorageSitePackJob::descriptor(), new StorageSitePackJob($queued));
        $run = $queued->startExport($scope, 'workbench.items', $actor);
        storageViewExpect($run->jobId !== null, $label . ': the export was not queued');
        $job = (new DurableQueueWorker($registry, $store, $queueClock))->runNext('sitepack-worker');
        storageViewExpect($job !== null && $job->status === JobStatus::Completed, $label . ': the job did not complete: ' . ($job?->status->value ?? 'none'));
        storageViewExpect($queued->read($run->runRef, $actor)->packageId === $one->packageId, $label . ': the queued export differs');

        // --- Refusals and access. ---
        sitePackRejects(static fn () => $sitePack->startExport($scope, 'workbench.items', $editor), 'storage_sitepack_export_denied', $label . ': an editor without the export right started one');
        sitePackRejects(static fn () => $sitePack->startExport($scope, 'workbench.items', 'actor:stranger'), 'storage_sitepack_export_denied', $label . ': a stranger started an export');
        sitePackRejects(static fn () => $sitePack->read($one->runRef, $editor), 'storage_sitepack_run_unknown', $label . ': another person read the run');
        sitePackRejects(static fn () => $sitePack->archive($one->runRef, $editor), 'storage_sitepack_run_unknown', $label . ': another person downloaded the archive');
        sitePackRejects(static fn () => $sitePack->startExport($scope, 'workbench.nothing', $actor), 'storage_workbench_structure_unknown', $label . ': an unknown structure was exported');
        storageViewExpect(count($sitePack->recent($actor)) === 5 && $sitePack->recent($actor)[0]->createdAt >= $one->createdAt, $label . ': the recent runs are off');

        // No file owner composed: a storage with file values fails for good, one without exports.
        $bare = $make(new Larena\Storage\SitePack\UnavailableStorageSitePackFiles());
        $run = $bare->startExport($scope, 'workbench.items', $actor, null, false);
        sitePackRejects(static fn () => $bare->run($run->runRef, $actor), 'storage_sitepack_files_unavailable', $label . ': files were exported without a file owner');
        storageViewExpect($bare->read($run->runRef, $actor)->state === StorageSitePackRun::STATE_FAILED && $bare->read($run->runRef, $actor)->finishedAt !== null, $label . ': the run without files did not fail for good');
        sitePackRejects(static fn () => $bare->process($run->runRef), 'storage_sitepack_files_unavailable', $label . ': a failed run ran again');
        $authorsRun = $bare->run($bare->startExport($scope, 'workbench.authors', $actor, null, false)->runRef, $actor);
        storageViewExpect($authorsRun->state === StorageSitePackRun::STATE_DONE && $authorsRun->counts['files'] === 0, $label . ': a storage without files did not export');
        $reader = SitePackArchiveReader::open($bare->archivePath($authorsRun->runRef));
        storageViewExpect($reader->envelope->profiles === ['storage'] && $reader->envelope->entry(StorageSitePackFormat::ASSET_INDEX)?->lines === 0, $label . ': the pack without files is off');
        $reader->close();

        // A field change running: refused.
        $busy = $workbench->createStructure($scope, ['structure_id' => 'workbench.busy', 'label' => 'Busy', 'fields' => [storageViewField('qty', 10, 'integer')]], $actor);
        foreach ([1, 2, 3] as $qty) {
            $workbench->createRecord($scope, 'workbench.busy', ['qty' => $qty], $actor);
        }
        $changing = $workbench->migrateStructure($scope, 'workbench.busy', $busy->version, ['structure_id' => 'workbench.busy', 'label' => 'Busy', 'fields' => [storageViewField('qty', 10, 'number')]], $actor);
        storageViewExpect($changing->changing() && $fieldChanges !== [], $label . ': the field change did not go to the background');
        sitePackRejects(static fn () => $sitePack->startExport($scope, 'workbench.busy', $actor), 'storage_sitepack_field_change_running', $label . ': an export started during a field change');

        // The structure changes mid-export: the run fails retryably and starts over on the new structure.
        $run = $sitePack->startExport($scope, 'workbench.items', $actor, null, false);
        $ticks = 0;
        $withExtra = array_merge($itemFields, [storageViewField('extra', 80, 'string')]);
        sitePackRejects(static function () use ($sitePack, $workbench, $run, $scope, $actor, $withExtra, &$ticks): void {
            $sitePack->process($run->runRef, static function () use ($workbench, $scope, $actor, $withExtra, &$ticks): void {
                if (++$ticks === 2) {
                    $current = $workbench->readStructure($scope, 'workbench.items', $actor);
                    $workbench->updateStructure($scope, 'workbench.items', $current->version, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => $withExtra], $actor);
                }
            });
        }, 'storage_sitepack_structure_changed', $label . ': the export went on over a changed structure');
        $changed = $sitePack->read($run->runRef, $actor);
        storageViewExpect($changed->state === StorageSitePackRun::STATE_FAILED && $changed->reason === 'storage_sitepack_structure_changed' && $changed->phase === 'prepare'
            && $changed->finishedAt === null && in_array($changed->reason, DatabaseStorageSitePack::RETRYABLE, true), $label . ': the changed structure did not fail retryably');
        $again = $sitePack->process($run->runRef);
        $fieldsAfter = array_column(sitePackLines($sitePack->archivePath($run->runRef), StorageSitePackFormat::STRUCTURES)[0]['fields'], 'key');
        storageViewExpect($again->state === StorageSitePackRun::STATE_DONE && in_array('extra', $fieldsAfter, true), $label . ': the retry did not export the new structure');

        // --- The command: --sync runs here and copies the archive to a new file. ---
        $container = new class extends Illuminate\Container\Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $container->instance(Larena\Storage\Contracts\StorageSitePack::class, $sitePack);
        $command = static function (array $input) use ($container): array {
            $command = new Larena\Storage\Console\Commands\StorageSitePackExportCommand();
            $command->setLaravel($container);
            $output = new Symfony\Component\Console\Output\BufferedOutput();
            $arguments = new Symfony\Component\Console\Input\ArrayInput($input);
            $arguments->setInteractive(false);
            $status = $command->run($arguments, $output);

            return [$status, trim($output->fetch())];
        };
        $target = $directory . '-authors.sitepack.zip';
        [$status, $printed] = $command(['structure' => 'workbench.authors', '--scope' => $scope, '--actor' => $actor, '--sync' => true, '--output' => $target]);
        $result = json_decode($printed, true);
        storageViewExpect($status === 0 && is_array($result) && $result['state'] === 'done' && is_file($target)
            && hash_file('sha256', $target) === $result['archive_sha256'] && (fileperms($target) & 0o777) === 0o600, $label . ': the command did not export: ' . $printed);
        @unlink($target);
        [$status] = $command(['structure' => 'workbench.authors', '--scope' => $scope]);
        storageViewExpect($status === 2, $label . ': the command ran without an actor');
        [$status] = $command(['structure' => 'workbench.authors', '--scope' => $scope, '--actor' => $actor, '--output' => $target]);
        storageViewExpect($status === 2, $label . ': --output went without --sync');

        // --- The sweep removes runs older than seven days with their archives. ---
        $paths = glob($directory . '/*.zip') ?: [];
        storageViewExpect(count($paths) >= 5, $label . ': the archives are missing before the sweep');
        storageViewExpect($sitePack->sweep() === 0, $label . ': the sweep removed fresh runs');
        $clock->advance(8 * 86400);
        $all = $connection->table('larena_storage_sitepack_runs')->count();
        storageViewExpect($sitePack->sweep() === $all && $connection->table('larena_storage_sitepack_runs')->count() === 0
            && (glob($directory . '/*') ?: []) === [], $label . ': the sweep left runs or files behind');
    } finally {
        Larena\Storage\SitePack\StorageSitePackExporter::wipe($directory);
    }
}
