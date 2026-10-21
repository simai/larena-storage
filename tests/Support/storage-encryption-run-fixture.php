<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use Larena\Core\SitePack\SitePackArchiveReader;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\DurableQueueWorker;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Queue\Storage\DatabaseQueueStore;
use Larena\Secret\Contracts\DataKeyWrapper;
use Larena\Secret\Local\AppKeyDerivedDataKeyWrapper;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageWorkbenchEncryptionRun;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Encryption\StorageDataKeys;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Encryption\StorageFieldEncryptionJob;
use Larena\Storage\Encryption\StorageFieldEncryptionRuns;
use Larena\Storage\Encryption\StorageFieldEnvelope;
use Larena\Storage\Encryption\StorageFieldReveal;
use Larena\Storage\Encryption\StorageFieldSealer;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\SitePack\DatabaseStorageSitePack;
use Larena\Storage\SitePack\StorageSitePackExporter;
use Larena\Storage\View\StorageViewChecker;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewRebuilder;
use Larena\Storage\View\StorageViewRegistry;
use Larena\Storage\View\StorageViewSync;

require_once __DIR__ . '/storage-encryption-fixture.php';

/**
 * Encryption runs («Turning encryption on and off», «Keys», slice storage B of larena-specs
 * docs/architecture/2026-10-22-storage-field-encryption.md) on any connection: SQLite in
 * StorageFieldEncryptionRunTest, a small throwaway MySQL database in StorageFieldEncryptionRunMySqlTest.
 */

function storageEncryptionRunInstall(Connection $connection): void
{
    storageEncryptionInstall($connection);
    Schema::swap($connection->getSchemaBuilder());
    (require __DIR__ . '/../../database/migrations/platform/2026_10_25_000001_create_larena_storage_field_encryption_runs_table.php')->up();
}

/**
 * One installation's runtime over the connection: an encryption run above $inlineLimit rows goes to $encryptionJobs.
 *
 * @param array<string, list<string>> $denied
 * @param Closure(): DataKeyWrapper $wrapper
 * @return array{workbench: DatabaseStorageWorkbench, storage: VersionedStorage, views: StorageViewSync, localized: DatabaseLocalizedValues, keys: StorageDataKeys, reveal: StorageFieldReveal, events: SitePackCapturedEvents, access: SitePackImportAccess}
 */
function storageEncryptionRunRuntime(
    Connection $connection,
    StorageViewDdl $ddl,
    array $denied,
    Closure $wrapper,
    SitePackCapturedEvents $events,
    ?Closure $encryptionJobs = null,
    int $inlineLimit = DatabaseStorageWorkbench::INLINE_ENCRYPTION_RUN_LIMIT,
    ?Closure $fieldChangeJobs = null,
    int $inlineFieldChangeLimit = DatabaseStorageWorkbench::INLINE_FIELD_CHANGE_LIMIT,
): array {
    $properties = PropertyTypeRegistry::builtIns();
    $access = new SitePackImportAccess($denied);
    $views = new StorageViewSync($connection, new StorageViewRegistry($connection, 0.0), $ddl);
    $cursorKey = 'storage-encryption-run-fixture-cursor-key-32-bytes';
    $keys = new StorageDataKeys($connection, $wrapper);
    $sealer = new StorageFieldSealer($keys);
    $storage = new VersionedStorage($connection, $properties, $access, $events, $access, $cursorKey, null, $views, new StorageMetrics(), $sealer);
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
    $workbench = new DatabaseStorageWorkbench(
        $connection, $properties, $access, $access, $storage, $policies, $cursorKey, $events, $views,
        fieldChangeJobs: $fieldChangeJobs,
        inlineFieldChangeLimit: $inlineFieldChangeLimit,
        encryption: $sealer,
        encryptionJobs: $encryptionJobs,
        inlineEncryptionRunLimit: $inlineLimit,
    );

    return [
        'workbench' => $workbench,
        'storage' => $storage,
        'views' => $views,
        'localized' => new DatabaseLocalizedValues($connection, $properties, 'en', $events, null, $sealer),
        'keys' => $keys,
        'reveal' => new StorageFieldReveal($keys, $access, $events, $workbench),
        'events' => $events,
        'access' => $access,
    ];
}

/** No sentinel in any larena_storage_ table of the connection. @param list<string> $sentinels */
function storageEncryptionRunNoPlaintext(Connection $connection, array $sentinels, string $message): void
{
    $dump = storageEncryptionDatabaseDump($connection);
    foreach ($sentinels as $sentinel) {
        storageViewExpect(!str_contains($dump, $sentinel), $message . ': plaintext left in the database (' . substr($sentinel, 0, 14) . '…)');
    }
}

/**
 * Every version's content hash is over its stored values, every head's hash is its head version's, and the витрина
 * holds no stale, missing or extra row (before anything rebuilds it).
 */
function storageEncryptionRunHashes(Connection $connection, StorageViewSync $views, string $schemaId, string $message): void
{
    $normalizer = new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns());
    $hashes = [];
    foreach ($connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->get() as $row) {
        $values = json_decode((string) $row->values_json, true, 512, JSON_THROW_ON_ERROR);
        storageViewExpect(hash('sha256', $normalizer->canonicalJson($values)) === (string) $row->content_hash, $message . ': a version hash is not over its values');
        $hashes[$row->record_id . '#' . $row->revision] = (string) $row->content_hash;
    }
    foreach ($connection->table('larena_storage_records')->where('schema_id', $schemaId)->get() as $head) {
        storageViewExpect(($hashes[$head->record_id . '#' . $head->current_revision] ?? null) === (string) $head->current_hash, $message . ': a head hash is not its version\'s');
    }
    foreach ($connection->table('larena_storage_localized_values')->where('schema_id', $schemaId)->get() as $row) {
        // The hash is over the text the writer encoded; MySQL's JSON column gives the members back in its own order.
        $value = json_decode((string) $row->value_json, true);
        if (StorageFieldEnvelope::isEnvelope($value)) {
            $value = StorageFieldEnvelope::fromValue($value)->toArray();
        }
        storageViewExpect(hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === (string) $row->content_hash,
            $message . ': a translation hash is not over its value');
    }
    $report = (new StorageViewChecker($views))->check($schemaId);
    storageViewExpect($report['stale'] === 0 && $report['missing'] === 0 && $report['extra'] === 0, $message . ': the витрина disagrees: ' . json_encode($report));
}

/** The витрина is clean once its queued rebuild ran (here, inline). */
function storageEncryptionRunViewClean(StorageViewSync $views, string $schemaId, string $message): void
{
    $checker = new StorageViewChecker($views);
    $report = $checker->check($schemaId);
    if ($report['problems'] !== []) {
        (new StorageViewRebuilder($views))->rebuild($schemaId);
        $report = $checker->check($schemaId);
    }
    storageViewExpect($report['problems'] === [], $message . ': ' . json_encode($report));
}

/** Every envelope of the storage's versions and translations, as decoded arrays. @return list<array<string, mixed>> */
function storageEncryptionRunEnvelopes(Connection $connection, string $schemaId): array
{
    $envelopes = [];
    foreach ($connection->table('larena_storage_record_versions')->where('schema_id', $schemaId)->pluck('values_json') as $json) {
        foreach (json_decode((string) $json, true) as $value) {
            if (StorageFieldEnvelope::isEnvelope($value)) {
                $envelopes[] = $value;
            }
        }
    }
    foreach ($connection->table('larena_storage_localized_values')->where('schema_id', $schemaId)->pluck('value_json') as $json) {
        $value = json_decode((string) $json, true);
        if (StorageFieldEnvelope::isEnvelope($value)) {
            $envelopes[] = $value;
        }
    }

    return $envelopes;
}

/** @param list<array<string, mixed>> $fields @return array{structure_id: string, label: string, fields: list<array<string, mixed>>} */
function storageEncryptionRunDescriptor(string $structureId, array $fields, bool $confirmed = false): array
{
    return ['structure_id' => $structureId, 'label' => ucfirst(substr($structureId, 10)), 'fields' => $fields]
        + ($confirmed ? [DatabaseStorageWorkbench::DISABLE_CONFIRMATION => true] : []);
}

/** @param list<array<string, mixed>> $fields @param array<string, mixed> $change @return list<array<string, mixed>> */
function storageEncryptionRunWith(array $fields, string $key, array $change, bool $dropEncrypted = false): array
{
    foreach ($fields as $index => $field) {
        if ($field['key'] === $key) {
            $fields[$index] = array_merge($field, $change);
            if ($dropEncrypted) {
                unset($fields[$index]['encrypted']);
            }
        }
    }

    return $fields;
}

/**
 * The scenario. $full also runs the SitePack plaintext round trip and the queue job (SQLite).
 */
function storageEncryptionRunScenario(Connection $connection, StorageViewDdl $ddl, string $label, bool $full): void
{
    $admin = 'actor:admin:alpha';
    $editor = 'actor:editor:beta';
    $scope = 'scope:tenant-alpha';
    storageEncryptionRunInstall($connection);
    $denied = [
        $admin => [],
        $editor => [StorageFieldReveal::OPERATION, 'storage.encryption.manage', 'storage.encryption.explain'],
    ];
    $events = new SitePackCapturedEvents();
    $inline = storageEncryptionRunRuntime($connection, $ddl, $denied, storageEncryptionWrapper(), $events);
    $queuedRuns = [];
    $fieldChanges = [];
    $queued = storageEncryptionRunRuntime(
        $connection,
        $ddl,
        $denied,
        storageEncryptionWrapper(),
        $events,
        static function (string $runRef, string $stamp) use (&$queuedRuns): bool {
            $queuedRuns[] = $runRef;

            return true;
        },
        0,
        static function (string $changeRef) use (&$fieldChanges): bool {
            $fieldChanges[] = $changeRef;

            return true;
        },
        0,
    );
    $workbench = $inline['workbench'];
    $marker = 'SENTINEL' . bin2hex(random_bytes(5));
    $sentinels = [];
    $runRow = static fn (string $runRef): ?stdClass => $connection->table(StorageFieldEncryptionRuns::TABLE)->where('run_ref', $runRef)->first();

    // --- Enable on filled fields (inline): every revision and every translation is sealed. ---
    $staff = [
        storageEncryptionField('title', 10, 'string', ['max_length' => 200], true),
        storageEncryptionField('passport', 20, 'string', ['max_length' => 60], false, ['searchable' => false]),
        storageEncryptionField('salary', 30, 'integer', [], false),
        storageEncryptionField('notes', 40, 'text', [], false, ['localized' => true, 'searchable' => false]),
    ];
    $structure = $workbench->createStructure($scope, storageEncryptionRunDescriptor('workbench.staff', $staff), $admin);
    $schemaId = $structure->schema->schemaId;
    $records = [];
    for ($index = 1; $index <= 4; $index++) {
        $passport = $marker . '-P' . $index;
        $salary = 918273600 + $index;
        $record = $workbench->createRecord($scope, 'workbench.staff', ['title' => 'Person ' . $index, 'passport' => $passport, 'salary' => $salary], $admin);
        // A second and third revision: the history holds plaintext too.
        $record = $workbench->updateRecord($scope, 'workbench.staff', $record->recordId, 1, ['title' => 'Person ' . $index, 'passport' => $passport . 'b', 'salary' => $salary], $admin);
        $record = $workbench->updateRecord($scope, 'workbench.staff', $record->recordId, 2, ['title' => 'Person ' . $index . '.', 'passport' => $passport . 'b', 'salary' => $salary], $admin);
        $inline['localized']->write($schemaId, $record->recordId, 2, 'ru', ['notes' => $marker . '-N2-' . $index], ['notes'], $admin);
        $inline['localized']->write($schemaId, $record->recordId, 3, 'ru', ['notes' => $marker . '-N3-' . $index], ['notes'], $admin);
        array_push($sentinels, $passport, $passport . 'b', (string) $salary, $marker . '-N2-' . $index, $marker . '-N3-' . $index);
        $records[$index] = $record;
    }
    $listed = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.staff', [], null, [], 50), $admin);
    storageViewExpect(count($listed->items) === 4, $label . ': the list does not answer');
    storageViewExpect(str_contains(storageEncryptionDatabaseDump($connection), $marker . '-P1b'), $label . ': the plaintext is not where the test expects it');

    // An editor cannot switch; without the manage right nothing changes.
    $encrypted = storageEncryptionRunWith(storageEncryptionRunWith(storageEncryptionRunWith($staff, 'passport', ['encrypted' => true]), 'salary', ['encrypted' => true]), 'notes', ['encrypted' => true]);
    storageEncryptionDenied(static fn () => $workbench->updateStructure($scope, 'workbench.staff', $structure->version, storageEncryptionRunDescriptor('workbench.staff', $encrypted), $editor), $label . ': an editor switched encryption on');
    // One change seals or opens, and does nothing every record must pass besides.
    storageEncryptionRejects(static fn () => $workbench->migrateStructure($scope, 'workbench.staff', $structure->version, storageEncryptionRunDescriptor('workbench.staff', storageEncryptionRunWith($encrypted, 'title', ['constraints' => ['max_length' => 100]])), $admin),
        StorageEncryptionPolicy::CHANGE_COMBINED, $label . ': a switch combined with a field change');

    $structure = $workbench->updateStructure($scope, 'workbench.staff', $structure->version, storageEncryptionRunDescriptor('workbench.staff', $encrypted), $admin);
    storageViewExpect($structure->encryptionRun === null && !$structure->changing(), $label . ': a small enable did not end in the request: ' . json_encode($structure->encryptionRun?->toArray()));
    storageViewExpect(StorageEncryptionPolicy::encryptedKeys($structure->fields) === ['passport', 'salary', 'notes'], $label . ': the structure did not switch');
    $run = $connection->table(StorageFieldEncryptionRuns::TABLE)->where('schema_id', $schemaId)->orderByDesc('sequence')->first();
    storageViewExpect($run !== null && $run->state === 'done' && $run->mode === 'encrypt' && (int) $run->rewritten === 12 + 8 && (int) $run->total === 12 + 8,
        $label . ': the run did not rewrite every version and translation: ' . json_encode($run));
    storageEncryptionRunNoPlaintext($connection, $sentinels, $label . ' (enable)');
    storageEncryptionRunHashes($connection, $inline['views'], $schemaId, $label . ' (enable)');
    storageEncryptionRunViewClean($inline['views'], $schemaId, $label . ' (enable)');
    $started = $events->of('storage.field.encryption.started');
    $enabled = $events->of('storage.field.encryption.enabled');
    storageViewExpect(count($started) === 1 && count($enabled) === 1 && $enabled[0]->payload['rewritten'] === 20 && $enabled[0]->payload['fields'] === ['notes', 'passport', 'salary'],
        $label . ': the enable is not audited with its counts');
    foreach ($events->events as $event) {
        $payload = (string) json_encode($event->payload);
        storageViewExpect(!str_contains($payload, $marker) && !str_contains($payload, 'larena_enc') && !str_contains($payload, '9182736'), $label . ': ' . $event->type . ' carries a value');
    }
    // Reads are envelopes; the reveal gives the values back, for the head and for history.
    $read = $workbench->readRecord($scope, 'workbench.staff', $records[1]->recordId, $admin);
    storageViewExpect(StorageFieldEnvelope::isEnvelope($read->values['passport']) && StorageFieldEnvelope::isEnvelope($read->values['salary']), $label . ': a read after the run is plaintext');
    storageViewExpect(storageEncryptionSame($inline['reveal']->revealRecord($scope, 'workbench.staff', $records[1]->recordId, ['passport', 'salary'], $admin), ['passport' => $marker . '-P1b', 'salary' => 918273601]),
        $label . ': the reveal after the run');
    $history = $workbench->recordHistory($scope, 'workbench.staff', $records[2]->recordId, $admin);
    $oldest = $history[count($history) - 1];
    storageViewExpect($oldest->revision === 1 && StorageFieldEnvelope::isEnvelope($oldest->values['passport']), $label . ': history was not sealed');
    storageViewExpect($inline['reveal']->revealValues($admin, $schemaId, $records[2]->recordId, ['passport' => $oldest->values['passport']]) === ['passport' => $marker . '-P2'], $label . ': an old revision does not reveal');
    $note = $inline['localized']->read($schemaId, $records[3]->recordId, 2, 'ru')['notes']->value;
    storageViewExpect($inline['reveal']->revealValues($admin, $schemaId, $records[3]->recordId, ['notes' => $note], 'ru') === ['notes' => $marker . '-N2-3'], $label . ': an old translation does not reveal');
    storageEncryptionRejects(static fn () => $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.staff', ['passport' => ['operator' => 'eq', 'value' => 'x']], null, [], 50), $admin),
        StorageEncryptionPolicy::FILTER_UNSUPPORTED, $label . ': a filter by a field switched on');
    // A run that ended is not run again.
    storageViewExpect($workbench->runFieldEncryption((string) $run->run_ref)['state'] === 'done', $label . ': a done run ran again');

    // --- Enable in the background: queued, masked reads, refusals, interruption, resume, writes meanwhile. ---
    $qb = $queued['workbench'];
    $ledgerFields = [
        storageEncryptionField('title', 10, 'string', ['max_length' => 200], true),
        storageEncryptionField('amount', 20, 'integer'),
        storageEncryptionField('memo', 30, 'string', ['max_length' => 100], false, ['searchable' => false]),
    ];
    $ledger = $qb->createStructure($scope, storageEncryptionRunDescriptor('workbench.ledger', $ledgerFields), $admin);
    $ledgerSchema = $ledger->schema->schemaId;
    $entries = [];
    for ($index = 1; $index <= 5; $index++) {
        $entry = $qb->createRecord($scope, 'workbench.ledger', ['title' => 'Entry ' . $index, 'amount' => 827364500 + $index, 'memo' => $marker . '-M' . $index], $admin);
        $entries[$index] = $qb->updateRecord($scope, 'workbench.ledger', $entry->recordId, 1, ['title' => 'Entry ' . $index, 'amount' => 827364500 + $index, 'memo' => $marker . '-M' . $index . 'x'], $admin);
        array_push($sentinels, (string) (827364500 + $index), $marker . '-M' . $index, $marker . '-M' . $index . 'x');
    }
    $sealedLedger = storageEncryptionRunWith(storageEncryptionRunWith($ledgerFields, 'amount', ['encrypted' => true]), 'memo', ['encrypted' => true]);
    $ledger = $qb->updateStructure($scope, 'workbench.ledger', $ledger->version, storageEncryptionRunDescriptor('workbench.ledger', $sealedLedger), $admin);
    $runRef = (string) end($queuedRuns);
    storageViewExpect($ledger->encryptionRun !== null && $ledger->encryptionRun->state === StorageWorkbenchEncryptionRun::STATE_QUEUED && $ledger->encryptionRun->runRef === $runRef
        && $ledger->changing() && $ledger->encryptionRun->total === 10, $label . ': a queued run is not on the structure: ' . json_encode($ledger->encryptionRun?->toArray()));
    // The structure takes no other change, no field change, no archive, no rotation meanwhile.
    storageEncryptionRejects(static fn () => $qb->updateStructure($scope, 'workbench.ledger', $ledger->version, storageEncryptionRunDescriptor('workbench.ledger', array_merge($sealedLedger, [storageEncryptionField('extra', 40, 'string')])), $admin),
        StorageEncryptionPolicy::RUNNING, $label . ': a change beside a running run');
    storageEncryptionRejects(static fn () => $qb->migrateStructure($scope, 'workbench.ledger', $ledger->version, storageEncryptionRunDescriptor('workbench.ledger', storageEncryptionRunWith($sealedLedger, 'title', ['constraints' => ['max_length' => 150]])), $admin),
        StorageEncryptionPolicy::RUNNING, $label . ': a field change beside a running run');
    storageEncryptionRejects(static fn () => $qb->archiveStructure($scope, 'workbench.ledger', $ledger->version, $admin), StorageEncryptionPolicy::RUNNING, $label . ': an archive beside a running run');
    storageEncryptionRejects(static fn () => $qb->rotateEncryptionKey($scope, 'workbench.ledger', $admin), StorageEncryptionPolicy::RUNNING, $label . ': a rotation beside a running run');
    storageEncryptionRejects(static fn () => $qb->retryEncryptionRun($scope, 'workbench.ledger', $admin), StorageEncryptionPolicy::NOT_FAILED, $label . ': a retry of a run that did not fail');
    // Plaintext still stored reads as an envelope (masked), reveals, and filters are refused at once.
    $masked = $qb->readRecord($scope, 'workbench.ledger', $entries[1]->recordId, $admin);
    storageViewExpect(StorageFieldEnvelope::isEnvelope($masked->values['amount']) && StorageFieldEnvelope::isEnvelope($masked->values['memo']), $label . ': plaintext read in plaintext while the run is queued');
    storageViewExpect(str_contains((string) $connection->table('larena_storage_record_versions')->where('record_id', $entries[1]->recordId)->where('revision', 2)->value('values_json'), $marker . '-M1x'),
        $label . ': the queued run rewrote before it ran');
    storageViewExpect(storageEncryptionSame($queued['reveal']->revealRecord($scope, 'workbench.ledger', $entries[1]->recordId, ['amount'], $admin), ['amount' => 827364501]), $label . ': a masked value does not reveal');
    $page = $qb->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.ledger', [], null, [], 50), $admin);
    foreach ($page->items as $item) {
        storageViewExpect(StorageFieldEnvelope::isEnvelope($item->values['memo']), $label . ': a list shows plaintext while the run is queued');
    }
    storageEncryptionRejects(static fn () => $qb->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.ledger', [], null, [['field' => 'amount', 'direction' => 'asc']], 50), $admin),
        StorageEncryptionPolicy::SORT_UNSUPPORTED, $label . ': a sort by a field being sealed');
    // An editor saves the masked values back unchanged: the new revision is sealed and keeps the value.
    $saved = $qb->updateRecord($scope, 'workbench.ledger', $entries[1]->recordId, 2, ['title' => 'Entry 1 edited'] + array_intersect_key($masked->values, ['amount' => true, 'memo' => true]), $editor);
    storageViewExpect(!str_contains((string) $connection->table('larena_storage_record_versions')->where('record_id', $entries[1]->recordId)->where('revision', 3)->value('values_json'), $marker),
        $label . ': a write during the run stored plaintext');
    storageViewExpect(storageEncryptionSame($queued['reveal']->revealRecord($scope, 'workbench.ledger', $saved->recordId, ['amount', 'memo'], $admin), ['amount' => 827364501, 'memo' => $marker . '-M1x']),
        $label . ': the masked values sent back lost their value');
    // The walk stops after its first chunk (a timeout, a crash), and the position is kept.
    $calls = 0;
    try {
        $qb->runFieldEncryption($runRef, 2, static function () use (&$calls): void {
            if (++$calls === 2) {
                throw new RuntimeException('storage_encryption_test_interrupted');
            }
        });
        throw new LogicException('the run was not interrupted');
    } catch (RuntimeException $exception) {
        storageViewExpect($exception->getMessage() === 'storage_encryption_test_interrupted', $label . ': ' . $exception->getMessage());
    }
    $stopped = $runRow($runRef);
    storageViewExpect($stopped !== null && $stopped->state === 'running' && $stopped->phase === 'versions' && $stopped->cursor !== null && (int) $stopped->rewritten > 0,
        $label . ': the interrupted run did not keep its position: ' . json_encode($stopped));
    $sealedSoFar = (int) $stopped->rewritten;
    // Writes go on between the walk's chunks: a new record, and a record whose history the walk did not reach yet.
    $late = $qb->createRecord($scope, 'workbench.ledger', ['title' => 'Late', 'amount' => 736455001, 'memo' => $marker . '-LATE'], $admin);
    array_push($sentinels, '736455001', $marker . '-LATE');
    $writes = 0;
    $result = $qb->runFieldEncryption($runRef, 2, static function () use (&$writes, $qb, $scope, $admin, $entries, $marker, &$sentinels): void {
        if (++$writes === 1) {
            $qb->updateRecord($scope, 'workbench.ledger', $entries[5]->recordId, 2, ['title' => 'Entry 5 again', 'amount' => 736455002, 'memo' => $marker . '-M5y'], $admin);
            array_push($sentinels, '736455002', $marker . '-M5y');
        }
    });
    storageViewExpect($result['state'] === 'done' && $result['resumed'] === true && $result['rewritten'] >= $sealedSoFar, $label . ': the resumed run did not end: ' . json_encode($result));
    $ledger = $qb->readStructure($scope, 'workbench.ledger', $admin);
    storageViewExpect($ledger->encryptionRun === null && !$ledger->changing(), $label . ': a done run is still on the structure');
    storageEncryptionRunNoPlaintext($connection, $sentinels, $label . ' (resumed enable)');
    storageEncryptionRunHashes($connection, $queued['views'], $ledgerSchema, $label . ' (resumed enable)');
    storageEncryptionRunViewClean($queued['views'], $ledgerSchema, $label . ' (resumed enable)');
    storageViewExpect(storageEncryptionSame($queued['reveal']->revealRecord($scope, 'workbench.ledger', $entries[5]->recordId, ['amount', 'memo'], $admin), ['amount' => 736455002, 'memo' => $marker . '-M5y']),
        $label . ': the write during the run lost its value');
    storageViewExpect(storageEncryptionSame($queued['reveal']->revealRecord($scope, 'workbench.ledger', $late->recordId, ['amount'], $admin), ['amount' => 736455001]), $label . ': the late record');

    // --- A run that fails (the key does not unwrap here) stays failed, blocks changes, and resumes on retry. ---
    $tagsFields = [
        storageEncryptionField('title', 10, 'string', ['max_length' => 200], true),
        storageEncryptionField('code', 20, 'string', ['max_length' => 60], false, ['searchable' => false]),
    ];
    $tags = $qb->createStructure($scope, storageEncryptionRunDescriptor('workbench.tags', $tagsFields), $admin);
    for ($index = 1; $index <= 3; $index++) {
        $qb->createRecord($scope, 'workbench.tags', ['title' => 'Tag ' . $index, 'code' => $marker . '-T' . $index], $admin);
        $sentinels[] = $marker . '-T' . $index;
    }
    $tags = $qb->updateStructure($scope, 'workbench.tags', $tags->version, storageEncryptionRunDescriptor('workbench.tags', storageEncryptionRunWith($tagsFields, 'code', ['encrypted' => true])), $admin);
    $tagsRun = (string) end($queuedRuns);
    $broken = storageEncryptionRunRuntime($connection, $ddl, $denied, storageEncryptionWrapper(STORAGE_ENCRYPTION_OTHER_KEY), $events);
    storageEncryptionRejects(static fn () => $broken['workbench']->runFieldEncryption($tagsRun, 2), StorageDataKeys::REASON_KEY_UNAVAILABLE, $label . ': a run under another APP_KEY');
    $tags = $qb->readStructure($scope, 'workbench.tags', $admin);
    storageViewExpect($tags->encryptionRun !== null && $tags->encryptionRun->state === 'failed' && $tags->encryptionRun->reason === StorageDataKeys::REASON_KEY_UNAVAILABLE && $tags->changing(),
        $label . ': the failed run is not on the structure: ' . json_encode($tags->encryptionRun?->toArray()));
    storageViewExpect(count($events->of('storage.field.encryption.failed')) === 1, $label . ': the failure is not audited');
    storageEncryptionRejects(static fn () => $qb->updateStructure($scope, 'workbench.tags', $tags->version, storageEncryptionRunDescriptor('workbench.tags', array_merge(storageEncryptionRunWith($tagsFields, 'code', ['encrypted' => true]), [storageEncryptionField('extra', 30, 'string')])), $admin),
        StorageEncryptionPolicy::FAILED, $label . ': a change beside a failed run');
    storageEncryptionDenied(static fn () => $qb->retryEncryptionRun($scope, 'workbench.tags', $editor), $label . ': an editor retried');
    $tags = $qb->retryEncryptionRun($scope, 'workbench.tags', $admin);
    storageViewExpect($tags->encryptionRun !== null && $tags->encryptionRun->state === 'queued' && (string) end($queuedRuns) === $tagsRun, $label . ': the retry did not queue the run again');
    storageViewExpect($qb->runFieldEncryption($tagsRun, 2)['state'] === 'done', $label . ': the retried run did not end');
    storageEncryptionRunNoPlaintext($connection, $sentinels, $label . ' (retried)');
    storageEncryptionRunHashes($connection, $queued['views'], $tags->schema->schemaId, $label . ' (retried)');

    // --- A field change and an encryption run never run side by side. ---
    $tags = $qb->readStructure($scope, 'workbench.tags', $admin);
    $retyped = storageEncryptionRunWith(storageEncryptionRunWith($tagsFields, 'code', ['encrypted' => true]), 'title', ['constraints' => ['max_length' => 150]]);
    $tags = $qb->migrateStructure($scope, 'workbench.tags', $tags->version, storageEncryptionRunDescriptor('workbench.tags', $retyped), $admin);
    storageViewExpect($tags->fieldChange !== null && $tags->fieldChange->running(), $label . ': the field change is not running in the background');
    storageEncryptionRejects(static fn () => $qb->rotateEncryptionKey($scope, 'workbench.tags', $admin), 'storage_workbench_field_change_running', $label . ': a rotation beside a field change');
    storageEncryptionRejects(static fn () => $qb->updateStructure($scope, 'workbench.tags', $tags->version, storageEncryptionRunDescriptor('workbench.tags', storageEncryptionRunWith($tagsFields, 'code', [], true), true), $admin),
        'storage_workbench_field_change_running', $label . ': a decrypt beside a field change');
    $qb->cancelFieldChange($scope, 'workbench.tags', $admin);

    // --- Disable (in the background): confirmation, envelopes shown and revealed meanwhile, plain writes, column back. ---
    $structure = $workbench->readStructure($scope, 'workbench.staff', $admin);
    $plainPassport = storageEncryptionRunWith($encrypted, 'passport', [], true);
    storageEncryptionRejects(static fn () => $qb->updateStructure($scope, 'workbench.staff', $structure->version, storageEncryptionRunDescriptor('workbench.staff', $plainPassport), $admin),
        StorageEncryptionPolicy::DISABLE_UNCONFIRMED, $label . ': a disable without its confirmation');
    storageEncryptionDenied(static fn () => $qb->updateStructure($scope, 'workbench.staff', $structure->version, storageEncryptionRunDescriptor('workbench.staff', $plainPassport, true), $editor), $label . ': an editor switched encryption off');
    storageEncryptionRejects(static fn () => $qb->updateStructure($scope, 'workbench.staff', $structure->version, storageEncryptionRunDescriptor('workbench.staff', storageEncryptionRunWith($plainPassport, 'title', ['encrypted' => true]), true), $admin),
        StorageEncryptionPolicy::CHANGE_COMBINED, $label . ': a change that seals and opens');
    $structure = $qb->updateStructure($scope, 'workbench.staff', $structure->version, storageEncryptionRunDescriptor('workbench.staff', $plainPassport, true), $admin);
    $decryptRun = (string) end($queuedRuns);
    storageViewExpect($structure->encryptionRun !== null && $structure->encryptionRun->mode === 'decrypt' && in_array('passport', $structure->viewPendingFields, true)
        && !in_array('passport', StorageEncryptionPolicy::encryptedKeys($structure->fields), true), $label . ': the disable did not switch first: ' . json_encode($structure->encryptionRun?->toArray()));
    $stillSealed = $qb->readRecord($scope, 'workbench.staff', $records[4]->recordId, $admin);
    storageViewExpect(StorageFieldEnvelope::isEnvelope($stillSealed->values['passport']), $label . ': an envelope not opened yet is not shown as one');
    storageViewExpect($queued['reveal']->revealRecord($scope, 'workbench.staff', $records[4]->recordId, ['passport'], $admin) === ['passport' => $marker . '-P4b'], $label . ': a field being opened does not reveal');
    storageEncryptionRejects(static fn () => $qb->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.staff', ['passport' => ['operator' => 'eq', 'value' => 'x']], null, [], 50), $admin),
        StorageEncryptionPolicy::FILTER_UNSUPPORTED, $label . ': a filter by a field still being opened');
    // An editor sends the envelope back: stored plain now.
    $written = $qb->updateRecord($scope, 'workbench.staff', $records[4]->recordId, 3, ['title' => 'Person 4 again'] + array_intersect_key($stillSealed->values, ['passport' => true, 'salary' => true]), $editor);
    storageViewExpect($written->values['passport'] === $marker . '-P4b' && StorageFieldEnvelope::isEnvelope($written->values['salary']), $label . ': a write during the disable is ' . json_encode(array_keys($written->values)));
    storageViewExpect($qb->runFieldEncryption($decryptRun, 3)['state'] === 'done', $label . ': the decrypt run did not end');
    foreach ([[$records[1]->recordId, 1, $marker . '-P1'], [$records[1]->recordId, 3, $marker . '-P1b'], [$records[3]->recordId, 2, $marker . '-P3b']] as [$recordId, $revision, $expected]) {
        $values = json_decode((string) $connection->table('larena_storage_record_versions')->where('record_id', $recordId)->where('revision', $revision)->value('values_json'), true);
        storageViewExpect(($values['passport'] ?? null) === $expected && StorageFieldEnvelope::isEnvelope($values['salary'] ?? null), $label . ': revision ' . $revision . ' was not opened in place');
    }
    storageEncryptionRunHashes($connection, $queued['views'], $schemaId, $label . ' (disable)');
    storageEncryptionRunViewClean($queued['views'], $schemaId, $label . ' (disable)');
    $registration = $queued['views']->registry()->find($schemaId, true);
    $column = null;
    foreach ($registration->layout->columns ?? [] as $candidate) {
        if ($candidate->fieldKey === 'passport' && $candidate->state === 'ready') {
            $column = $candidate->column;
        }
    }
    storageViewExpect($column !== null && (string) $connection->table($registration->tableName)->where('record_id', $records[2]->recordId)->value($column) === $marker . '-P2b',
        $label . ': the opened field did not get its витрина column back');
    $filtered = $qb->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.staff', ['passport' => ['operator' => 'eq', 'value' => $marker . '-P2b']], null, [], 50), $admin);
    storageViewExpect(count($filtered->items) === 1, $label . ': a filter by the opened field does not answer');
    storageViewExpect(count($events->of('storage.field.encryption.disabled')) === 1, $label . ': the disable is not audited');
    // Salary and notes stay sealed.
    storageEncryptionRunNoPlaintext($connection, array_values(array_filter($sentinels, static fn (string $sentinel): bool => !str_contains($sentinel, '-P'))), $label . ' (disable kept the others)');

    // --- Rotate: a new data key, the old values sealed again under it, the old key deleted. ---
    storageEncryptionRejects(static fn () => $qb->rotateEncryptionKey($scope, 'workbench.nothing', $admin), 'storage_workbench_structure_unknown', $label . ': a rotation of no structure');
    $plainStructure = $qb->createStructure($scope, storageEncryptionRunDescriptor('workbench.plain', [storageEncryptionField('title', 10, 'string', ['max_length' => 50])]), $admin);
    storageEncryptionRejects(static fn () => $qb->rotateEncryptionKey($scope, 'workbench.plain', $admin), StorageEncryptionPolicy::NOT_ENABLED, $label . ': a rotation without a data key');
    storageEncryptionDenied(static fn () => $qb->rotateEncryptionKey($scope, 'workbench.ledger', $editor), $label . ': an editor rotated');
    $oldKey = $queued['keys']->activeKeyId($ledgerSchema);
    $before = $qb->readRecord($scope, 'workbench.ledger', $entries[2]->recordId, $admin);
    storageViewExpect(StorageFieldEnvelope::fromValue($before->values['amount'])->keyId === $oldKey, $label . ': the ledger is not under its key');
    $ledger = $qb->rotateEncryptionKey($scope, 'workbench.ledger', $admin);
    $rotateRun = (string) end($queuedRuns);
    $newKey = $queued['keys']->activeKeyId($ledgerSchema);
    storageViewExpect($ledger->encryptionRun !== null && $ledger->encryptionRun->mode === 'rotate' && $newKey !== null && $newKey !== $oldKey
        && $connection->table(StorageDataKeys::TABLE)->where('key_id', $oldKey)->value('status') === 'retiring', $label . ': the rotation did not switch keys first');
    // A write that sends back a value of the retiring key seals it again under the active one.
    $rewritten = $qb->updateRecord($scope, 'workbench.ledger', $entries[2]->recordId, 2, ['title' => 'Entry 2 rotated'] + array_intersect_key($before->values, ['amount' => true, 'memo' => true]), $editor);
    storageViewExpect(StorageFieldEnvelope::fromValue($rewritten->values['amount'])->keyId === $newKey, $label . ': a reused envelope kept the retiring key');
    if ($full) {
        // The queue job runs the rotation.
        $clock = new StorageViewTestClock(new DateTimeImmutable('2026-10-22T00:00:00Z'));
        $registry = new JobTypeRegistry();
        $registry->register(StorageFieldEncryptionJob::descriptor(), new StorageFieldEncryptionJob($qb));
        $store = new DatabaseQueueStore($connection);
        (new DurableQueueDispatcher($registry, $store, $clock))->dispatch(StorageFieldEncryptionJob::request($rotateRun, 2, 'test'));
        $job = (new DurableQueueWorker($registry, $store, $clock))->runNext('encryption-worker');
        storageViewExpect($job !== null && $job->status->value === 'completed', $label . ': the job did not complete: ' . json_encode($job?->status));
    } else {
        $qb->runFieldEncryption($rotateRun, 2);
    }
    storageViewExpect($runRow($rotateRun)?->state === 'done', $label . ': the rotation run did not end');
    storageViewExpect($connection->table(StorageDataKeys::TABLE)->where('key_id', $oldKey)->exists() === false, $label . ': the retiring key was not deleted');
    foreach (storageEncryptionRunEnvelopes($connection, $ledgerSchema) as $envelope) {
        storageViewExpect($envelope['k'] === $newKey, $label . ': an envelope of the retired key is left');
    }
    storageEncryptionRunHashes($connection, $queued['views'], $ledgerSchema, $label . ' (rotate)');
    storageViewExpect(storageEncryptionSame($queued['reveal']->revealRecord($scope, 'workbench.ledger', $entries[3]->recordId, ['amount', 'memo'], $admin), ['amount' => 827364503, 'memo' => $marker . '-M3x']),
        $label . ': a rotated value does not reveal');
    $history = $qb->recordHistory($scope, 'workbench.ledger', $entries[3]->recordId, $admin);
    storageViewExpect($queued['reveal']->revealValues($admin, $ledgerSchema, $entries[3]->recordId, ['memo' => $history[count($history) - 1]->values['memo']]) === ['memo' => $marker . '-M3'],
        $label . ': a rotated old revision does not reveal');
    storageViewExpect(count($events->of('storage.field.encryption.rotated')) === 1, $label . ': the rotation is not audited');
    storageEncryptionRunNoPlaintext($connection, array_values(array_filter($sentinels, static fn (string $sentinel): bool => !str_contains($sentinel, '-P'))), $label . ' (rotate)');

    // --- Rewrap after an APP_KEY change: every data key moves to the new KEK in one go. ---
    $moved = new StorageDataKeys($connection, static fn (): DataKeyWrapper => new AppKeyDerivedDataKeyWrapper(STORAGE_ENCRYPTION_OTHER_KEY, [STORAGE_ENCRYPTION_APP_KEY]));
    $health = $moved->health();
    $keyCount = $connection->table(StorageDataKeys::TABLE)->count();
    storageViewExpect($health['state'] === 'warning' && $health['previous_kek_keys'] === $keyCount && in_array('storage_data_keys_previous_kek', $health['reasons'], true),
        $label . ': the health does not warn of a previous KEK: ' . json_encode($health));
    $onlyNew = new StorageDataKeys($connection, static fn (): DataKeyWrapper => new AppKeyDerivedDataKeyWrapper(STORAGE_ENCRYPTION_OTHER_KEY));
    storageViewExpect($onlyNew->health()['unwrappable_missing'] === $keyCount, $label . ': keys of a lost KEK are not reported');
    storageEncryptionRejects(static fn () => $onlyNew->rewrap(), StorageDataKeys::REASON_KEY_UNAVAILABLE, $label . ': a rewrap without the previous key');
    storageViewExpect($connection->table(StorageDataKeys::TABLE)->where('kek_id', '<>', (string) $connection->table(StorageDataKeys::TABLE)->value('kek_id'))->count() === 0, $label . ': a refused rewrap changed a key');
    storageViewExpect(array_filter($moved->describe($ledgerSchema), static fn (array $key): bool => $key['current_kek'] || !$key['unwrappable']) === [], $label . ': a key of the previous KEK is called current');
    $rewrap = $moved->rewrap();
    storageViewExpect(array_filter($moved->describe($ledgerSchema), static fn (array $key): bool => !$key['current_kek']) === [], $label . ': a rewrapped key is not on the current KEK');
    storageViewExpect($rewrap['rewrapped'] === $keyCount && $moved->health()['state'] === 'ok', $label . ': the rewrap did not move every key: ' . json_encode($rewrap));
    storageViewExpect($moved->rewrap()['rewrapped'] === 0, $label . ': a second rewrap moved keys');
    $after = storageEncryptionRunRuntime($connection, $ddl, $denied, static fn (): DataKeyWrapper => new AppKeyDerivedDataKeyWrapper(STORAGE_ENCRYPTION_OTHER_KEY), $events);
    storageViewExpect(storageEncryptionSame($after['reveal']->revealRecord($scope, 'workbench.ledger', $entries[4]->recordId, ['amount'], $admin), ['amount' => 827364504]), $label . ': a value does not open after the rewrap');
    $old = storageEncryptionRunRuntime($connection, $ddl, $denied, storageEncryptionWrapper(), $events);
    storageEncryptionRejects(static fn () => $old['reveal']->revealRecord($scope, 'workbench.ledger', $entries[4]->recordId, ['amount'], $admin), StorageDataKeys::REASON_KEY_UNAVAILABLE, $label . ': the old APP_KEY still opens after the rewrap');

    // --- SitePack: an export with the encrypted values in plaintext, audited, and an import that seals them again. ---
    if ($full) {
        storageEncryptionRunSitePack($connection, $after, $scope, $admin, $editor, $marker, $label);
    }

    // --- Purge: the runs go with the structure. ---
    $tags = $after['workbench']->readStructure($scope, 'workbench.tags', $admin);
    $after['workbench']->archiveStructure($scope, 'workbench.tags', $tags->version, $admin);
    $tags = $after['workbench']->readStructure($scope, 'workbench.tags', $admin);
    $after['workbench']->purgeStructure($scope, 'workbench.tags', $tags->version, $admin);
    storageViewExpect($connection->table(StorageFieldEncryptionRuns::TABLE)->where('schema_id', $tags->schema->schemaId)->count() === 0, $label . ': a purge left its runs');
    storageViewExpect($plainStructure->encryptionRun === null, $label . ': a structure without encryption shows a run');
}

/**
 * The SitePack plaintext option: denied without the decrypt right; with it the pack carries the values, says so in
 * its manifest, the start is audited, and an import into another scope seals them with that storage's data key.
 *
 * @param array{workbench: DatabaseStorageWorkbench, storage: VersionedStorage, views: StorageViewSync, localized: DatabaseLocalizedValues, keys: StorageDataKeys, reveal: StorageFieldReveal, events: SitePackCapturedEvents, access: SitePackImportAccess} $runtime
 */
function storageEncryptionRunSitePack(Connection $connection, array $runtime, string $scope, string $admin, string $editor, string $marker, string $label): void
{
    $directory = sys_get_temp_dir() . '/larena-storage-encryption-run-sitepack-' . bin2hex(random_bytes(6));
    $clock = new SitePackFixtureClock(new DateTimeImmutable('2026-10-22T09:00:00Z'));
    $sitePack = new DatabaseStorageSitePack(
        database: $connection,
        workbench: $runtime['workbench'],
        storage: $runtime['storage'],
        propertyTypes: PropertyTypeRegistry::builtIns(),
        authorizer: $runtime['access'],
        scopes: $runtime['access'],
        directory: $directory,
        files: new SitePackImportFiles(),
        events: $runtime['events'],
        siteOf: static fn (string $actor): string => 'site:main',
        clock: $clock->closure(),
        chunk: 2,
        fileChunk: 1,
        localized: $runtime['localized'],
        siteLocales: static fn (string $siteRef): array => ['en', 'ru'],
        keys: $runtime['keys'],
    );
    try {
        storageEncryptionDenied(static fn () => $sitePack->startExport($scope, 'workbench.ledger', $editor, null, false, true), $label . ': an editor exported plaintext');
        $started = $sitePack->startExport($scope, 'workbench.ledger', $admin, 'corr-plain', false, true);
        storageViewExpect(count($runtime['events']->of('storage.sitepack.export_decrypted')) === 1, $label . ': the plaintext export is not audited at its start');
        $exported = $sitePack->run($started->runRef, $admin);
        storageViewExpect($exported->state === 'done' && ($exported->counts['encrypted_values_decrypted'] ?? 0) > 0 && !isset($exported->counts['encrypted_values_omitted']),
            $label . ': the plaintext export did not finish: ' . json_encode($exported->toArray()));
        $done = $runtime['events']->of('storage.sitepack.exported');
        storageViewExpect(($done[count($done) - 1]->payload['contains_decrypted_values'] ?? null) === true, $label . ': the export event does not say it is decrypted');
        $archive = $sitePack->archive($exported->runRef, $admin)->path;
        $lines = '';
        foreach (sitePackArtifacts($archive) as $bytes) {
            $lines .= $bytes;
        }
        storageViewExpect(str_contains($lines, $marker . '-M3x') && str_contains($lines, '827364503') && !str_contains($lines, 'larena_enc'), $label . ': the pack does not carry the plaintext');
        $reader = SitePackArchiveReader::open($archive);
        try {
            $annotations = $reader->envelope->annotations;
        } finally {
            $reader->close();
        }
        storageViewExpect(($annotations['contains_decrypted_values'] ?? null) === true, $label . ': the manifest does not say the pack is decrypted');
        // A default export still leaves them out.
        $omitting = $sitePack->run($sitePack->startExport($scope, 'workbench.ledger', $admin, null, false)->runRef, $admin);
        storageViewExpect(($omitting->counts['encrypted_values_omitted'] ?? 0) > 0, $label . ': a default export carries encrypted values');

        $copy = $directory . '-ledger.zip';
        copy($archive, $copy);
        $planned = sitePackImportPlan($sitePack, 'scope:tenant-beta', $copy, $admin, new StorageSitePackImportOptions(null, null, 'draft', [], true));
        $codes = sitePackImportCodes($planned->plan === null ? [] : $planned->plan->warnings);
        storageViewExpect(in_array('encrypted_values_decrypted', $codes, true) && !in_array('encrypted_values_omitted', $codes, true), $label . ': the plan does not say the values are sealed again: ' . json_encode($codes));
        $imported = sitePackImportApply($sitePack, $planned, $admin);
        storageViewExpect($imported->state === 'done', $label . ': the import did not finish: ' . json_encode($imported->toArray()));
        $target = $runtime['workbench']->readStructure('scope:tenant-beta', 'workbench.ledger', $admin);
        $targetKey = $runtime['keys']->activeKeyId($target->schema->schemaId);
        $sourceKey = $runtime['keys']->activeKeyId($runtime['workbench']->readStructure($scope, 'workbench.ledger', $admin)->schema->schemaId);
        storageViewExpect($targetKey !== null && $targetKey !== $sourceKey, $label . ': the imported storage has no key of its own');
        $page = $runtime['workbench']->listRecords(new StorageWorkbenchRecordQuery('scope:tenant-beta', 'workbench.ledger', [], null, [], 50), $admin);
        $found = false;
        foreach ($page->items as $item) {
            storageViewExpect(StorageFieldEnvelope::isEnvelope($item->values['amount'] ?? null) && StorageFieldEnvelope::fromValue($item->values['amount'])->keyId === $targetKey, $label . ': an imported value is not sealed with the target key');
            $revealed = $runtime['reveal']->revealRecord('scope:tenant-beta', 'workbench.ledger', $item->recordId, ['memo'], $admin);
            $found = $found || ($revealed['memo'] ?? null) === $marker . '-M3x';
        }
        storageViewExpect($found, $label . ': an imported value does not reveal');
        $raw = (string) json_encode($connection->table('larena_storage_record_versions')->where('schema_id', $target->schema->schemaId)->pluck('values_json')->all());
        storageViewExpect(!str_contains($raw, $marker), $label . ': the import stored plaintext');
    } finally {
        StorageSitePackExporter::wipe($directory);
        @unlink($directory . '-ledger.zip');
    }
}
