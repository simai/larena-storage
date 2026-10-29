<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\Http\Controllers\StorageSectionController;
use Larena\Storage\Admin\Http\Controllers\StorageSitePackController;
use Larena\Storage\Admin\StorageEncryptionKeyStatus;
use Larena\Storage\Admin\StorageEncryptionRunState;
use Larena\Storage\Admin\StorageFieldChangeState;
use Larena\Storage\Admin\StorageFieldPropertyViewPort;
use Larena\Storage\Admin\StorageSectionGateway;
use Larena\Storage\Admin\StorageSitePackExports;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Admin\Runtime\AdminDataviewPagePresenter;
use Larena\Admin\Runtime\AdminRecordPanelPresenter;
use Larena\Storage\Admin\Runtime\StorageEncryptionPanel;
use Larena\Storage\Admin\Runtime\StorageSitePackPanel;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Filesystem\ValueObjects\PersistentLogicalFileInspection;
use Larena\Setting\Persistence\DatabaseSettingValueRepository;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\Contracts\StorageSitePackArchiveFile;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchEncryption;
use Larena\Storage\Contracts\StorageWorkbenchEncryptionRun;
use Larena\Storage\Contracts\StorageWorkbenchPurgeReceipt;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordPage;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Exceptions\StorageRejected;

// Encryption runs in the Storage section (larena-specs docs/architecture/2026-10-22-storage-field-encryption.md,
// «Turning encryption on and off», «Keys»): «Шифруем поле «X»: обработано n из N» (and «Расшифровываем…», «Меняем
// ключ шифрования…») with its progress while a run is queued or running, the structure locked with the reason; a
// stopped run's reason in plain words and «Повторить» for a person who manages encryption; switching encryption on an
// existing field waits for «Подтверждаю включение шифрования» (what changes: search, sorting, filters, old backups),
// off for «Подтверждаю расшифровку» (sent as encryption_disable_confirmed); «Шифрование» on the settings page with the
// key's state and «Сменить ключ шифрования»; the SitePack export's «Включить зашифрованные поля в открытом виде» for
// a person with decrypt and export only; every refusal in ru and en.

/** The admin's own words, read from the package's language file, with :replacements. */
final class EncryptionRunTexts implements \Illuminate\Contracts\Translation\Translator
{
    /** @var array<string, mixed> */
    private array $admin;

    public function __construct(private string $locale = 'ru')
    {
        $this->admin = storage_admin_words($locale);
    }

    public function get($key, array $replace = [], $locale = null)
    {
        $asked = $key;
        $key = storage_admin_key((string) $key);
        if (!str_starts_with((string) $key, 'larena-admin::admin.')) {
            return $asked;
        }
        $value = $this->admin;
        foreach (explode('.', substr((string) $key, strlen('larena-admin::admin.'))) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $asked;
            }
            $value = $value[$part];
        }
        if (!is_string($value)) {
            return $asked;
        }
        foreach ($replace as $name => $text) {
            $value = str_replace(':' . $name, (string) $text, $value);
        }

        return $value;
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $this->get($key, $replace);
    }

    public function getLocale()
    {
        return $this->locale;
    }

    public function setLocale($locale)
    {
    }
}

/** Denies the operations listed for an actor; allows the rest. */
final class EncryptionRunAuthorizer implements ActorOperationAuthorizer
{
    /** @param array<string, list<string>> $denied */
    public function __construct(private array $denied = [])
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        if (in_array($operation, $this->denied[$actor] ?? [], true)) {
            throw new AccessMutationRejected('access_actor_forbidden');
        }
    }
}

final class EncryptionRunScope implements StorageSectionScope
{
    public function scopeForActor(string $actor): string
    {
        return 'scope:alpha';
    }

    public function fileBelongsToScope(string $logicalRef, string $scopeRef): bool
    {
        return false;
    }
}

final class EncryptionRunInspector implements PersistentLogicalFileInspector
{
    public function inspect(string $logicalRef): PersistentLogicalFileInspection
    {
        throw new RuntimeException('not_used');
    }
}

/**
 * One storage whose fields all hold values, as Storage answers it without encryption runs: a change is applied as
 * given. {@see EncryptionRunWorkbench} adds the runs.
 */
class EncryptionRunPlainWorkbench implements StorageWorkbench
{
    public StorageWorkbenchStructure $structure;
    public ?StorageWorkbenchEncryptionRun $run = null;
    /** @var list<array<string, mixed>> the descriptors of the structure changes, as given */
    public array $descriptors = [];
    public ?string $refuse = null;

    public function __construct()
    {
        $this->structure = new StorageWorkbenchStructure('workbench.people', 'scope:alpha', 3, new StorageSchemaVersionRef('workbench.people', 3), 'Люди', [
            ['key' => 'name', 'label' => 'Имя', 'position' => 10, 'type' => 'string', 'type_version' => 1, 'required' => false, 'visibility' => 'public', 'constraints' => []],
            ['key' => 'passport', 'label' => 'Паспорт', 'position' => 20, 'type' => 'string', 'type_version' => 1, 'required' => false, 'visibility' => 'admin',
                'constraints' => [], 'encrypted' => true, 'searchable' => false],
            ['key' => 'note', 'label' => 'Заметка', 'position' => 30, 'type' => 'text', 'type_version' => 1, 'required' => false, 'visibility' => 'public', 'constraints' => []],
        ], hash('sha256', 'people-3'), '2026-10-22T00:00:00Z');
    }

    /** The storage with this run open (or none). */
    public function open(?StorageWorkbenchEncryptionRun $run): void
    {
        $this->run = $run;
    }

    public static function run(string $mode, string $state, array $fields, int $rewritten = 0, int $total = 0, ?string $reason = null, int $skipped = 0): StorageWorkbenchEncryptionRun
    {
        return new StorageWorkbenchEncryptionRun('storage-encryption-run-' . str_repeat('a', 32), $mode, $state, $fields, $rewritten, $skipped, $total, $reason,
            '2026-10-22T10:00:00Z', $state === 'done' ? '2026-10-22T10:05:00Z' : null);
    }

    protected function stated(): StorageWorkbenchStructure
    {
        return $this->structure->withState(null, false, [], null, $this->run);
    }

    protected function refuseWhileOpen(): void
    {
        if ($this->refuse !== null) {
            [$reason, $this->refuse] = [$this->refuse, null];
            throw new StorageRejected($reason);
        }
        if ($this->run !== null && $this->run->open()) {
            throw new StorageRejected($this->run->state === 'failed' ? StorageEncryptionPolicy::FAILED : StorageEncryptionPolicy::RUNNING);
        }
    }

    public function filterOperators(): array
    {
        return [];
    }

    public function createStructure(string $scopeRef, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('not_used');
    }

    public function updateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->change($descriptor);
    }

    public function migrateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->change($descriptor);
    }

    /** @param array<string, mixed> $descriptor */
    protected function change(array $descriptor): StorageWorkbenchStructure
    {
        $this->refuseWhileOpen();
        $this->descriptors[] = $descriptor;
        $s = $this->structure;
        $this->structure = new StorageWorkbenchStructure($s->structureId, $s->scopeRef, $s->version + 1, $s->schema, (string) $descriptor['label'],
            $descriptor['fields'], $s->descriptorHash, $s->updatedAt);

        return $this->stated();
    }

    public function cancelFieldChange(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        throw new StorageRejected('storage_workbench_field_change_not_running');
    }

    public function readStructure(string $scopeRef, string $structureId, string $actor): StorageWorkbenchStructure
    {
        return $structureId === $this->structure->structureId ? $this->stated() : throw new StorageRejected('storage_structure_missing');
    }

    public function listStructures(string $scopeRef, string $actor, bool $includeArchived = false): array
    {
        return [$this->stated()];
    }

    public function setHistoryKeep(string $scopeRef, string $structureId, ?int $keep, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->stated();
    }

    public function archiveStructure(string $scopeRef, string $structureId, int $expectedVersion, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $this->refuseWhileOpen();

        return $this->stated();
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
        throw new StorageRejected('not_used');
    }

    public function updateRecord(string $scopeRef, string $structureId, string $recordId, int $expectedRevision, array $values, string $actor, ?string $correlationId = null): StorageWorkbenchRecord
    {
        throw new StorageRejected('not_used');
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
        throw new StorageRejected('storage_record_missing');
    }

    public function listRecords(StorageWorkbenchRecordQuery $query, string $actor): StorageWorkbenchRecordPage
    {
        return new StorageWorkbenchRecordPage([], null, 5000, true);
    }

    public function recordHistory(string $scopeRef, string $structureId, string $recordId, string $actor, int $limit = 50): array
    {
        return [];
    }
}

/**
 * The same storage with Storage's encryption runs: switching encryption on or off a field (every field holds values)
 * opens a queued run, switching off needs encryption_disable_confirmed, one change switches one way only; a key
 * change opens a rotate run (or ends in the request); a failed run is retried.
 */
final class EncryptionRunWorkbench extends EncryptionRunPlainWorkbench implements StorageWorkbenchEncryption
{
    /** A key change or a retry ends in the request (a small storage). */
    public bool $inline = false;
    /** @var list<string> */
    public array $calls = [];

    protected function change(array $descriptor): StorageWorkbenchStructure
    {
        $this->refuseWhileOpen();
        $confirmed = $descriptor['encryption_disable_confirmed'] ?? false;
        unset($descriptor['encryption_disable_confirmed']);
        $before = StorageEncryptionPolicy::encryptedKeys($this->structure->fields);
        $after = StorageEncryptionPolicy::encryptedKeys($descriptor['fields']);
        $on = array_values(array_diff($after, $before));
        $off = array_values(array_diff($before, $after));
        if ($on !== [] && $off !== []) {
            throw new StorageRejected(StorageEncryptionPolicy::CHANGE_COMBINED);
        }
        if ($off !== [] && $confirmed !== true) {
            throw new StorageRejected(StorageEncryptionPolicy::DISABLE_UNCONFIRMED);
        }
        $given = $descriptor + ($confirmed === true ? ['encryption_disable_confirmed' => true] : []);
        $structure = parent::change($descriptor);
        // The descriptor as the section gave it, flag and all.
        $this->descriptors[count($this->descriptors) - 1] = $given;
        if ($on !== [] || $off !== []) {
            $this->run = self::run($on !== [] ? 'encrypt' : 'decrypt', 'queued', $on !== [] ? $on : $off, 0, 5000);
        }

        return $this->run === null ? $structure : $this->stated();
    }

    public function rotateEncryptionKey(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $this->refuseWhileOpen();
        if (StorageEncryptionPolicy::encryptedKeys($this->structure->fields) === []) {
            throw new StorageRejected(StorageEncryptionPolicy::NOT_ENABLED);
        }
        $this->calls[] = 'rotate:' . $actor;
        $this->run = $this->inline ? null : self::run('rotate', 'queued', StorageEncryptionPolicy::encryptedKeys($this->structure->fields), 0, 5000);

        return $this->stated();
    }

    public function retryEncryptionRun(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        if ($this->run === null || $this->run->state !== 'failed') {
            throw new StorageRejected(StorageEncryptionPolicy::NOT_FAILED);
        }
        $this->calls[] = 'retry:' . $actor;
        $this->run = $this->inline ? null : self::run($this->run->mode, 'running', $this->run->fields, $this->run->rewritten, $this->run->total);

        return $this->stated();
    }
}

/** Storage's SitePack as far as the export page uses it; `$plaintext` says whether it takes the plaintext option. */
class EncryptionRunSitePack implements StorageSitePack
{
    /** @var list<array{string, bool}> structure and plaintext of each start */
    public array $starts = [];
    public bool $deny = false;

    public function startExport(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        $this->starts[] = [$structureId, false];

        return self::queued($scopeRef, $structureId, $actor);
    }

    public static function queued(string $scopeRef, string $structureId, string $actor): StorageSitePackRun
    {
        return new StorageSitePackRun('storage-sitepack-' . str_repeat('9', 32), 'export', $scopeRef, 'site:main', $structureId, $actor, 'queued', 'prepare',
            0, 0, [], null, null, null, 'job-1', null, null, '2026-10-22T09:00:00+00:00', '2026-10-22T09:00:00+00:00', null);
    }

    public function run(string $runRef, string $actor): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_run_unknown');
    }

    public function read(string $runRef, string $actor): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_run_unknown');
    }

    public function recent(string $actor, int $limit = 20): array
    {
        return [];
    }

    public function archive(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackArchiveFile
    {
        throw new StorageRejected('storage_sitepack_run_not_done');
    }

    public function cancel(string $runRef, string $actor, ?string $correlationId = null): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_run_unknown');
    }

    public function retry(string $runRef, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_run_unknown');
    }

    public function receive(string $scopeRef, string $archivePath, string $actor, ?string $correlationId = null): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_import_denied');
    }

    public function plan(string $runRef, StorageSitePackImportOptions $options, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_import_denied');
    }

    public function apply(string $runRef, string $planDigest, string $actor, ?string $correlationId = null, bool $queued = true): StorageSitePackRun
    {
        throw new StorageRejected('storage_sitepack_import_denied');
    }
}

/** Storage's own SitePack, which takes includeEncryptedPlaintext beyond the contract (it checks the decrypt right). */
final class EncryptionRunPlaintextSitePack extends EncryptionRunSitePack
{
    public function startExport(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null, bool $queued = true,
        bool $includeEncryptedPlaintext = false): StorageSitePackRun
    {
        if ($includeEncryptedPlaintext && $this->deny) {
            throw new AccessMutationRejected('access_actor_forbidden');
        }
        $this->starts[] = [$structureId, $includeEncryptedPlaintext];

        return self::queued($scopeRef, $structureId, $actor);
    }
}

/**
 * What the pages read again after each step: the storage as the gateway projects it, a gateway action, and what the
 * last redirect said. Each call reads anew.
 */
final class EncryptionRunReads
{
    public ?Store $session = null;

    public function __construct(private StorageSectionGateway $gateway, private string $actor)
    {
    }

    /**
     * @phpstan-impure
     * @return array<string, mixed>
     */
    public function selected(): array
    {
        $selected = $this->gateway->snapshot($this->actor, null, ['surface' => StorageSectionGateway::SURFACE, 'structure_id' => 'workbench.people', 'records' => 'skip'])['selected_structure'];
        assert(is_array($selected));

        return $selected;
    }

    /**
     * @phpstan-impure
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatch(string $action, array $payload): array
    {
        return $this->gateway->dispatch($this->actor, null, $action, $payload);
    }

    /**
     * The status and the error the last redirect flashed; reading them clears them.
     *
     * @phpstan-impure
     * @return array{0: mixed, 1: ?string}
     */
    public function flash(): array
    {
        assert($this->session !== null);
        $errors = $this->session->get('errors');
        $said = [$this->session->get('status'), $errors instanceof \Illuminate\Support\ViewErrorBag ? $errors->first('storage') : null];
        $this->session->forget(['status', 'errors']);

        return $said;
    }
}

$translator = new EncryptionRunTexts('ru');
Container::getInstance()->instance('translator', $translator);
$ru = storage_admin_words('ru');
$en = storage_admin_words('en');
$admin = 'user:admin_identity:1';
$editor = 'user:admin_identity:2';
$nbsp = "\u{00A0}";
$authorizer = new EncryptionRunAuthorizer([$editor => ['storage.encryption.manage', 'storage.workbench.record.decrypt']]);
$workbench = new EncryptionRunWorkbench();
$gateway = new StorageSectionGateway($authorizer, new EncryptionRunScope(), $workbench, new EncryptionRunInspector());
$section = ['surface' => StorageSectionGateway::SURFACE, 'structure_id' => 'workbench.people', 'records' => 'skip'];
$reads = new EncryptionRunReads($gateway, $admin);
$state = new StorageEncryptionRunState($translator);
$changes = new StorageFieldChangeState($translator);

// 1. At rest the storage has no run and the section says nothing; the rights to manage encryption and to decrypt are
// the person's own.
$rest = $reads->selected();
assert($rest['encryption_run'] === null && $rest['changing'] === false);
assert($state->notice($rest) === null && $state->locked($rest) === null && $state->fieldMarks($rest) === [] && $state->retryOffer($rest) === null);
assert(!StorageEncryptionRunState::open($rest) && !StorageFieldChangeState::changing($rest) && $changes->notice($rest) === null);
$permissions = $gateway->snapshot($admin, null, $section)['permissions'];
assert($permissions['encryption.manage'] === true && $permissions['record.decrypt'] === true);
$editorPermissions = $gateway->snapshot($editor, null, $section)['permissions'];
assert($editorPermissions['encryption.manage'] === false && $editorPermissions['record.decrypt'] === false);
// A run that is done is not open: nothing to say.
$workbench->open(EncryptionRunPlainWorkbench::run('encrypt', 'done', ['note'], 5000, 5000));
$read1 = $reads->selected();
$read2 = $reads->selected();
assert($read1['encryption_run'] === null && $state->notice($read2) === null);

// 2. A running run: its progress (rewritten and skipped of the rows), the structure locked, the field marked.
$workbench->open(EncryptionRunPlainWorkbench::run('encrypt', 'running', ['note'], 1000, 5000, null, 200));
$running = $reads->selected();
assert($running['changing'] === true && $running['encryption_run'] === ['run_ref' => 'storage-encryption-run-' . str_repeat('a', 32), 'mode' => 'encrypt',
    'state' => 'running', 'fields' => ['note'], 'rewritten' => 1000, 'skipped' => 200, 'total' => 5000, 'reason' => null,
    'started_at' => '2026-10-22T10:00:00Z', 'finished_at' => null], json_encode($running['encryption_run']));
$notice = $state->notice($running);
assert($notice !== null && $notice['kind'] === 'encryption_running' && $notice['tone'] === 'info');
assert($notice['title'] === "Шифруем поле «Заметка»: обработано 1{$nbsp}200 из 5{$nbsp}000", $notice['title']);
assert($notice['text'] === 'Шифрование поля идёт в фоне — список работает как обычно.');
assert($notice['progress'] === ['value' => 1200, 'max' => 5000, 'label' => 'Обработано записей']);
assert($changes->notice($running) === $notice, 'the field change state speaks the run');
assert(StorageFieldChangeState::changing($running) && StorageEncryptionRunState::running($running));
assert($state->locked($running) === 'Пока идёт шифрование, структуру хранилища менять и архивировать хранилище временно нельзя.'
    && $changes->locked($running) === $state->locked($running));
assert($state->fieldMarks($running) === ['note' => 'шифруется…'] && $changes->fieldMarks($running) === ['note' => 'шифруется…']);
assert($state->retryOffer($running) === null, 'a running run is not retried');
assert($state->refusal(StorageEncryptionRunState::RUNNING, $running) === 'Сейчас шифруется поле «Заметка». Дождитесь окончания.');
assert($changes->refusal(StorageSectionGateway::FIELD_CHANGE_RUNNING, $running) === 'Сейчас шифруется поле «Заметка». Дождитесь окончания.',
    'a page opened before the run is refused in the run\'s words');
assert($state->refusal('storage_workbench_action_rejected', $running) === null);
assert($changes->started($running) === 'Шифрование поля идёт в фоне — список работает как обычно.' && $changes->started() === 'Изменение поля идёт в фоне — список работает как обычно.');
// Every structure change and archiving is refused meanwhile, with Storage's reason.
foreach ([
    $reads->dispatch('structure.configure', ['structure_id' => 'workbench.people', 'label' => 'Люди', 'expected_version' => 3,
        'fields' => [['key' => 'name', 'label' => 'ФИО']]]),
    $reads->dispatch('structure.archive', ['structure_id' => 'workbench.people', 'expected_version' => 3, 'confirmed' => '1']),
] as $refused) {
    assert($refused['status'] === 'rejected' && $refused['reason_code'] === StorageEncryptionPolicy::RUNNING, json_encode($refused));
}
// Queued, not counted yet; several fields; decrypt; rotate.
$workbench->open(EncryptionRunPlainWorkbench::run('encrypt', 'queued', ['note', 'name']));
$queued = $reads->selected();
assert($state->notice($queued)['title'] === 'Шифруем поля «Заметка», «Имя»: начинаем' && $state->notice($queued)['progress'] === null);
assert($state->refusal(StorageEncryptionRunState::RUNNING, $queued) === 'Сейчас шифруются поля «Заметка», «Имя». Дождитесь окончания.');
$workbench->open(EncryptionRunPlainWorkbench::run('decrypt', 'running', ['passport'], 10, 20));
$decrypting = $reads->selected();
assert($state->notice($decrypting)['title'] === 'Расшифровываем поле «Паспорт»: обработано 10 из 20');
assert($state->notice($decrypting)['text'] === 'Расшифровка поля идёт в фоне — список работает как обычно.');
assert($state->fieldMarks($decrypting) === ['passport' => 'расшифровывается…']);
assert($state->refusal(StorageEncryptionRunState::RUNNING, $decrypting) === 'Сейчас расшифровывается поле «Паспорт». Дождитесь окончания.');
$workbench->open(EncryptionRunPlainWorkbench::run('rotate', 'running', ['passport'], 3, 20));
$rotating = $reads->selected();
assert($state->notice($rotating)['title'] === 'Меняем ключ шифрования: обработано 3 из 20');
assert($state->notice($rotating)['text'] === 'Смена ключа шифрования идёт в фоне — список работает как обычно.');
assert($state->refusal(StorageEncryptionRunState::RUNNING, $rotating) === 'Сейчас меняется ключ шифрования хранилища. Дождитесь окончания.');
assert($state->refusal(StorageEncryptionRunState::RUNNING, null) === 'Сейчас идёт шифрование хранилища. Дождитесь окончания.');

// 3. A stopped run: its reason in plain words, «Повторить», the structure still locked.
$failed = static function (string $mode, ?string $reason) use ($workbench, $reads): array {
    $workbench->open(EncryptionRunPlainWorkbench::run($mode, 'failed', ['note'], 2000, 5000, $reason));

    return $reads->selected();
};
$stopped = $failed('encrypt', 'storage_field_encryption_record_corrupt');
$notice = $state->notice($stopped);
assert($notice['kind'] === 'encryption_failed' && $notice['tone'] === 'danger' && $notice['title'] === 'Шифрование поля «Заметка» остановилось' && $notice['progress'] === null);
assert($notice['text'] === 'Одна из записей повреждена, её значение нельзя прочитать. Обратитесь к администратору сервера. '
    . 'Уже обработанные записи сохранены; «Повторить» продолжит с того места, где работа остановилась.', $notice['text']);
assert($state->retryOffer($stopped) === ['label' => 'Повторить'] && $state->fieldMarks($stopped) === []);
assert(StorageFieldChangeState::changing($stopped) && !StorageEncryptionRunState::running($stopped));
assert($state->locked($stopped) === 'Пока шифрование не завершено, структуру хранилища менять и архивировать хранилище нельзя. Продолжите его кнопкой «Повторить».');
assert($state->refusal(StorageEncryptionRunState::FAILED, $stopped) === 'Шифрование хранилища остановилось. Сначала продолжите его кнопкой «Повторить».');
assert($state->notice($failed('decrypt', null))['title'] === 'Расшифровка поля «Заметка» остановилась');
assert($state->notice($failed('rotate', null))['title'] === 'Смена ключа шифрования остановилась');
foreach ([
    'storage_field_encryption_key_still_used' => 'Прежний ключ ещё нужен части записей, поэтому его нельзя удалить.',
    'storage_field_encryption_interrupted' => 'Работа была прервана: её перехватил другой процесс или сервер перезапустился.',
    'storage_field_encryption_key_unavailable' => 'Ключ шифрования хранилища не открывается на этом сайте. Обратитесь к администратору сервера.',
    'storage_field_encryption_unavailable' => 'Шифрование сейчас недоступно на этом сайте. Обратитесь к администратору сервера.',
    'storage_persistence_failed' => 'Работа остановилась из-за сбоя на сервере.',
] as $reason => $text) {
    assert($state->reason($reason) === $text, $reason);
}
assert($state->reason(null) === 'Работа остановилась из-за сбоя на сервере.');

// 4. «Повторить» and «Сменить ключ шифрования» through the gateway: Storage's own calls, their refusals passed on.
$failed('encrypt', 'storage_field_encryption_interrupted');
$retried = $reads->dispatch('structure.encryption_retry', ['structure_id' => 'workbench.people']);
assert($retried['status'] === 'ok' && $retried['reason_code'] === StorageSectionGateway::ENCRYPTION_STARTED && $workbench->calls === ['retry:' . $admin], json_encode($retried));
assert($retried['redirect'] === ['scope_ref' => 'scope:alpha', 'structure_id' => 'workbench.people'], 'the run is not an address');
$notFailed = $reads->dispatch('structure.encryption_retry', ['structure_id' => 'workbench.people']);
assert($notFailed['status'] === 'rejected' && $notFailed['reason_code'] === StorageEncryptionPolicy::NOT_FAILED);
$workbench->open(null);
$unconfirmed = $reads->dispatch('structure.encryption_rotate', ['structure_id' => 'workbench.people']);
assert($unconfirmed['status'] === 'rejected' && $unconfirmed['reason_code'] === 'storage_workbench_action_rejected' && count($workbench->calls) === 1,
    'a key change is confirmed first');
$rotated = $reads->dispatch('structure.encryption_rotate', ['structure_id' => 'workbench.people', 'confirmed' => '1']);
assert($rotated['status'] === 'ok' && $rotated['reason_code'] === StorageSectionGateway::ENCRYPTION_STARTED && end($workbench->calls) === 'rotate:' . $admin);
$read3 = $reads->dispatch('structure.encryption_rotate', ['structure_id' => 'workbench.people', 'confirmed' => '1']);
assert($read3['reason_code'] === StorageEncryptionPolicy::RUNNING);
$workbench->open(null);
$workbench->inline = true;
$read4 = $reads->dispatch('structure.encryption_rotate', ['structure_id' => 'workbench.people', 'confirmed' => '1']);
assert($read4['reason_code'] === 'storage_workbench_action_applied',
    'a small storage changes its key in the request');
$workbench->inline = false;
// Without Storage's runs a key change is unavailable in words; an explicitly bound one is used.
$plain = new EncryptionRunPlainWorkbench();
$unavailable = (new StorageSectionGateway($authorizer, new EncryptionRunScope(), $plain, new EncryptionRunInspector()))
    ->dispatch($admin, null, 'structure.encryption_rotate', ['structure_id' => 'workbench.people', 'confirmed' => '1']);
assert($unavailable['status'] === 'rejected' && $unavailable['reason_code'] === StorageEncryptionPolicy::UNAVAILABLE);
$bound = new EncryptionRunWorkbench();
(new StorageSectionGateway($authorizer, new EncryptionRunScope(), $plain, new EncryptionRunInspector(), $bound))
    ->dispatch($admin, null, 'structure.encryption_rotate', ['structure_id' => 'workbench.people', 'confirmed' => '1']);
assert($bound->calls === ['rotate:' . $admin]);

// 5. Switching encryption on and off through the structure change: the run starts and the receipt says so; switching
// off a field with values needs the confirmation flag, which goes to Storage in the descriptor.
$workbench->open(null);
$configure = static fn (array $field, array $more = []): array => $reads->dispatch('structure.configure', [
    'structure_id' => 'workbench.people', 'label' => 'Люди', 'expected_version' => $workbench->structure->version,
    'fields' => [$field], 'confirm_migration' => '1'] + $more);
$on = $configure(['key' => 'note', 'label' => 'Заметка', 'type' => 'text', 'encrypted' => true]);
assert($on['status'] === 'ok' && $on['reason_code'] === StorageSectionGateway::ENCRYPTION_STARTED, json_encode($on));
assert(!array_key_exists('encryption_disable_confirmed', end($workbench->descriptors)), 'switching on needs no flag');
$read5 = $reads->selected();
$read6 = $reads->selected();
assert($read5['encryption_run']['mode'] === 'encrypt' && $read6['encryption_run']['fields'] === ['note']);
$workbench->open(null);
$off = $configure(['key' => 'passport', 'label' => 'Паспорт', 'type' => 'string', 'encrypted' => false]);
assert($off['status'] === 'rejected' && $off['reason_code'] === StorageEncryptionPolicy::DISABLE_UNCONFIRMED, json_encode($off));
$off = $configure(['key' => 'passport', 'label' => 'Паспорт', 'type' => 'string', 'encrypted' => false], ['encryption_disable_confirmed' => '1']);
assert($off['status'] === 'ok' && $off['reason_code'] === StorageSectionGateway::ENCRYPTION_STARTED && end($workbench->descriptors)['encryption_disable_confirmed'] === true);
$read7 = $reads->selected();
assert($read7['encryption_run']['mode'] === 'decrypt');
$workbench->open(null);
// Back to the storage as it was: «Паспорт» encrypted, «Заметка» not.
$workbench->structure = (new EncryptionRunWorkbench())->structure;

// 6. The field editor: the confirmation beside «Шифровать» on an existing field, for a person who manages encryption.
$database = tempnam(sys_get_temp_dir(), 'larena-encryption-run-');
assert(is_string($database));
try {
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
    $connection = $capsule->getConnection();
    $connection->getSchemaBuilder()->create('larena_setting_values', static function (Blueprint $table): void {
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
    \Larena\Setting\Persistence\DatabaseViewSettingsStore::createSchema($connection->getSchemaBuilder());
    $port = new StorageFieldPropertyViewPort($gateway, new AdminRecordLayoutStore(\Larena\Setting\Runtime\ViewSettings::on($connection)), $authorizer);
    $collection = StorageFieldPropertyViewPort::COLLECTION_PREFIX . 'workbench.people';
    /** @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>} */
    $load = static function (string $actor, array $payload) use ($port, $collection): array {
        $answer = $port->raise($actor, 'scope:alpha', 'record.load', ['collection' => $collection, 'reason' => 'connected'] + $payload);
        assert(($answer['answer'] ?? null) === 'applied', json_encode($answer, JSON_UNESCAPED_UNICODE));
        $fields = [];
        foreach ($answer['record']['sections'] as $part) {
            foreach ($part['groups'] as $group) {
                foreach ($group['fields'] as $item) {
                    $fields[$item['key']] = $item;
                }
            }
        }

        return [$answer, $fields];
    };
    $save = static fn (string $actor, string $key, array $values): array => $port->raise($actor, 'scope:alpha', 'record.save', ['collection' => $collection,
        'record_id' => $key, 'revision' => $workbench->structure->version, 'values' => $values]);
    [, $note] = $load($admin, ['record_id' => 'note']);
    assert($note['encryption_confirmed']['label'] === 'Подтверждаю включение шифрования' && $note['encryption_confirmed']['value'] === false
        && !isset($note['encryption_confirmed']['readonly']), json_encode($note['encryption_confirmed'] ?? null, JSON_UNESCAPED_UNICODE));
    assert($note['encryption_confirmed']['hint'] === 'Поле перестанет участвовать в поиске, сортировке и фильтрах; старые резервные копии базы и журналы MySQL '
        . 'всё ещё содержат открытые значения. Уже сохранённые значения зашифруются в фоне.');
    [, $passport] = $load($admin, ['record_id' => 'passport']);
    assert($passport['encryption_confirmed']['label'] === 'Подтверждаю расшифровку'
        && str_starts_with($passport['encryption_confirmed']['hint'], 'Значения снова будут храниться в базе открыто'));
    // Not for a new field (it has no values), not for a person without the right, not for a type that cannot be encrypted.
    assert(!isset($load($admin, ['mode' => 'create'])[1]['encryption_confirmed']));
    assert(!isset($load($editor, ['record_id' => 'note'])[1]['encryption_confirmed']) && !isset($load($editor, ['record_id' => 'passport'])[1]['encryption_confirmed']));
    // «Шифровать» without the confirmation: nothing is asked of Storage and the field says what changes.
    $count = count($workbench->descriptors);
    $answer = $save($admin, 'note', ['encrypted' => true]);
    assert(($answer['answer'] ?? null) === 'refused' && $answer['reason'] === StorageSectionGateway::ENCRYPTION_CONFIRM_REQUIRED, json_encode($answer, JSON_UNESCAPED_UNICODE));
    assert($answer['errors']['encrypted'] === $ru['larena_storage']['section']['encryption']['errors']['storage_field_encryption_confirm_required']);
    assert(str_contains($answer['errors']['encrypted'], 'поле перестанет участвовать в поиске, сортировке и фильтрах; старые резервные копии базы и журналы MySQL всё ещё содержат открытые значения'));
    assert(count($workbench->descriptors) === $count);
    // Confirmed: the switch goes to Storage and its run starts; the panel shows the run and closes the field meanwhile.
    $answer = $save($admin, 'note', ['encrypted' => true, 'encryption_confirmed' => true]);
    assert(($answer['answer'] ?? null) === 'applied', json_encode($answer, JSON_UNESCAPED_UNICODE));
    assert(end($workbench->descriptors)['fields'][2]['encrypted'] === true && !array_key_exists('encryption_disable_confirmed', end($workbench->descriptors)));
    [$read, $note] = $load($admin, ['record_id' => 'note']);
    assert(str_starts_with((string) $note['field_state']['value'], 'Шифруем поле «Заметка»: обработано 0 из 5' . $nbsp . '000. Шифрование поля идёт в фоне — список работает как обычно.'),
        (string) $note['field_state']['value']);
    assert(str_contains((string) $note['field_state']['value'], 'Пока идёт шифрование, структуру хранилища менять и архивировать хранилище временно нельзя.'));
    $locked = $save($admin, 'name', ['label' => 'ФИО']);
    assert(($locked['answer'] ?? null) === 'refused' && $locked['reason'] === 'edit_denied', json_encode($locked, JSON_UNESCAPED_UNICODE));
    // A refusal from a page opened before the run names the run.
    assert((new ReflectionMethod($port, 'reasonMessage'))->invoke($port, StorageEncryptionRunState::RUNNING) === 'Сейчас шифруется поле «Заметка». Дождитесь окончания.');
    assert((new ReflectionMethod($port, 'reasonMessage'))->invoke($port, StorageEncryptionPolicy::CHANGE_COMBINED)
        === $ru['larena_storage']['section']['encryption']['errors']['storage_field_encryption_change_combined']);
    $workbench->open(null);
    // Switching off: without «Подтверждаю расшифровку» refused in words; with it Storage gets encryption_disable_confirmed.
    $answer = $save($admin, 'passport', ['encrypted' => false]);
    assert(($answer['answer'] ?? null) === 'refused' && $answer['reason'] === StorageEncryptionPolicy::DISABLE_UNCONFIRMED
        && $answer['errors']['encrypted'] === 'Чтобы выключить шифрование у поля со значениями, отметьте «Подтверждаю расшифровку»: значения снова будут храниться в базе открыто.');
    $answer = $save($admin, 'passport', ['encrypted' => false, 'encryption_confirmed' => true]);
    assert(($answer['answer'] ?? null) === 'applied' && end($workbench->descriptors)['encryption_disable_confirmed'] === true, json_encode($answer, JSON_UNESCAPED_UNICODE));
    [, $passport] = $load($admin, ['record_id' => 'passport']);
    assert(str_starts_with((string) $passport['field_state']['value'], 'Расшифровываем поле «Паспорт»'));
    $workbench->open(null);
    // A change that only ticks the confirmation switches nothing.
    $answer = $save($admin, 'name', ['label' => 'Имя', 'encryption_confirmed' => true]);
    $read8 = $reads->selected();
    assert(($answer['answer'] ?? null) === 'applied' && $read8['encryption_run'] === null);
} finally {
    @unlink($database);
}

// 7. The key's state: from Storage's explain of the storage's keys, else from the installation's health.
$explained = static fn (array $keys): Closure => static fn (string $scope, string $structure, string $actor): array => ['keys' => $keys];
$key = static fn (bool $unwrappable, bool $current): array => ['key_id' => 'dk-1', 'status' => 'active', 'kek_id' => 'kek-1', 'algorithm' => 'xchacha20poly1305',
    'created_at' => '2026-10-22', 'retired_at' => null, 'unwrappable' => $unwrappable, 'current_kek' => $current];
$healthy = static fn (): array => ['state' => 'ok', 'reasons' => [], 'keys' => 1, 'previous_kek_keys' => 0, 'unwrappable_missing' => 0];
$status = static fn (?Closure $health, ?Closure $explain): array => (new StorageEncryptionKeyStatus($health, $explain))->status($admin, 'scope:alpha', 'workbench.people');
assert($status($healthy, $explained([$key(true, true)])) === ['state' => 'ok', 'rotatable' => true]);
assert($status($healthy, $explained([])) === ['state' => 'none', 'rotatable' => false]);
assert($status($healthy, $explained([$key(true, true), $key(true, false)])) === ['state' => 'warning', 'rotatable' => true]);
assert($status($healthy, $explained([$key(false, false)])) === ['state' => 'key_missing', 'rotatable' => false]);
assert($status(static fn (): array => ['state' => 'unavailable', 'reasons' => ['storage_field_encryption_unavailable'], 'keys' => 2], $explained([$key(false, true)]))
    === ['state' => 'unavailable', 'rotatable' => false]);
// The explain refused (its right): the installation's health speaks.
$refusedExplain = static fn (string $scope, string $structure, string $actor): array => throw new AccessMutationRejected('access_actor_forbidden');
assert($status(static fn (): array => ['state' => 'warning', 'reasons' => ['storage_data_keys_previous_kek'], 'keys' => 3], $refusedExplain)['state'] === 'warning');
assert($status(static fn (): array => ['state' => 'warning', 'reasons' => ['storage_field_encryption_key_unavailable'], 'keys' => 3], $refusedExplain)['state'] === 'key_missing');
assert($status(static fn (): array => ['state' => 'ok', 'reasons' => [], 'keys' => 0], null)['state'] === 'none');
assert($status(static fn (): array => throw new RuntimeException('down'), null)['state'] === 'unavailable');
assert(!(new StorageEncryptionKeyStatus())->available() && (new StorageEncryptionKeyStatus($healthy))->available());

// 8. «Шифрование» on the settings page: the state in plain words; «Сменить ключ шифрования» asks first, gives way to a
// note while the storage takes no change, and is not offered without a key.
$panel = new StorageEncryptionPanel($translator);
$drawn = $panel->render(['state' => 'ok', 'rotatable' => true], '/admin/storage/workbench.people/encryption/rotate', 'csrf', false);
foreach (['data-larena-storage-encryption="ok"', '>Шифрование</h2>', 'Ключ шифрования в порядке.', 'action="/admin/storage/workbench.people/encryption/rotate"',
    'data-larena-confirm-submit="Сменить ключ шифрования хранилища? Все зашифрованные значения перешифруются в фоне; пока это идёт, структуру хранилища менять нельзя."',
    'name="_token" value="csrf"', 'name="confirmed" value="1"', 'Сменить ключ шифрования'] as $needle) {
    assert(str_contains($drawn, $needle), $needle);
}
$warning = $panel->render(['state' => 'warning', 'rotatable' => true], '/r', 't', true);
assert(str_contains($warning, 'Ключ хранилища ещё зашифрован прежним главным ключом сайта — выполните перешифровку ключей (larena:storage:encryption:rewrap).'));
assert(str_contains($warning, 'Сменить ключ можно, когда закончится текущая работа с хранилищем.') && !str_contains($warning, '<form'), 'locked: no button');
foreach (['none' => 'У хранилища пока нет ключа шифрования', 'key_missing' => 'Один из ключей хранилища не открывается на этом сайте',
    'unavailable' => 'Шифрование сейчас недоступно: на сервере нет главного ключа сайта или расширения sodium.'] as $keyState => $text) {
    $html = $panel->render(['state' => $keyState, 'rotatable' => false], '/r', 't', false);
    assert(str_contains($html, $text) && !str_contains($html, '<form'), $keyState);
}

// 9. The pages: the controller over the gateway, with its routes and session.
$session = new Store('test', new ArraySessionHandler(10));
$session->start();
$routes = new RouteCollection();
foreach (['settings' => 'admin/storage/{structure}/settings', 'records' => 'admin/storage/{structure}'] as $name => $uri) {
    $routes->add((new Route(['GET'], $uri, []))->name('larena.admin.storage.' . $name));
}
$routes->refreshNameLookups();
$redirector = new Redirector(new UrlGenerator($routes, Request::create('http://larena.test/admin')));
$redirector->setSession($session);
$config = new class implements \Illuminate\Contracts\Config\Repository {
    /** @var array<string, mixed> */
    private array $items = ['larena-admin.storage_section.uri' => 'admin/storage', 'larena-auth.admin_entry.access_actor_attribute' => 'larena_access_actor'];
    public function has($key) { return array_key_exists($key, $this->items); }
    public function get($key, $default = null) { return is_string($key) && array_key_exists($key, $this->items) ? $this->items[$key] : $default; }
    public function all() { return $this->items; }
    public function set($key, $value = null) { $this->items[(string) $key] = $value; }
    public function prepend($key, $value) {}
    public function push($key, $value) {}
};
$views = new class implements \Illuminate\Contracts\View\Factory {
    public function exists($view) { return false; }
    public function file($path, $data = [], $mergeData = []) { throw new RuntimeException('not_used'); }
    public function make($view, $data = [], $mergeData = []) { throw new RuntimeException('not_used'); }
    public function share($key, $value = null) { return null; }
    public function composer($views, $callback) { return []; }
    public function creator($views, $callback) { return []; }
    public function addNamespace($namespace, $hints) { return $this; }
    public function replaceNamespace($namespace, $hints) { return $this; }
};
$app = new Container();
$app->instance(StorageEncryptionKeyStatus::class, new StorageEncryptionKeyStatus($healthy, $explained([$key(true, true)])));
$sitePack = new EncryptionRunPlaintextSitePack();
$app->instance(StorageSitePackExports::class, new StorageSitePackExports($sitePack));
$controller = new StorageSectionController($config, $views, $redirector, $translator, $gateway, new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(), $app);
$request = static function (string $actor, string $uri = '/admin/storage/workbench.people/settings', string $method = 'GET', array $input = []) use ($session): Request {
    $request = Request::create('http://larena.test' . $uri, $method, $input);
    $request->attributes->set('larena_access_actor', $actor);
    $request->setLaravelSession($session);

    return $request;
};
$reads->session = $session;
$noticeMethod = new ReflectionMethod(StorageSectionController::class, 'fieldChangeNotice');
// The notice is described (developer remark 3', stage 3); drawn by the shared renderer, its parts read as the page shows them.
$noticeOf = new class($noticeMethod) {
    public function __construct(private ReflectionMethod $method) {}
    public function invoke(object $controller, mixed ...$arguments): ?array
    {
        $notice = $this->method->invoke($controller, ...$arguments);

        return $notice === null ? null : $notice + ['html' => (string) (new \Larena\Admin\Runtime\AdminElementDocumentRenderer())->tree(...$notice['parts'])];
    }
};
$manage = ['encryption.manage' => true, 'structure.migrate' => true, 'structure.configure' => true];
// The running run's notice: its title, its progress bar; no «Повторить», no «Отменить изменение».
$workbench->open(EncryptionRunPlainWorkbench::run('encrypt', 'running', ['note'], 1000, 5000));
$html = $noticeOf->invoke($controller, $request($admin), $reads->selected(), 'workbench.people', $manage, 'records');
assert($html['kind'] === 'encryption_running' && str_contains($html['html'], '<progress value="1000" max="5000" aria-label="Обработано записей"'));
assert(!str_contains($html['html'], 'encryption/retry') && !str_contains($html['html'], 'field-change/cancel'));
// The stopped run's notice: «Повторить» for a person who manages encryption only, back to the page it was pressed on.
$failed('encrypt', 'storage_field_encryption_interrupted');
$html = $noticeOf->invoke($controller, $request($admin), $reads->selected(), 'workbench.people', $manage, 'records');
assert(str_contains($html['html'], 'action="/admin/storage/workbench.people/encryption/retry"') && str_contains($html['html'], 'name="return" value="records"')
    && str_contains($html['html'], 'data-larena-storage-encryption-retry'));
$html = $noticeOf->invoke($controller, $request($admin), $reads->selected(), 'workbench.people', ['encryption.manage' => false, 'structure.migrate' => true], 'settings');
assert($html['kind'] === 'encryption_failed' && !str_contains($html['html'], 'encryption/retry'), 'without the right there is no «Повторить»');
// «Повторить»: Storage resumes the run; the page says it goes on in the background.
$back = $controller->retryEncryptionRun($request($admin, '/admin/storage/workbench.people/encryption/retry', 'POST', ['return' => 'records']), 'workbench.people');
assert($back->getTargetUrl() === 'http://larena.test/admin/storage/workbench.people');
$read9 = $reads->flash();
assert($read9 === ['Шифрование поля идёт в фоне — список работает как обычно.', null]);
$controller->retryEncryptionRun($request($admin, '/admin/storage/workbench.people/encryption/retry', 'POST'), 'workbench.people');
$read10 = $reads->flash();
assert($read10 === [null, 'Повторять нечего: шифрование не останавливалось.'], 'a run that did not stop is not retried');
$failed('encrypt', null);
$workbench->inline = true;
$controller->retryEncryptionRun($request($admin, '/admin/storage/workbench.people/encryption/retry', 'POST'), 'workbench.people');
$read11 = $reads->flash();
assert($read11 === ['Готово: все записи обработаны.', null]);
$workbench->inline = false;
$failed('encrypt', null);
$denied = new StorageSectionController($config, $views, $redirector, $translator,
    new StorageSectionGateway(new EncryptionRunAuthorizer([$admin => ['storage.encryption.manage']]), new EncryptionRunScope(),
        new class extends EncryptionRunPlainWorkbench implements StorageWorkbenchEncryption {
            public function rotateEncryptionKey(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
            {
                throw new AccessMutationRejected('access_actor_forbidden');
            }

            public function retryEncryptionRun(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
            {
                throw new AccessMutationRejected('access_actor_forbidden');
            }
        }, new EncryptionRunInspector()),
    new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(), $app);
$denied->retryEncryptionRun($request($admin, '/admin/storage/workbench.people/encryption/retry', 'POST'), 'workbench.people');
$read12 = $reads->flash();
assert($read12[1] === $ru['larena_storage']['section']['notice_denied'], 'Storage asks for the right itself');
// «Сменить ключ шифрования»: confirmed by the page's question; refused in words while a run is open or without a key.
$workbench->open(null);
$controller->rotateEncryptionKey($request($admin, '/admin/storage/workbench.people/encryption/rotate', 'POST', ['confirmed' => '1']), 'workbench.people');
$read13 = $reads->flash();
assert($read13 === ['Смена ключа шифрования идёт в фоне — список работает как обычно.', null]);
$controller->rotateEncryptionKey($request($admin, '/admin/storage/workbench.people/encryption/rotate', 'POST', ['confirmed' => '1']), 'workbench.people');
$read14 = $reads->flash();
assert($read14 === [null, 'Сейчас меняется ключ шифрования хранилища. Дождитесь окончания.']);
$workbench->open(null);
$workbench->inline = true;
$controller->rotateEncryptionKey($request($admin, '/admin/storage/workbench.people/encryption/rotate', 'POST', ['confirmed' => '1']), 'workbench.people');
$read15 = $reads->flash();
assert($read15 === ['Ключ шифрования сменён.', null]);
$workbench->inline = false;
$keyless = new EncryptionRunWorkbench();
$keyless->structure = new StorageWorkbenchStructure('workbench.people', 'scope:alpha', 1, new StorageSchemaVersionRef('workbench.people', 1), 'Люди',
    [$workbench->structure->fields[0]], 'h', '2026-10-22T00:00:00Z');
(new StorageSectionController($config, $views, $redirector, $translator, new StorageSectionGateway($authorizer, new EncryptionRunScope(), $keyless, new EncryptionRunInspector()),
    new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(), $app))
    ->rotateEncryptionKey($request($admin, '/admin/storage/workbench.people/encryption/rotate', 'POST', ['confirmed' => '1']), 'workbench.people');
$read16 = $reads->flash();
assert($read16 === [null, 'У хранилища ещё нет ключа шифрования: сначала включите шифрование хотя бы у одного поля.']);
// The settings page's «Шифрование»: for a person who manages encryption only; the button closed while a run is open.
$panelOf = new ReflectionMethod(StorageSectionController::class, 'encryptionPanel');
$pageState = ['status' => 'ok', 'scope_ref' => 'scope:alpha'];
$workbench->open(null);
$shown = $panelOf->invoke($controller, $request($admin), $pageState, 'workbench.people', $manage, $reads->selected());
assert($shown instanceof \Illuminate\Support\HtmlString);
$shown = (string) $shown;
assert(str_contains($shown, 'Ключ шифрования в порядке.') && str_contains($shown, 'action="/admin/storage/workbench.people/encryption/rotate"'));
$read17 = $reads->selected();
assert($panelOf->invoke($controller, $request($editor), $pageState, 'workbench.people', ['encryption.manage' => false] + $manage, $read17) === null);
$workbench->open(EncryptionRunPlainWorkbench::run('rotate', 'running', ['passport'], 1, 2));
$closed = (string) $panelOf->invoke($controller, $request($admin), $pageState, 'workbench.people', $manage, $reads->selected());
assert(str_contains($closed, 'Сменить ключ можно, когда закончится текущая работа с хранилищем.') && !str_contains($closed, '<form'));
$workbench->open(null);
assert($panelOf->invoke(new StorageSectionController($config, $views, $redirector, $translator, $gateway, new AdminDataviewPagePresenter(), new AdminRecordPanelPresenter(),
    new Container()), $request($admin), $pageState, 'workbench.people', $manage, $reads->selected()) === null, 'without Storage\'s keys there is no section');
// A save from the full form that switched encryption: «Шифрование поля идёт в фоне — список работает как обычно».
$controller->dispatch($request($admin, '/admin/storage/workbench.people', 'POST', ['action' => 'structure.configure', 'payload' => [
    'label' => 'Люди', 'expected_version' => (string) $workbench->structure->version, 'confirm_migration' => '1',
    'fields' => [['key' => 'name', 'label' => 'Имя', 'type' => 'string', 'encrypted' => '1']]]]), 'workbench.people');
$read18 = $reads->flash();
assert($read18 === ['Шифрование поля идёт в фоне — список работает как обычно.', null]);
$controller->dispatch($request($admin, '/admin/storage/workbench.people', 'POST', ['action' => 'structure.configure', 'payload' => [
    'label' => 'Люди', 'expected_version' => (string) $workbench->structure->version, 'fields' => [['key' => 'name', 'label' => 'ФИО']]]]), 'workbench.people');
$read19 = $reads->flash();
assert($read19 === [null, 'Сейчас шифруется поле «Имя». Дождитесь окончания.']);
$workbench->open(null);
$workbench->refuse = StorageEncryptionPolicy::CHANGE_COMBINED;
$controller->dispatch($request($admin, '/admin/storage/workbench.people', 'POST', ['action' => 'structure.configure', 'payload' => [
    'label' => 'Люди', 'expected_version' => (string) $workbench->structure->version, 'fields' => [['key' => 'name', 'label' => 'ФИО']]]]), 'workbench.people');
$read20 = $reads->flash();
assert($read20 === [null, $ru['larena_storage']['section']['encryption']['errors']['storage_field_encryption_change_combined']]);

// 10. «Перенос на другой сайт»: encrypted values stay out unless a person with decrypt and export asks for them in
// plaintext, which only Storage's own SitePack takes; the warning says what the archive then holds.
$sitePanel = new ReflectionMethod(StorageSectionController::class, 'sitePackPanel');
$exportRights = ['sitepack.export' => true, 'record.decrypt' => true];
$offered = (string) $sitePanel->invoke($controller, $request($admin), $pageState, 'workbench.people', $exportRights, $reads->selected());
assert(str_contains($offered, 'Значения зашифрованных полей в выгрузку не попадут: на новом сайте эти поля будут пустыми.'));
assert(str_contains($offered, 'Включить зашифрованные поля в открытом виде') && str_contains($offered, 'data-larena-storage-sitepack-plaintext')
    && str_contains($offered, 'Архив будет содержать значения зашифрованных полей открытым текстом.'), $offered);
$noDecrypt = (string) $sitePanel->invoke($controller, $request($editor), $pageState, 'workbench.people', ['sitepack.export' => true, 'record.decrypt' => false], $reads->selected());
assert(str_contains($noDecrypt, 'в выгрузку не попадут') && !str_contains($noDecrypt, 'data-larena-storage-sitepack-plaintext'), 'without decrypt no plaintext');
$unencrypted = $reads->selected();
$unencrypted['fields'] = array_values(array_filter($unencrypted['fields'], static fn (array $field): bool => ($field['encrypted'] ?? false) !== true));
assert($unencrypted['fields'] !== []);
$plainPanel = (string) $sitePanel->invoke($controller, $request($admin), $pageState, 'workbench.people', $exportRights, $unencrypted);
assert(!str_contains($plainPanel, 'в выгрузку не попадут') && !str_contains($plainPanel, 'data-larena-storage-sitepack-plaintext'), 'nothing to say without encrypted fields');
$contractOnly = new Container();
$contractOnly->instance(StorageSitePackExports::class, new StorageSitePackExports(new EncryptionRunSitePack()));
$withoutOption = (string) $sitePanel->invoke(new StorageSectionController($config, $views, $redirector, $translator, $gateway, new AdminDataviewPagePresenter(),
    new AdminRecordPanelPresenter(), $contractOnly), $request($admin), $pageState, 'workbench.people', $exportRights, $reads->selected());
assert(!str_contains($withoutOption, 'data-larena-storage-sitepack-plaintext'), 'a SitePack without the option offers none');
assert((new StorageSitePackExports($sitePack))->supportsPlaintext() && !(new StorageSitePackExports(new EncryptionRunSitePack()))->supportsPlaintext());
// The export: the box ticked passes includeEncryptedPlaintext; unticked it does not; a SitePack without it refuses in words.
$export = new StorageSitePackController($config, $redirector, $translator, $gateway, $app);
$export->export($request($admin, '/admin/storage/workbench.people/sitepack/export', 'POST', ['include_encrypted_plaintext' => '1']), 'workbench.people');
$export->export($request($admin, '/admin/storage/workbench.people/sitepack/export', 'POST'), 'workbench.people');
assert($sitePack->starts === [['workbench.people', true], ['workbench.people', false]], json_encode($sitePack->starts));
$reads->flash();
$sitePack->deny = true;
$export->export($request($editor, '/admin/storage/workbench.people/sitepack/export', 'POST', ['include_encrypted_plaintext' => '1']), 'workbench.people');
$read21 = $reads->flash();
assert($read21[1] === 'Выгрузить зашифрованные поля в открытом виде может только тот, кому разрешено их читать.');
$contractExport = new StorageSitePackController($config, $redirector, $translator, $gateway, $contractOnly);
$contractExport->export($request($admin, '/admin/storage/workbench.people/sitepack/export', 'POST', ['include_encrypted_plaintext' => '1']), 'workbench.people');
$read22 = $reads->flash();
assert($read22[1] === 'Выгрузить зашифрованные поля в открытом виде на этом сайте нельзя.');
$sitePackPanel = new StorageSitePackPanel($translator);
assert($sitePackPanel->reason(StorageEncryptionPolicy::KEY_UNAVAILABLE) === 'Ключ шифрования хранилища не открывается на этом сайте, поэтому зашифрованные поля выгрузить нельзя.');

// 11. The routes: both under /{structure}/encryption, never meeting another storage route.
$routeSource = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/admin/storage-section.php');
foreach ([
    "Route::post('/{structure}/encryption/rotate', [StorageSectionController::class, 'rotateEncryptionKey'])->where('structure', \$structure)->name('encryption.rotate')",
    "Route::post('/{structure}/encryption/retry', [StorageSectionController::class, 'retryEncryptionRun'])->where('structure', \$structure)->name('encryption.retry')",
] as $needle) {
    assert(str_contains($routeSource, $needle), $needle);
}
assert(preg_match('/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D', 'encryption') === 0, 'a storage ID never reads as «encryption»');
$settingsView = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/admin/settings.blade.php');
assert(str_contains($settingsView, "{{ \$encryptionPanel ?? '' }}"), 'the settings page prints the section drawn by the shared renderer');

// 12. Every word exists in English too, under the same keys; every refusal code has its words.
$keys = static function (array $words, string $prefix = '') use (&$keys): array {
    $found = [];
    foreach ($words as $name => $value) {
        $found = [...$found, ...(is_array($value) ? $keys($value, $prefix . $name . '.') : [$prefix . $name])];
    }

    return $found;
};
foreach (['encryption', 'encryption_run', 'encryption_keys', 'sitepack'] as $part) {
    assert($keys($ru['larena_storage']['section'][$part]) === $keys($en['larena_storage']['section'][$part]), $part);
}
foreach ([StorageEncryptionPolicy::RUNNING, StorageEncryptionPolicy::FAILED, StorageEncryptionPolicy::DISABLE_UNCONFIRMED, StorageEncryptionPolicy::CHANGE_COMBINED,
    StorageEncryptionPolicy::NOT_ENABLED, StorageEncryptionPolicy::NOT_FAILED, StorageEncryptionPolicy::REWRITE_REQUIRED, StorageSectionGateway::ENCRYPTION_CONFIRM_REQUIRED,
    'storage_field_encryption_key_still_used', 'storage_field_encryption_record_corrupt', 'storage_field_encryption_interrupted'] as $code) {
    // Read as the running class answers it, not as its declaration says.
    $reasons = (new ReflectionClassConstant(StorageSectionGateway::class, 'ENCRYPTION_REASONS'))->getValue();
    assert(is_array($reasons) && in_array($code, $reasons, true), $code);
    foreach (['ru' => $ru, 'en' => $en] as $language => $words) {
        assert(trim((string) ($words['larena_storage']['section']['encryption']['errors'][$code] ?? '')) !== '', $language . ' ' . $code);
    }
}
foreach (StorageEncryptionRunState::FAILURE_REASONS as $code) {
    assert(trim((string) ($en['larena_storage']['section']['encryption_run']['reasons'][$code] ?? '')) !== '', $code);
}
$english = new StorageEncryptionRunState(new EncryptionRunTexts('en'));
$workbench->open(EncryptionRunPlainWorkbench::run('encrypt', 'running', ['note'], 1, 2));
$read23 = $reads->selected();
assert($english->notice($read23)['title'] === 'Encrypting the field «Заметка»: 1 of 2 processed');
$workbench->open(null);

echo "StorageFieldEncryptionRunAdminTest passed.\n";
