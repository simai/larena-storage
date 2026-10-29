<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Schema;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\Http\Controllers\StorageJobsController;
use Larena\Storage\Admin\Http\Controllers\StorageSectionController;
use Larena\Storage\Admin\StorageBackgroundJobs;
use Larena\Storage\Admin\StorageCsvExportRows;
use Larena\Storage\Admin\StorageCsvImport;
use Larena\Storage\Admin\StorageExportJobHandler;
use Larena\Storage\Admin\StorageImportJobHandler;
use Larena\Storage\Admin\StorageImportPlanJobHandler;
use Larena\Storage\Admin\StorageJobFiles;
use Larena\Storage\Admin\StorageJobLedger;
use Larena\Storage\Admin\StorageSectionGateway;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Admin\Runtime\AdminRecordPanelPresenter;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Filesystem\ValueObjects\PersistentLogicalFileInspection;
use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Runtime\QueueControlService;
use Larena\Queue\Storage\DatabaseQueueStore;
use Larena\Setting\Persistence\DatabaseSettingValueRepository;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchPurgeReceipt;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordPage;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The Storage section's background import and export (storage.import.plan, storage.import.apply, storage.export.csv) on the real
// durable queue and Setting values over SQLite: a large import is queued with a reference to its private file,
// written in chunks that commit with their progress, and an interrupted job (cancelled, timed out, crashed in a
// chunk) resumes to exactly the result of one pass. The export writes the list's CSV to a private file for the
// person who asked; another person, or one without the right, reaches nothing.

final class BackgroundJobsClock implements QueueClock
{
    public function __construct(public DateTimeImmutable $value)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }

    public function advance(int $seconds): void
    {
        $this->value = $this->value->modify('+'.$seconds.' seconds');
    }
}

final class BackgroundJobsAuthorizer implements ActorOperationAuthorizer
{
    /** @param array<string, list<string>> $denied */
    public function __construct(public array $denied = [])
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        if (in_array($operation, $this->denied[$actor] ?? [], true)) {
            throw new AccessMutationRejected('access_denied');
        }
    }
}

final class BackgroundJobsScope implements StorageSectionScope
{
    public function scopeForActor(string $actor): string
    {
        return str_starts_with($actor, 'user:admin_identity:') ? 'scope:alpha' : throw new RuntimeException(StorageSectionScope::SCOPE_DENIED);
    }

    public function fileBelongsToScope(string $logicalRef, string $scopeRef): bool
    {
        return false;
    }
}

final class BackgroundJobsInspector implements PersistentLogicalFileInspector
{
    public function inspect(string $logicalRef): PersistentLogicalFileInspection
    {
        throw new RuntimeException('not_used');
    }
}

/** Storage's workbench over one SQLite table, so a rolled back chunk really writes nothing. */
final class BackgroundJobsWorkbench implements StorageWorkbench
{
    /** @var array<string, StorageWorkbenchStructure> */
    public array $structures = [];
    /** Runs before each record is created, with the number created so far in this process. */
    public ?Closure $beforeCreate = null;
    public int $creates = 0;
    /** Runs before each list page, with the number of pages listed so far. */
    public ?Closure $beforeList = null;
    /** A list Storage refuses (a large storage's list still being built): the import then reads each record by its ID. */
    public bool $listRefused = false;
    private int $reads = 0;
    private int $lists = 0;

    /**
     * How many records were read one by one since the last call.
     *
     * @phpstan-impure
     */
    public function takeReads(): int
    {
        [$reads, $this->reads] = [$this->reads, 0];

        return $reads;
    }

    /** How many list pages were read so far. */
    public function lists(): int
    {
        return $this->lists;
    }

    public function __construct(private Connection $database)
    {
    }

    public function filterOperators(): array
    {
        return [];
    }

    public function createStructure(string $scopeRef, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $id = (string) $descriptor['structure_id'];

        return $this->structures[$id] = new StorageWorkbenchStructure($id, $scopeRef, 1, new StorageSchemaVersionRef($id, 1),
            (string) $descriptor['label'], $descriptor['fields'], hash('sha256', $id), '2026-10-20T00:00:00Z');
    }

    public function updateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function migrateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function readStructure(string $scopeRef, string $structureId, string $actor): StorageWorkbenchStructure
    {
        return $this->structures[$structureId] ?? throw new StorageRejected('storage_structure_missing');
    }

    public function listStructures(string $scopeRef, string $actor, bool $includeArchived = false): array
    {
        return array_values($this->structures);
    }

    public function setHistoryKeep(string $scopeRef, string $structureId, ?int $keep, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function cancelFieldChange(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new RuntimeException('not_used');
    }

    public function archiveStructure(string $scopeRef, string $structureId, int $expectedVersion, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function restoreStructure(string $scopeRef, string $structureId, int $expectedVersion, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function purgeStructure(string $scopeRef, string $structureId, int $expectedVersion, string $actor, ?string $correlationId = null): StorageWorkbenchPurgeReceipt
    {
        throw new StorageRejected('not_used');
    }

    public function createRecord(string $scopeRef, string $structureId, array $values, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        if ($this->beforeCreate !== null) {
            ($this->beforeCreate)($this->creates);
        }
        if (($values['name'] ?? null) === 'reject-me') {
            throw new StorageRejected('storage_value_rejected');
        }
        $this->creates++;
        $id = 'record-'.bin2hex(random_bytes(16));
        $this->database->table('test_records')->insert(['record_id' => $id, 'structure_id' => $structureId, 'revision' => 1,
            'values_json' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

        return $this->readRecord($scopeRef, $structureId, $id, $actor);
    }

    public function updateRecord(string $scopeRef, string $structureId, string $recordId, int $expectedRevision, array $values, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        $updated = $this->database->table('test_records')->where('record_id', $recordId)->where('structure_id', $structureId)
            ->where('revision', $expectedRevision)->update(['revision' => $expectedRevision + 1, 'values_json' => json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        if ($updated !== 1) {
            throw new StorageConflict('storage_record_revision_conflict');
        }

        return $this->readRecord($scopeRef, $structureId, $recordId, $actor);
    }

    public function archiveRecord(string $scopeRef, string $structureId, string $recordId, int $expectedRevision, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        throw new StorageRejected('not_used');
    }

    public function restoreRecord(string $scopeRef, string $structureId, string $recordId, int $expectedRevision, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        throw new StorageRejected('not_used');
    }

    public function bulkArchive(string $scopeRef, string $structureId, array $expectedRevisions, string $actor, ?string $correlationId = null): array
    {
        throw new StorageRejected('not_used');
    }

    public function purgeRecords(string $scopeRef, string $structureId, array $expectedRevisions, string $actor, ?string $correlationId = null): StorageWorkbenchPurgeReceipt
    {
        throw new StorageRejected('not_used');
    }

    public function readRecord(string $scopeRef, string $structureId, string $recordId, string $actor): StorageWorkbenchRecord
    {
        $this->reads++;
        $row = $this->database->table('test_records')->where('record_id', $recordId)->where('structure_id', $structureId)->first();
        if ($row === null) {
            throw new StorageRejected('storage_record_missing');
        }

        return $this->record($row, $scopeRef);
    }

    /** Pages by record id; the continuation is the last id, as a keyset. */
    public function listRecords(StorageWorkbenchRecordQuery $query, string $actor): StorageWorkbenchRecordPage
    {
        if ($this->listRefused) {
            throw new StorageRejected(StorageSectionGateway::VIEW_NOT_READY);
        }
        if ($this->beforeList !== null) {
            ($this->beforeList)($this->lists);
        }
        $this->lists++;
        $rows = $this->database->table('test_records')->where('structure_id', $query->structureId)->orderBy('record_id');
        if ($query->search !== null) {
            $rows->where('values_json', 'like', '%'.$query->search.'%');
        }
        $total = (clone $rows)->count();
        if ($query->continuation !== null) {
            $rows->where('record_id', '>', $query->continuation);
        }
        $page = $rows->limit($query->limit + 1)->get()->all();
        $more = count($page) > $query->limit;
        $page = array_slice($page, 0, $query->limit);
        $items = array_map(fn (object $row): StorageWorkbenchRecord => $this->record($row, $query->scopeRef), $page);

        return new StorageWorkbenchRecordPage($items, $more ? end($items)->recordId : null,
            $query->countMode === StorageWorkbenchCountMode::Skip ? count($items) : $total);
    }

    public function recordHistory(string $scopeRef, string $structureId, string $recordId, string $actor, int $limit = 50): array
    {
        return [$this->readRecord($scopeRef, $structureId, $recordId, $actor)];
    }

    private function record(object $row, string $scopeRef): StorageWorkbenchRecord
    {
        $values = json_decode((string) $row->values_json, true, 32, JSON_THROW_ON_ERROR);

        return new StorageWorkbenchRecord((string) $row->structure_id, $scopeRef, (string) $row->record_id, (int) $row->revision, 1, 'active',
            is_array($values) ? $values : [], hash('sha256', (string) $row->values_json), 'update', '2026-10-20T00:00:00Z');
    }
}

final class BackgroundJobsConfig implements \Illuminate\Contracts\Config\Repository
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items)
    {
    }

    public function has($key)
    {
        return array_key_exists($key, $this->items);
    }

    public function get($key, $default = null)
    {
        return is_string($key) && array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    public function all()
    {
        return $this->items;
    }

    public function set($key, $value = null)
    {
        $this->items[(string) $key] = $value;
    }

    public function prepend($key, $value)
    {
    }

    public function push($key, $value)
    {
    }
}

final class BackgroundJobsTranslator implements \Illuminate\Contracts\Translation\Translator
{
    public function get($key, array $replace = [], $locale = null)
    {
        return $key.($replace === [] ? '' : ' '.json_encode($replace, JSON_UNESCAPED_UNICODE));
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $key;
    }

    public function getLocale()
    {
        return 'ru';
    }

    public function setLocale($locale)
    {
    }
}

final class BackgroundJobsViews implements \Illuminate\Contracts\View\Factory
{
    /** @var array<string, mixed> What the last page was given. */
    public array $data = [];

    public function exists($view)
    {
        return false;
    }

    public function file($path, $data = [], $mergeData = [])
    {
        throw new RuntimeException('not_used');
    }

    public function make($view, $data = [], $mergeData = [])
    {
        $this->data = is_array($data) ? $data : [];

        return new class ((string) $view, $this->data) implements \Illuminate\Contracts\View\View {
            /** @param array<string, mixed> $data */
            public function __construct(private string $view, private array $data)
            {
            }

            public function name()
            {
                return $this->view;
            }

            public function with($key, $value = null)
            {
                return $this;
            }

            public function getData()
            {
                return $this->data;
            }

            public function render()
            {
                return '';
            }
        };
    }

    public function share($key, $value = null)
    {
        return null;
    }

    public function composer($views, $callback)
    {
        return [];
    }

    public function creator($views, $callback)
    {
        return [];
    }

    public function addNamespace($namespace, $hints)
    {
        return $this;
    }

    public function replaceNamespace($namespace, $hints)
    {
        return $this;
    }
}

function background_jobs_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$databasePath = tempnam(sys_get_temp_dir(), 'larena-storage-jobs-');
$filesRoot = sys_get_temp_dir().'/larena-storage-jobs-'.bin2hex(random_bytes(6));
assert(is_string($databasePath));

try {
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $databasePath, 'prefix' => '']);
    $capsule->setAsGlobal();
    $connection = $capsule->getConnection();
    $schema = $connection->getSchemaBuilder();
    $schema->create('larena_setting_values', static function (Blueprint $table): void {
        $table->id();
        $table->string('setting_namespace', 120);
        $table->string('setting_key', 160);
        $table->string('scope', 32);
        $table->string('scope_id', 120);
        $table->json('value');
        $table->unsignedBigInteger('revision')->default(1);
        $table->timestamps();
        $table->unique(['setting_namespace', 'setting_key', 'scope', 'scope_id']);
    });
    $schema->create('test_records', static function (Blueprint $table): void {
        $table->string('record_id', 64)->primary();
        $table->string('structure_id', 120);
        $table->unsignedInteger('revision');
        $table->text('values_json');
    });
    // The queue's own tables, from its migration.
    Schema::swap($schema);
    $queueMigration = require dirname(__DIR__, 2).'/vendor/larena/queue/database/migrations/2026_07_26_000000_create_larena_queue_tables.php';
    $queueMigration->up();
    (require dirname(__DIR__, 2).'/vendor/larena/queue/database/migrations/2026_10_22_000000_add_larena_queue_job_deferrals.php')->up();

    $admin = 'user:admin_identity:1';
    $reader = 'user:admin_identity:2';
    $other = 'user:admin_identity:3';
    $authorizer = new BackgroundJobsAuthorizer([$reader => ['storage.workbench.record.create', 'storage.workbench.record.update']]);
    $scopes = new BackgroundJobsScope();
    $workbench = new BackgroundJobsWorkbench($connection);
    $fields = [
        ['key' => 'name', 'label' => 'Имя', 'type' => 'string', 'required' => true],
        ['key' => 'age', 'label' => 'Возраст', 'type' => 'integer'],
        ['key' => 'level', 'label' => 'Уровень', 'type' => 'choice', 'constraints' => ['options' => [['value' => 'junior', 'label' => 'Младший'], ['value' => 'senior', 'label' => 'Старший']]]],
        ['key' => 'note', 'label' => 'Скрытое', 'type' => 'string', 'hidden' => true],
    ];
    foreach (['workbench.people', 'workbench.reference', 'workbench.plans'] as $id) {
        $workbench->createStructure('scope:alpha', ['structure_id' => $id, 'label' => $id, 'fields' => $fields], $admin);
    }
    $gateway = new StorageSectionGateway($authorizer, $scopes, $workbench, new BackgroundJobsInspector());
    $settings = new DatabaseSettingValueRepository($connection);
    $ledger = new StorageJobLedger($settings);
    $files = new StorageJobFiles($filesRoot);
    $import = new StorageCsvImport($workbench, $scopes, $connection);
    $clock = new BackgroundJobsClock(new DateTimeImmutable('2026-10-20T10:00:00Z'));
    $registry = new JobTypeRegistry();
    $store = new DatabaseQueueStore($connection);
    $control = new QueueControlService($store, $clock);
    StorageBackgroundJobs::register($registry,
        new StorageImportJobHandler($import, $gateway, $scopes, $ledger, $files, $connection),
        new StorageExportJobHandler($gateway, $scopes, $ledger, $files, new StorageCsvExportRows()),
        new StorageImportPlanJobHandler($import, $gateway, $scopes, $ledger, $files));
    $jobs = new StorageBackgroundJobs($ledger, $files, new DurableQueueDispatcher($registry, $store, $clock), $control, $registry);
    $worker = new DurableQueueWorker($registry, $store, $clock);
    background_jobs_assert($jobs->available(), 'The jobs run on the registered queue.');
    background_jobs_assert(!(new StorageBackgroundJobs($ledger, $files))->available(), 'Without the queue there are no background jobs.');

    // Records the file updates by ID, then a file of 1 500 rows: 300 updates and 1 200 additions, one refused by Storage.
    $existing = [];
    foreach (['workbench.people', 'workbench.reference'] as $id) {
        for ($i = 0; $i < 300; $i++) {
            $existing[$id][] = $workbench->createRecord('scope:alpha', $id, ['name' => 'Старый '.$i, 'age' => 20], $admin)->recordId;
        }
    }
    $csvFor = static function (string $structure) use ($existing): string {
        $csv = "ID;Имя;Возраст;Уровень\n";
        foreach ($existing[$structure] as $i => $recordId) {
            $csv .= $recordId.';Старый '.$i.';'.(30 + $i % 7).";Старший\n";
        }
        for ($i = 0; $i < 1200; $i++) {
            $csv .= ';'.($i === 700 ? 'reject-me' : 'Новый '.$i).';'.($i % 90).';'.($i % 2 === 0 ? 'Младший' : 'Старший')."\n";
        }

        return $csv;
    };
    $workbench->creates = 0;

    // ── Enqueue on apply: a file over the inline limit is queued from the import page's «Применить». ──
    $config = new BackgroundJobsConfig([
        'larena-admin.storage_section.uri' => 'admin/storage',
        'larena-admin.storage_section.import_inline_rows' => 1000,
        'larena-auth.admin_entry.access_actor_attribute' => 'larena_access_actor',
    ]);
    $container = new Container();
    $container->instance(StorageJobFiles::class, $files);
    $container->instance(StorageBackgroundJobs::class, $jobs);
    $container->instance(StorageCsvImport::class, $import);
    $session = new Store('test', new ArraySessionHandler(10));
    $redirector = new Redirector(new UrlGenerator(new RouteCollection(), Request::create('http://larena.test/admin')));
    $redirector->setSession($session);
    $translator = new BackgroundJobsTranslator();
    $views = new BackgroundJobsViews();
    $section = new StorageSectionController($config, $views, $redirector, $translator, $gateway,
        new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(), $container);
    $jobsController = new StorageJobsController($config, $views, $redirector, $translator, $gateway, $container);
    $request = static function (string $actor, array $input = []) use ($session): Request {
        $request = Request::create('http://larena.test/admin/storage/workbench.people/import/apply', 'POST', $input);
        $request->attributes->set('larena_access_actor', $actor);
        $request->setLaravelSession($session);

        return $request;
    };

    $csv = $csvFor('workbench.people');
    $plan = $import->plan($admin, 'workbench.people', $csv, $gateway->permissions($admin));
    background_jobs_assert($plan['errors'] === [] && $plan['counts'] === ['create' => 1200, 'update' => 300, 'same' => 0], 'The preview plans every row.');
    background_jobs_assert(StorageCsvImport::rowCount($csv) === 1500, 'The file has 1 500 rows.');
    $token = bin2hex(random_bytes(16));
    background_jobs_assert($files->keepUpload($admin, $token, $csv), 'The upload is kept privately.');
    background_jobs_assert(!is_file($filesRoot.'/uploads/'.$token.'.csv') && $files->upload($reader, $token) === null, 'An upload is found by its person and token only.');
    $response = $section->importApply($request($admin, ['token' => $token, 'digest' => $plan['digest']]), 'workbench.people');
    background_jobs_assert(str_ends_with((string) $response->getTargetUrl(), '/admin/storage/workbench.people/jobs'), 'A queued import leads to the jobs page: '.$response->getTargetUrl().' '.json_encode($session->get('errors')?->all()));
    $queued = $connection->table('larena_queue_jobs')->get()->all();
    background_jobs_assert(count($queued) === 1 && $queued[0]->job_type === 'storage.import.apply' && $queued[0]->status === 'queued', 'Apply enqueues storage.import.apply.');
    $payload = json_decode((string) $queued[0]->payload_json, true, 32, JSON_THROW_ON_ERROR);
    background_jobs_assert(array_keys($payload) === ['actor', 'digest', 'file_ref', 'scope_ref', 'structure_id'], 'The payload names the job, not its data.');
    background_jobs_assert($payload['actor'] === $admin && $payload['scope_ref'] === 'scope:alpha' && $payload['digest'] === $plan['digest'], 'The payload carries the person, scope and plan digest.');
    background_jobs_assert(!str_contains((string) $queued[0]->payload_json, 'Новый') && !str_contains((string) $queued[0]->payload_json, $filesRoot), 'The payload never carries the file or its location.');
    $ref = $payload['file_ref'];
    background_jobs_assert(is_file($files->path($ref, StorageJobFiles::SOURCE)) && $files->upload($admin, $token) === null, 'The upload moved into the job\'s private files.');
    background_jobs_assert(substr(sprintf('%o', fileperms($files->path($ref, StorageJobFiles::SOURCE))), -4) === '0600', 'The job\'s file is private.');
    background_jobs_assert($connection->table('test_records')->where('structure_id', 'workbench.people')->count() === 300, 'Nothing is written before the queue runs.');
    $listed = $jobs->jobs($admin, 'workbench.people');
    background_jobs_assert(count($listed) === 1 && $listed[0]['state'] === 'queued' && $listed[0]['total'] === 1500 && $listed[0]['can_cancel'], 'The jobs panel shows the queued import.');
    background_jobs_assert($jobs->jobs($reader, 'workbench.people') === [], 'Another person does not see the job.');

    // ── Interrupted runs: cancelled after its chunk, crashed inside a chunk, out of time between chunks. ──
    $jobId = (string) $queued[0]->id;
    $workbench->beforeCreate = static function (int $created) use ($control, $jobId): void {
        if ($created === 50) {
            $control->cancel($jobId);
        }
    };
    $first = $worker->runNext('worker-a');
    background_jobs_assert($first?->status === JobStatus::Cancelled, 'A cancel asked mid-run ends the job after its chunk.');
    $progress = $ledger->progress($admin, $ref);
    background_jobs_assert($progress['phase'] === 'applying' && $progress['done'] === 400 && $progress['offset'] > 0, 'The progress stands after the last committed chunk: '.json_encode($progress));
    $written = $connection->table('test_records')->where('structure_id', 'workbench.people')->count();
    background_jobs_assert($written === 300 + 400 - 300, 'Two chunks are written: the 300 updates and 100 additions.');
    $cancelled = $jobs->jobs($admin, 'workbench.people')[0];
    background_jobs_assert($cancelled['state'] === 'cancelled' && $cancelled['can_retry'] && !$cancelled['can_cancel'] && $cancelled['done'] === 400, 'A cancelled job offers «Повторить».');

    // Retried: it crashes inside its third chunk; the chunk is rolled back with its progress.
    background_jobs_assert($jobs->retry($admin, 'workbench.people', $ref), 'The person retries their job.');
    background_jobs_assert(!$jobs->retry($reader, 'workbench.people', $ref) && !$jobs->cancel($other, 'workbench.people', $ref), 'Another person cannot retry or cancel it.');
    $workbench->creates = 0;
    $workbench->beforeCreate = static function (int $created): void {
        if ($created === 250) {
            throw new RuntimeException('the database went away');
        }
    };
    $crashed = $worker->runNext('worker-b');
    background_jobs_assert($crashed?->status === JobStatus::Retrying && $crashed->failureReason === 'handler_exception', 'A crash in a chunk is retried by the queue.');
    $progress = $ledger->progress($admin, $ref);
    background_jobs_assert($progress['done'] === 600 && $connection->table('test_records')->where('structure_id', 'workbench.people')->count() === 300 + 300,
        'The crashed chunk wrote nothing; the chunk before it stays: '.json_encode(['done' => $progress['done']]));

    // Resumed: it runs out of its time after a chunk; the queue runs it again from the saved progress.
    $workbench->creates = 0;
    $workbench->beforeCreate = static function (int $created) use ($clock): void {
        if ($created === 1) {
            $clock->advance(1000);
        }
    };
    $clock->advance(10);
    $timedOut = $worker->runNext('worker-c');
    background_jobs_assert($timedOut?->status === JobStatus::Retrying && $timedOut->failureReason === 'execution_timed_out', 'Out of time between chunks, the job continues later.');
    background_jobs_assert($ledger->progress($admin, $ref)['done'] === 800, 'The chunk before the time ran out is kept.');
    $workbench->beforeCreate = null;
    $clock->advance(10);
    $finished = $worker->runNext('worker-d');
    background_jobs_assert($finished?->status === JobStatus::Completed, 'The resumed job completes.');
    background_jobs_assert($finished->result === ['created' => 1199, 'failed' => 1, 'total' => 1500, 'updated' => 300], 'The result counts the whole import: '.json_encode($finished->result));
    background_jobs_assert(!is_file($files->path($ref, StorageJobFiles::PLAN)) && !is_file($files->path($ref, StorageJobFiles::SOURCE)), 'A finished import keeps no copy of the file.');
    $done = $jobs->jobs($admin, 'workbench.people')[0];
    background_jobs_assert($done['state'] === 'completed' && $done['done'] === 1500 && $done['failed'] === 1 && !$done['can_retry'] && !$done['can_cancel'], 'The panel shows the finished import.');
    background_jobs_assert($done['errors'] === [['line' => 1002, 'column' => '', 'reason' => 'write_refused']], 'The refused row is reported by its line: '.json_encode($done['errors']));

    // The same file in one pass, in another storage: the interrupted import gave exactly the same records.
    $referencePlan = $import->plan($admin, 'workbench.reference', $csvFor('workbench.reference'), $gateway->permissions($admin));
    $referenceToken = bin2hex(random_bytes(16));
    $files->keepUpload($admin, $referenceToken, $csvFor('workbench.reference'));
    $referenceRef = $jobs->startImport($admin, 'scope:alpha', 'workbench.reference', $referenceToken, $referencePlan['digest'], 1500);
    background_jobs_assert(is_string($referenceRef) && $worker->runNext('worker-e')?->status === JobStatus::Completed, 'The one-pass import completes.');
    $contents = static function (string $structure) use ($connection, $existing): array {
        $values = [];
        foreach ($connection->table('test_records')->where('structure_id', $structure)->get() as $row) {
            $decoded = json_decode((string) $row->values_json, true, 32, JSON_THROW_ON_ERROR);
            $index = array_search($row->record_id, $existing[$structure], true);
            $values[] = ($index === false ? 'new' : 'old:'.$index.':r'.$row->revision).':'.json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
        sort($values);

        return $values;
    };
    background_jobs_assert($contents('workbench.people') === $contents('workbench.reference'), 'Interrupt and resume equals one pass.');
    background_jobs_assert(count($contents('workbench.people')) === 1499 && count(array_unique($contents('workbench.people'))) === 1499, 'No row was written twice.');

    // A file that no longer plans as the person saw it is refused whole by the worker.
    $staleToken = bin2hex(random_bytes(16));
    $staleCsv = "ID;Возраст\n".$existing['workbench.people'][0].";99\n";
    $stalePlan = $import->plan($admin, 'workbench.people', $staleCsv, $gateway->permissions($admin));
    $files->keepUpload($admin, $staleToken, $staleCsv);
    $staleRef = $jobs->startImport($admin, 'scope:alpha', 'workbench.people', $staleToken, $stalePlan['digest'], 1);
    $changed = $workbench->readRecord('scope:alpha', 'workbench.people', $existing['workbench.people'][0], $admin);
    $workbench->updateRecord('scope:alpha', 'workbench.people', $changed->recordId, $changed->revision, ['name' => 'Изменён', 'age' => 1], $admin);
    $stale = $worker->runNext('worker-f');
    background_jobs_assert($stale?->status === JobStatus::Failed && $stale->failureReason === 'storage_import_changed_meanwhile', 'A changed plan is refused.');
    background_jobs_assert(is_string($staleRef) && !$jobs->jobs($admin, 'workbench.people')[0]['can_retry'], 'A refused plan offers no retry.');

    // ── Access refusal: a person without the import right gets no job run, and reaches no one else's job. ──
    $readerToken = bin2hex(random_bytes(16));
    $files->keepUpload($reader, $readerToken, $staleCsv);
    $denied = $section->importApply($request($reader, ['token' => $readerToken, 'digest' => $stalePlan['digest']]), 'workbench.people');
    background_jobs_assert($connection->table('larena_queue_jobs')->count() === 3 && str_ends_with((string) $denied->getTargetUrl(), '/import'), 'A reader\'s apply is refused.');
    // A job forged into the queue without its person's ledger entry is refused by the worker.
    (new DurableQueueDispatcher($registry, $store, $clock))->dispatch(new DispatchRequest(StorageImportJobHandler::JOB_TYPE,
        ['structure_id' => 'workbench.people', 'actor' => $admin, 'scope_ref' => 'scope:alpha', 'file_ref' => str_repeat('a', 32), 'digest' => str_repeat('b', 64)],
        'forged-import', 'forged-import'));
    background_jobs_assert($worker->runNext('worker-g')?->failureReason === 'storage_import_job_unknown', 'A forged job is refused.');
    // Rights withdrawn after queueing: the worker refuses before writing.
    $lateToken = bin2hex(random_bytes(16));
    $lateCsv = "Имя\nПоздний\n";
    $latePlan = $import->plan($admin, 'workbench.people', $lateCsv, $gateway->permissions($admin));
    $files->keepUpload($admin, $lateToken, $lateCsv);
    $jobs->startImport($admin, 'scope:alpha', 'workbench.people', $lateToken, $latePlan['digest'], 1);
    $authorizer->denied[$admin] = ['storage.workbench.record.create', 'storage.workbench.record.update'];
    background_jobs_assert($worker->runNext('worker-h')?->failureReason === 'storage_import_denied', 'An import without the right is refused by the worker.');
    background_jobs_assert($connection->table('test_records')->where('values_json', 'like', '%Поздний%')->count() === 0, 'Nothing was written.');
    $authorizer->denied[$admin] = [];
    $readerRequest = $request($reader);
    try {
        $jobsController->retry($readerRequest, 'workbench.people', $ref);
        throw new LogicException('retry reached');
    } catch (NotFoundHttpException) {
    }

    // ── Export: the list's CSV in a private file, downloaded by its person only. ──
    $exportRef = $jobs->startExport($admin, 'scope:alpha', 'workbench.people', StorageCsvExportRows::query('{"search":"","filters":{},"sort":[]}', ['name', 'age', 'level']), 'ru');
    background_jobs_assert(is_string($exportRef), 'The export is queued.');
    $exportRow = $connection->table('larena_queue_jobs')->where('job_type', 'storage.export.csv')->first();
    background_jobs_assert(array_keys(json_decode((string) $exportRow->payload_json, true, 32, JSON_THROW_ON_ERROR)) === ['actor', 'file_ref', 'locale', 'query', 'scope_ref', 'structure_id'], 'The export payload names the job and the list\'s query.');
    // A list nobody sorted is exported in its own order, newest first, as the list shows it.
    $exportSort = json_decode((string) $exportRow->payload_json, true, 32, JSON_THROW_ON_ERROR)['query']['sort'];
    background_jobs_assert(array_map(static fn (array $key): array => [$key['field'], $key['direction']], $exportSort) === [['_created_at', 'desc']],
        'The unsorted export is newest first: '.$exportRow->payload_json);
    // Cancelled while queued: it ends at once; retried, it runs to the end with a heartbeat per page.
    background_jobs_assert($jobs->cancel($admin, 'workbench.people', $exportRef), 'A queued export is cancelled.');
    background_jobs_assert($jobs->jobs($admin, 'workbench.people')[0]['state'] === 'cancelled', 'The panel shows it cancelled.');
    background_jobs_assert($jobs->retry($admin, 'workbench.people', $exportRef), 'It is retried.');
    $exported = $worker->runNext('worker-i');
    background_jobs_assert($exported?->status === JobStatus::Completed && $exported->result === ['rows' => 1499], 'The export writes every record: '.json_encode($exported?->result));
    $csvOut = (string) file_get_contents($files->path($exportRef, StorageJobFiles::EXPORT));
    background_jobs_assert(str_starts_with($csvOut, "\u{FEFF}ID,Имя,Возраст,Уровень\n"), 'ID first, then the shown fields by their labels; hidden fields are left out.');
    $lines = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_values(array_filter(explode("\n", trim(substr($csvOut, 3))))));
    background_jobs_assert(count($lines) === 1500 && count(array_unique(array_column(array_slice($lines, 1), 0))) === 1499, 'Every record once.');
    background_jobs_assert(in_array(['ID', 'Имя', 'Возраст', 'Уровень'], [$lines[0]], true), 'The header.');
    $novel = array_values(array_filter($lines, static fn (array $line): bool => $line[1] === 'Новый 1'))[0] ?? [];
    background_jobs_assert(preg_match('/^record-[a-f0-9]{32}$/', $novel[0] ?? '') === 1 && $novel[2] === '1' && $novel[3] === 'Старший', 'A choice is written by its label: '.json_encode($novel));
    $listedExport = $jobs->jobs($admin, 'workbench.people')[0];
    background_jobs_assert($listedExport['kind'] === 'export' && $listedExport['can_download'] && $listedExport['done'] === 1499 && $listedExport['total'] === 1499, 'The panel offers the download.');
    // A formula is written as text, and a resumed export cuts what a crashed run wrote after its saved length.
    $workbench->createRecord('scope:alpha', 'workbench.reference', ['name' => '=SUM(1,1)'], $admin);
    $formulaRef = $jobs->startExport($admin, 'scope:alpha', 'workbench.reference', StorageCsvExportRows::query(['search' => 'SUM'], ['name']), 'ru');
    background_jobs_assert(is_string($formulaRef) && $worker->runNext('worker-j')?->status === JobStatus::Completed, 'The searched export completes.');
    $formulaCsv = (string) file_get_contents($files->path($formulaRef, StorageJobFiles::EXPORT));
    background_jobs_assert(substr_count($formulaCsv, "\n") === 2 && str_contains($formulaCsv, "'=SUM(1,1)"), 'The search narrows the export and a formula stays text.');

    // Download: the person who asked gets the file; anyone else, and an import, get nothing.
    $download = $jobsController->download($request($admin), 'workbench.people', $exportRef);
    background_jobs_assert($download->getFile()->getPathname() === $files->path($exportRef, StorageJobFiles::EXPORT)
        && $download->headers->get('Content-Type') === 'text/csv; charset=UTF-8' && str_contains((string) $download->headers->get('Content-Disposition'), 'workbench.people-'), 'The owner downloads the export.');
    foreach ([[$reader, $exportRef], [$other, $exportRef], [$admin, $ref], [$admin, str_repeat('0', 32)]] as [$who, $what]) {
        try {
            $jobsController->download($request($who), 'workbench.people', $what);
            throw new LogicException('download reached');
        } catch (NotFoundHttpException) {
        }
    }
    background_jobs_assert($jobs->download($admin, 'workbench.reference', $exportRef) === null, 'A job is found in its own storage only.');
    // Without the right to read the list the export is refused by the worker.
    $deniedExport = $jobs->startExport($other, 'scope:alpha', 'workbench.people', StorageCsvExportRows::query('', []), 'ru');
    $authorizer->denied[$other] = ['admin.frontend.read'];
    background_jobs_assert(is_string($deniedExport) && $worker->runNext('worker-k')?->failureReason === 'storage_export_denied', 'An export without the read right is refused.');
    $authorizer->denied[$other] = [];

    // ── A large storage whose list is still being built in the background (Storage refuses its list meanwhile). ──
    $workbench->listRefused = true;
    // The export waits: the queue defers it a minute at a time without spending its attempts (a list may take
    // longer to build than all the attempts' retry delays), and it then writes every record.
    $waitingRef = $jobs->startExport($admin, 'scope:alpha', 'workbench.people', StorageCsvExportRows::query('', ['name']), 'ru');
    $waitStarted = $clock->now();
    $waiting = $worker->runNext('worker-wait-a');
    background_jobs_assert(is_string($waitingRef) && $waiting?->status === JobStatus::Deferred && $waiting->failureReason === StorageExportJobHandler::VIEW_NOT_READY
        && $waiting->attempts === 0 && $waiting->deferrals === 1
        && $waiting->availableAt->getTimestamp() === $waitStarted->getTimestamp() + StorageExportJobHandler::WAIT_SECONDS,
        'An export of a list not built yet waits a minute without spending an attempt: '.json_encode([$waiting?->status, $waiting?->failureReason, $waiting?->attempts]));
    $clock->advance(30);
    background_jobs_assert($worker->runNext('worker-wait-a') === null, 'Not before its minute.');
    $clock->advance(30);
    $again = $worker->runNext('worker-wait-a');
    background_jobs_assert($again?->status === JobStatus::Deferred && $again->attempts === 0 && $again->deferrals === 2, 'It keeps waiting while the list is built.');
    $waitingJob = $jobs->job($admin, 'workbench.people', $waitingRef);
    background_jobs_assert($waitingJob['state'] === 'waiting' && $waitingJob['reason'] === null && $waitingJob['can_cancel'] && !$waitingJob['can_retry'],
        'The panel shows it waiting, cancellable: '.json_encode($waitingJob));
    $waitingItem = (new \Larena\Storage\Admin\Runtime\StorageJobsPanel($translator))->items([$waitingJob], '/admin/storage/workbench.people/jobs', 'token', true)[0];
    background_jobs_assert($waitingItem['state'] === 'larena-storage::admin.section.jobs.state.waiting' && $waitingItem['tone'] === 'info'
        && str_contains((string) (new \Larena\Admin\Runtime\AdminElementDocumentRenderer())->tree(...$waitingItem['actions']), '/cancel'), 'The panel says it waits for the list: '.json_encode($waitingItem));
    foreach (['ru' => 'Ждём, пока подготовится список — продолжим автоматически', 'en' => 'Waiting for the list to be prepared — will continue automatically'] as $locale => $text) {
        $copy = storage_admin_words($locale);
        background_jobs_assert(($copy['larena_storage']['section']['jobs']['state']['waiting'] ?? null) === $text
            && is_string($copy['larena_storage']['section']['jobs']['reasons']['job_deferral_limit_reached'] ?? null), 'The waiting copy in '.$locale);
    }
    // The records page says the list is being prepared, and keeps the storage, its settings and the record panel.
    $listRequest = Request::create('http://larena.test/admin/storage/workbench.people', 'GET');
    $listRequest->attributes->set('larena_access_actor', $admin);
    $listRequest->setLaravelSession($session);
    $listPage = $section->records($listRequest, 'workbench.people');
    background_jobs_assert($listPage instanceof \Illuminate\Contracts\View\View && $views->data['status'] === 'ok' && $views->data['dataview'] === null
        && ($views->data['listNotice']['reason'] ?? null) === StorageSectionGateway::VIEW_NOT_READY
        && $views->data['listNotice']['text'] === 'larena-storage::admin.section.list_not_ready_text'
        && ($views->data['selected']['structure_id'] ?? null) === 'workbench.people', 'The records page says the list is being prepared: '.json_encode($views->data['listNotice'] ?? null));
    // The settings page draws its structure forms with Framework controls in the controller (developer remark 3', stage 3).
    \Larena\Ui\Facades\Smart::resolveUsing(static fn (): \Larena\Ui\Runtime\SmartManager => \Larena\Ui\Runtime\SmartManager::withDefaults());
    $section->settings($listRequest, 'workbench.people');
    background_jobs_assert($views->data['status'] === 'ok', 'The settings open without the list.');
    // The jobs page answers «not found» for a storage it cannot read; it opens without the list.
    background_jobs_assert($jobsController->index($listRequest, 'workbench.people') instanceof \Illuminate\Contracts\View\View, 'The jobs page opens without the list.');
    // An import needs the person's rights, not the list: its check still runs.
    background_jobs_assert(is_array((new \Larena\Storage\Admin\StorageImportRights($gateway, $scopes))->of($admin, 'scope:alpha', 'workbench.people')),
        'The import rights are read without the list.');
    $workbench->listRefused = false;
    $clock->advance(StorageExportJobHandler::WAIT_SECONDS);
    $built = $worker->runNext('worker-wait-b');
    background_jobs_assert($built?->status === JobStatus::Completed && $built->attempts === 1, 'Once the list is built the export completes on its first attempt.');
    background_jobs_assert($jobs->job($admin, 'workbench.people', $waitingRef)['can_download'], 'And offers its file.');

    // A relative date range is not exported differently from the list: it is refused.
    try {
        StorageCsvExportRows::query('{"filters":{"age":{"operator":"today"}}}', ['age']);
        throw new LogicException('relative filter accepted');
    } catch (InvalidArgumentException) {
    }
    background_jobs_assert(StorageCsvExportRows::query('{"filters":{"level":["senior"]},"sort":[{"key":"age","direction":"desc"}]}', ['age', 'level'])
        === ['search' => '', 'filters' => ['level' => ['operator' => 'in', 'values' => ['senior']]], 'sort' => [['field' => 'age', 'direction' => 'desc']]], 'The list\'s query in Storage\'s shape.');
    // The creation time is a sort the export takes like the list does; with no sort it is the list's own, newest first.
    background_jobs_assert(StorageCsvExportRows::query('{"sort":[{"key":"_created_at","direction":"asc"}]}', ['age'])['sort'] === [['field' => '_created_at', 'direction' => 'asc']]
        && StorageCsvExportRows::query('', ['age'])['sort'] === \Larena\Storage\Admin\StorageListOrder::NEWEST_FIRST, 'The export\'s order.');

    // A small file still writes at once, whole, and leaves no job.
    $smallToken = bin2hex(random_bytes(16));
    $smallCsv = "Имя;Возраст\nСразу;5\n";
    $smallPlan = $import->plan($admin, 'workbench.people', $smallCsv, $gateway->permissions($admin));
    $files->keepUpload($admin, $smallToken, $smallCsv);
    $before = $connection->table('larena_queue_jobs')->count();
    $inline = $section->importApply($request($admin, ['token' => $smallToken, 'digest' => $smallPlan['digest']]), 'workbench.people');
    background_jobs_assert(str_ends_with((string) $inline->getTargetUrl(), '/admin/storage/workbench.people') && $connection->table('larena_queue_jobs')->count() === $before
        && $connection->table('test_records')->where('values_json', 'like', '%Сразу%')->count() === 1, 'A small file is written at once: '.$inline->getTargetUrl().json_encode($session->get('errors')?->all()));
    background_jobs_assert($files->upload($admin, $smallToken) === null, 'Its upload is spent.');

    // ── The preview of a large file: read in windows with the records of each window read together, and checked by
    // the queue (storage.import.plan) with the same plan the request would show. ──
    // A third person who may import too, with jobs of their own.
    $authorizer->denied[$other] = [];
    $plans = [];
    for ($i = 0; $i < 300; $i++) {
        $plans[] = $workbench->createRecord('scope:alpha', 'workbench.plans', ['name' => 'Старый '.$i, 'age' => 20], $admin)->recordId;
    }
    // 2 500 rows over three windows: every eighth row updates a record (every fiftieth leaves it as it is), the rest add.
    $planCsv = static function (bool $withErrors) use ($plans): string {
        $csv = "ID;Имя;Возраст;Уровень\n";
        for ($i = 0; $i < 2500; $i++) {
            $index = intdiv($i, 8);
            if ($i % 8 === 0 && $index < 300) {
                $csv .= $index % 50 === 0 ? $plans[$index].';Старый '.$index.";20;\n" : $plans[$index].';Старый '.$index.';'.(30 + $index % 7).";Старший\n";
            } elseif ($withErrors && $i % 10 === 3) {
                $csv .= ";Плохой $i;возраст;Младший\n";
            } else {
                $csv .= ";Новый $i;".($i % 90).";Младший\n";
            }
        }
        if ($withErrors) {
            // A record named twice (the second time in the last window) and one that does not exist.
            $csv .= $plans[1].";Снова;1;\nrecord-".str_repeat('0', 32).";Нет;1;\n";
        }

        return $csv;
    };
    $permissions = $gateway->permissions($admin);
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $workbench->takeReads();
    $batched = $import->plan($admin, 'workbench.plans', $planCsv(true), $permissions);
    $queries = count($connection->getQueryLog());
    $connection->disableQueryLog();
    background_jobs_assert($batched['counts'] === ['create' => 1950, 'update' => 294, 'same' => 6] && count($batched['errors']) === 252, 'The plan reads the whole file: '.json_encode($batched['counts']).count($batched['errors']));
    $reads = $workbench->takeReads();
    background_jobs_assert($reads === 0 && $queries < 60, 'The records of 300 rows are read in pages, not one by one: '.$queries.' queries, '.$reads.' reads.');
    $errorReasons = array_count_values(array_column($batched['errors'], 'reason'));
    background_jobs_assert($errorReasons === ['value_not_integer' => 250, 'id_repeated' => 1, 'record_unknown' => 1], 'A repeated ID is found across windows: '.json_encode($errorReasons));
    // The same plan, digest included, as reading each record by its ID.
    $workbench->listRefused = true;
    $oneByOne = $import->plan($admin, 'workbench.plans', $planCsv(true), $permissions);
    $workbench->listRefused = false;
    background_jobs_assert($workbench->takeReads() === 301 && $oneByOne === $batched, 'Read one by one, the plan is the same.');
    // A file naming few records of a large storage reads just those.
    $few = $import->plan($admin, 'workbench.plans', "ID;Возраст\n".$plans[7].";77\n", $permissions);
    background_jobs_assert($workbench->takeReads() === 1 && $few['counts'] === ['create' => 0, 'update' => 1, 'same' => 0], 'A few records are read by their IDs.');

    // Upload: a file over the inline limit is checked by the queue; the import page follows the check.
    $upload = static function (string $actor, string $csv) use ($session): Request {
        $path = tempnam(sys_get_temp_dir(), 'larena-import-upload-');
        file_put_contents((string) $path, $csv);
        $request = Request::create('http://larena.test/admin/storage/workbench.plans/import', 'POST', [], [], [
            'file' => new \Illuminate\Http\UploadedFile((string) $path, 'plans.csv', 'text/csv', null, true),
        ]);
        $request->attributes->set('larena_access_actor', $actor);
        $request->setLaravelSession($session);

        return $request;
    };
    $page = static function (string $actor, ?string $job) use ($session, $section, $views): array {
        $request = Request::create('http://larena.test/admin/storage/workbench.plans/import', 'GET', $job === null ? [] : ['job' => $job]);
        $request->attributes->set('larena_access_actor', $actor);
        $request->setLaravelSession($session);
        $section->import($request, 'workbench.plans');

        return $views->data;
    };
    $planQueue = static fn () => $connection->table('larena_queue_jobs')->where('job_type', StorageImportPlanJobHandler::JOB_TYPE)->orderBy('created_at')->get()->all();
    try {
        $section->importPreview($upload($reader, $planCsv(false)), 'workbench.plans');
        throw new LogicException('a reader uploaded');
    } catch (NotFoundHttpException) {
    }
    background_jobs_assert($planQueue() === [], 'A person without the import right queues no check.');
    $checked = $section->importPreview($upload($admin, $planCsv(false)), 'workbench.plans');
    $planRef = $checked instanceof \Illuminate\Http\RedirectResponse && preg_match('#/admin/storage/workbench\.plans/import\?job=([a-f0-9]{32})$#', $checked->getTargetUrl(), $found) === 1
        ? $found[1] : '';
    background_jobs_assert($planRef !== '', 'A large upload leads to its check on the import page.');
    $planJobs = $planQueue();
    background_jobs_assert(count($planJobs) === 1 && $planJobs[0]->status === 'queued', 'The upload enqueues storage.import.plan.');
    $planPayload = json_decode((string) $planJobs[0]->payload_json, true, 32, JSON_THROW_ON_ERROR);
    background_jobs_assert(array_keys($planPayload) === ['actor', 'file_ref', 'scope_ref', 'structure_id'] && $planPayload['file_ref'] === $planRef
        && !str_contains((string) $planJobs[0]->payload_json, 'Новый') && !str_contains((string) $planJobs[0]->payload_json, $filesRoot), 'The check\'s payload names the job, not its file.');
    $waiting = $page($admin, $planRef);
    background_jobs_assert($waiting['plan'] === null && $waiting['apply'] === null && is_array($waiting['checking'])
        && $waiting['checking']['ref'] === $planRef && str_contains($waiting['checking']['state'], 'state.queued')
        && str_contains($waiting['checking']['progress'], '"total":"2'), 'The import page says the file waits for its check: '.json_encode($waiting['checking']));
    background_jobs_assert($page($other, $planRef)['checking'] === null, 'Another person\'s check is not shown.');

    // Interrupted: out of time after its first window, then cancelled after its second; each run goes on from the saved window.
    $planJobId = (string) $planJobs[0]->id;
    $listedBefore = $workbench->lists();
    $workbench->beforeList = static function (int $listed) use ($clock, $listedBefore): void {
        if ($listed === $listedBefore) {
            $clock->advance(1000);
        }
    };
    $workbench->takeReads();
    $timedOutPlan = $worker->runNext('worker-plan-a');
    background_jobs_assert($timedOutPlan?->status === JobStatus::Retrying && $timedOutPlan->failureReason === 'execution_timed_out', 'Out of time after a window, the check continues later.');
    $planProgress = $ledger->progress($admin, $planRef);
    background_jobs_assert($planProgress['phase'] === 'checking' && $planProgress['done'] === StorageCsvImport::PLAN_WINDOW && $planProgress['total'] === 2500
        && $planProgress['resume']['line'] === StorageCsvImport::PLAN_WINDOW + 1, 'The check stands after its first window: '.json_encode(array_diff_key($planProgress, ['resume' => 1])));
    $running = $page($admin, $planRef)['checking'];
    background_jobs_assert(str_contains($running['progress'], 'progress_checked') && str_contains($running['progress'], '"done":"1'), 'The page shows how far the check went: '.$running['progress']);
    $listedFirst = $workbench->lists();
    $workbench->beforeList = static function (int $listed) use ($control, $planJobId, $listedFirst): void {
        if ($listed === $listedFirst) {
            $control->cancel($planJobId);
        }
    };
    $clock->advance(10);
    $cancelledPlan = $worker->runNext('worker-plan-b');
    background_jobs_assert($cancelledPlan?->status === JobStatus::Cancelled && $ledger->progress($admin, $planRef)['done'] === 2 * StorageCsvImport::PLAN_WINDOW, 'A cancel stops the check after its window.');
    $cancelledItem = $jobs->job($admin, 'workbench.plans', $planRef);
    background_jobs_assert($cancelledItem !== null && $cancelledItem['stage'] === 'check' && $cancelledItem['state'] === 'cancelled' && $cancelledItem['can_retry'] && !$cancelledItem['can_apply'], 'A cancelled check offers «Повторить».');
    background_jobs_assert($jobs->retry($admin, 'workbench.plans', $planRef), 'The check is retried.');
    $workbench->beforeList = null;
    $finishedPlan = $worker->runNext('worker-plan-c');
    background_jobs_assert($finishedPlan?->status === JobStatus::Completed && $finishedPlan->result === ['create' => 2200, 'errors' => 0, 'same' => 6, 'update' => 294],
        'The check completes: '.json_encode($finishedPlan?->result));
    background_jobs_assert($workbench->takeReads() === 0, 'The check read no record one by one.');
    // The queued plan is the plan the request shows for the same file.
    $inlineClean = $import->plan($admin, 'workbench.plans', $planCsv(false), $permissions);
    $checkedItem = $jobs->job($admin, 'workbench.plans', $planRef);
    background_jobs_assert($checkedItem !== null && $checkedItem['plan'] === ['columns' => $inlineClean['columns'], 'counts' => $inlineClean['counts'],
        'errors' => [], 'error_count' => 0, 'digest' => $inlineClean['digest']], 'Checked by the queue after two interruptions, the plan equals the inline plan.');
    background_jobs_assert($checkedItem['state'] === 'completed' && $checkedItem['can_apply'] && !$checkedItem['can_retry'], 'A clean checked plan may be applied.');
    $shown = $page($admin, $planRef);
    background_jobs_assert($shown['checking'] === null && $shown['plan']['counts'] === $inlineClean['counts'] && $shown['plan']['error_count'] === 0
        && $shown['apply']['action'] === '/admin/storage/workbench.plans/jobs/'.$planRef.'/apply' && $shown['apply']['fields'] === ['digest' => $inlineClean['digest']]
        && $shown['queued'] === true && str_contains((string) $shown['content'], 'import.queued_note'), 'The import page shows the plan with «Применить».');
    $panelItem = array_values(array_filter($shown['jobs'], static fn (array $item): bool => $item['ref'] === $planRef))[0];
    $panelActions = (string) (new \Larena\Admin\Runtime\AdminElementDocumentRenderer())->tree(...$panelItem['actions']);
    background_jobs_assert(str_contains($panelItem['state'], 'state.checked') && str_contains($panelActions, '/jobs/'.$planRef.'/apply')
        && str_contains($panelActions, 'import?job='.$planRef) && str_contains((string) $panelItem['summary'], 'plan_summary'), 'The jobs panel offers «Применить» and the plan.');

    // A file with errors: the queue keeps the first errors with the number of all, and offers nothing to apply.
    $badChecked = $section->importPreview($upload($admin, $planCsv(true)), 'workbench.plans');
    preg_match('#job=([a-f0-9]{32})$#', $badChecked->getTargetUrl(), $badFound);
    background_jobs_assert($worker->runNext('worker-plan-d')?->status === JobStatus::Completed, 'The check of a file with errors completes.');
    $badItem = $jobs->job($admin, 'workbench.plans', $badFound[1]);
    background_jobs_assert($badItem !== null && $badItem['plan'] !== null && $badItem['plan']['errors'] === array_slice($batched['errors'], 0, StorageCsvImport::MAX_KEPT_ERRORS)
        && $badItem['plan']['error_count'] === 252 && $badItem['plan']['counts'] === $batched['counts'] && $badItem['plan']['digest'] === $batched['digest'] && !$badItem['can_apply'],
        'The first 200 errors and the count of all, as the inline plan has them.');
    $badPage = $page($admin, $badFound[1]);
    background_jobs_assert($badPage['apply'] === null && $badPage['plan']['error_count'] === 252 && $badPage['plan']['more_errors'] === 52, 'The page lists 200 errors and counts the rest.');
    $refusedApply = $jobsController->apply($request($admin, ['digest' => $batched['digest']]), 'workbench.plans', $badFound[1]);
    background_jobs_assert(str_contains($refusedApply->getTargetUrl(), '/import?job='.$badFound[1]), 'A plan with errors is not applied.');

    // Apply: bound to the digest the person saw, by the person who checked the file, once.
    $applyQueue = static fn () => $connection->table('larena_queue_jobs')->where('job_type', StorageImportJobHandler::JOB_TYPE)->count();
    $appliesBefore = $applyQueue();
    $wrongDigest = $jobsController->apply($request($admin, ['digest' => str_repeat('c', 64)]), 'workbench.plans', $planRef);
    background_jobs_assert(str_contains($wrongDigest->getTargetUrl(), '/import?job=') && $applyQueue() === $appliesBefore, 'Another digest applies nothing.');
    foreach ([$reader, $other] as $stranger) {
        try {
            $jobsController->apply($request($stranger, ['digest' => $inlineClean['digest']]), 'workbench.plans', $planRef);
            throw new LogicException('a foreign check was applied');
        } catch (NotFoundHttpException) {
        }
    }
    background_jobs_assert(!$jobs->applyPlan($other, 'scope:alpha', 'workbench.plans', $planRef, $inlineClean['digest']) && $applyQueue() === $appliesBefore, 'Another person\'s check is refused.');
    $applied = $jobsController->apply($request($admin, ['digest' => $inlineClean['digest']]), 'workbench.plans', $planRef);
    background_jobs_assert(str_ends_with($applied->getTargetUrl(), '/admin/storage/workbench.plans/jobs') && $applyQueue() === $appliesBefore + 1, '«Применить» enqueues storage.import.apply.');
    $applyRow = $connection->table('larena_queue_jobs')->where('job_type', StorageImportJobHandler::JOB_TYPE)->orderBy('created_at', 'desc')->first();
    $applyPayload = json_decode((string) $applyRow->payload_json, true, 32, JSON_THROW_ON_ERROR);
    background_jobs_assert($applyPayload['file_ref'] === $planRef && $applyPayload['digest'] === $inlineClean['digest'], 'The write goes on in the same job, bound to the plan\'s digest.');
    background_jobs_assert(!$jobs->applyPlan($admin, 'scope:alpha', 'workbench.plans', $planRef, $inlineClean['digest']), 'A plan is applied once.');
    $appliedItem = $jobs->job($admin, 'workbench.plans', $planRef);
    background_jobs_assert($appliedItem !== null && $appliedItem['stage'] === 'apply' && $appliedItem['state'] === 'queued' && $appliedItem['plan'] === null, 'The job now writes.');
    $written = $worker->runNext('worker-plan-e');
    background_jobs_assert($written?->status === JobStatus::Completed && $written->result === ['created' => 2200, 'failed' => 0, 'total' => 2494, 'updated' => 294],
        'The checked plan is written: '.json_encode($written?->result));
    background_jobs_assert($connection->table('test_records')->where('structure_id', 'workbench.plans')->count() === 2500
        && !is_file($files->path($planRef, StorageJobFiles::SOURCE)), 'Every row is written and the file is gone.');

    // Foreign and refused checks: a check forged into the queue, an apply job's reference, rights withdrawn.
    (new DurableQueueDispatcher($registry, $store, $clock))->dispatch(new DispatchRequest(StorageImportPlanJobHandler::JOB_TYPE,
        ['structure_id' => 'workbench.plans', 'actor' => $admin, 'scope_ref' => 'scope:alpha', 'file_ref' => str_repeat('d', 32)], 'forged-plan', 'forged-plan'));
    background_jobs_assert($worker->runNext('worker-plan-f')?->failureReason === 'storage_import_job_unknown', 'A forged check is refused.');
    (new DurableQueueDispatcher($registry, $store, $clock))->dispatch(new DispatchRequest(StorageImportPlanJobHandler::JOB_TYPE,
        ['structure_id' => 'workbench.plans', 'actor' => $admin, 'scope_ref' => 'scope:alpha', 'file_ref' => $planRef], 'replayed-plan', 'replayed-plan'));
    background_jobs_assert($worker->runNext('worker-plan-g')?->failureReason === 'storage_import_job_unknown', 'A check of a job that writes is refused.');
    $lateCheck = $section->importPreview($upload($admin, $planCsv(false)), 'workbench.plans');
    preg_match('#job=([a-f0-9]{32})$#', $lateCheck->getTargetUrl(), $lateFound);
    $authorizer->denied[$admin] = ['storage.workbench.record.create', 'storage.workbench.record.update'];
    background_jobs_assert($worker->runNext('worker-plan-h')?->failureReason === 'storage_import_denied', 'A check without the import right is refused by the worker.');
    $authorizer->denied[$admin] = [];
    background_jobs_assert($jobs->job($admin, 'workbench.plans', $lateFound[1])['state'] === 'failed' && $page($admin, $lateFound[1])['checking']['reason'] !== null, 'The page says why the check failed.');

    $queueMigration->down();
    echo "StorageBackgroundJobsTest passed.\n";
} finally {
    @unlink($databasePath);
    if (is_dir($filesRoot)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($filesRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($filesRoot);
    }
}
