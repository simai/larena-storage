<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Schema;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Secret\Contracts\DataKeyWrapper;
use Larena\Secret\Exceptions\DataKeyUnavailable;
use Larena\Secret\Local\AppKeyDerivedDataKeyWrapper;
use Larena\Storage\Contracts\StorageRecordListQuery;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Encryption\StorageDataKeys;
use Larena\Storage\Encryption\StorageEncryptionExplain;
use Larena\Storage\Encryption\StorageEncryptionPolicy;
use Larena\Storage\Encryption\StorageFieldCipher;
use Larena\Storage\Encryption\StorageFieldEnvelope;
use Larena\Storage\Encryption\StorageFieldReveal;
use Larena\Storage\Encryption\StorageFieldSealer;
use Larena\Storage\Exceptions\LocalizedValueRejected;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\StorageRecordConverter;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Storage\SitePack\DatabaseStorageSitePack;
use Larena\Storage\View\StorageViewDdl;
use Larena\Storage\View\StorageViewRegistry;
use Larena\Storage\View\StorageViewSync;

require_once __DIR__ . '/storage-sitepack-import-fixture.php';

/**
 * Field encryption («Шифровать», larena-specs docs/architecture/2026-10-22-storage-field-encryption.md, slice storage
 * A) on any connection: SQLite in StorageFieldEncryptionTest, a throwaway MySQL database in
 * StorageFieldEncryptionMySqlTest.
 */

const STORAGE_ENCRYPTION_APP_KEY = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';
const STORAGE_ENCRYPTION_OTHER_KEY = 'base64:ZmVkY2JhOTg3NjU0MzIxMGZlZGNiYTk4NzY1NDMyMTA=';

function storageEncryptionInstall(Connection $connection): void
{
    sitePackImportInstall($connection);
    Schema::swap($connection->getSchemaBuilder());
    (require __DIR__ . '/../../database/migrations/platform/2026_10_24_000001_create_larena_storage_data_keys_table.php')->up();
}

/**
 * @param array<string, list<string>> $denied actor => denied operations
 * @param Closure(): DataKeyWrapper $wrapper
 * @return array{workbench: DatabaseStorageWorkbench, storage: VersionedStorage, views: StorageViewSync, localized: DatabaseLocalizedValues, keys: StorageDataKeys, reveal: StorageFieldReveal, explain: StorageEncryptionExplain, events: SitePackCapturedEvents, access: SitePackImportAccess}
 */
function storageEncryptionRuntime(Connection $connection, StorageViewDdl $ddl, array $denied, Closure $wrapper): array
{
    $properties = PropertyTypeRegistry::builtIns();
    $access = new SitePackImportAccess($denied);
    $events = new SitePackCapturedEvents();
    $views = new StorageViewSync($connection, new StorageViewRegistry($connection, 0.0), $ddl);
    $cursorKey = 'storage-encryption-fixture-cursor-key-32-bytes!!';
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
        encryption: $sealer,
    );
    $localized = new DatabaseLocalizedValues($connection, $properties, 'en', $events, null, $sealer);

    return [
        'workbench' => $workbench,
        'storage' => $storage,
        'views' => $views,
        'localized' => $localized,
        'keys' => $keys,
        'reveal' => new StorageFieldReveal($keys, $access, $events, $workbench),
        'explain' => new StorageEncryptionExplain($workbench, $keys, $access),
        'events' => $events,
        'access' => $access,
    ];
}

function storageEncryptionWrapper(string $appKey = STORAGE_ENCRYPTION_APP_KEY): Closure
{
    return static fn (): DataKeyWrapper => new AppKeyDerivedDataKeyWrapper($appKey);
}

/** @param array<string, mixed> $options @return array<string, mixed> */
function storageEncryptionField(string $key, int $position, string $type, array $constraints = [], bool $required = false, array $options = []): array
{
    return storageViewField($key, $position, $type, $constraints, $required) + $options;
}

function storageEncryptionRejects(callable $call, string $reason, string $message): void
{
    try {
        $call();
    } catch (StorageRejected|LocalizedValueRejected|ReadContractRejected $exception) {
        storageViewExpect($exception->reasonCode === $reason, $message . ': expected ' . $reason . ', got ' . $exception->reasonCode);

        return;
    }
    throw new RuntimeException($message . ': expected rejection ' . $reason);
}

/** Equal in canonical form (the stored JSON sorts every map). */
function storageEncryptionSame(mixed $left, mixed $right): bool
{
    return StorageFieldCipher::canonicalJson($left) === StorageFieldCipher::canonicalJson($right);
}

function storageEncryptionDenied(callable $call, string $message): void
{
    try {
        $call();
    } catch (RuntimeException $exception) {
        storageViewExpect(!$exception instanceof StorageRejected && $exception->getMessage() === 'sitepack_import_fixture_denied', $message . ': ' . $exception->getMessage());

        return;
    }
    throw new RuntimeException($message . ': not denied');
}

/** Every string inside a value (arrays walked, objects skipped). @return list<string> */
function storageEncryptionStrings(mixed $value, int $depth = 0): array
{
    if (is_string($value)) {
        return [$value];
    }
    if (!is_array($value) || $depth > 12) {
        return [];
    }
    $strings = [];
    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $strings[] = $key;
        }
        array_push($strings, ...storageEncryptionStrings($item, $depth + 1));
    }

    return $strings;
}

/** Whether an exception's message, chain or trace arguments hold $needle. */
function storageEncryptionLeaks(Throwable $exception, string $needle): bool
{
    for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
        if (str_contains($current->getMessage(), $needle) || str_contains($current->getTraceAsString(), $needle)) {
            return true;
        }
        foreach ($current->getTrace() as $frame) {
            foreach (storageEncryptionStrings($frame['args'] ?? []) as $string) {
                if (str_contains($string, $needle)) {
                    fwrite(STDERR, 'leak in ' . ($frame['class'] ?? '') . '::' . $frame['function'] . PHP_EOL);

                    return true;
                }
            }
        }
    }

    return false;
}

/** Every value stored in a table, as one string. */
function storageEncryptionDump(Connection $connection, string $table): string
{
    $dump = '';
    foreach ($connection->table($table)->get() as $row) {
        $dump .= json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    return $dump;
}

/** Every table of the connection whose name starts with larena_storage_, dumped. */
function storageEncryptionDatabaseDump(Connection $connection): string
{
    $dump = '';
    $mysql = $connection->getDriverName() !== 'sqlite';
    foreach ($connection->getSchemaBuilder()->getTables() as $table) {
        // This database only: a server's other databases are never read.
        if ($mysql && ($table['schema'] ?? null) !== $connection->getDatabaseName()) {
            continue;
        }
        $name = (string) $table['name'];
        if (str_starts_with($name, 'larena_storage_')) {
            $dump .= storageEncryptionDump($connection, $name);
        }
    }

    return $dump;
}

/**
 * The scenario. $full also runs the unit checks and the SitePack round trip (SQLite); MySQL runs the write, read,
 * reuse, refusal and purge paths.
 */
function storageEncryptionScenario(Connection $connection, StorageViewDdl $ddl, string $label, bool $full): void
{
    $admin = 'actor:admin:alpha';
    $editor = 'actor:editor:beta';
    $scope = 'scope:tenant-alpha';
    storageEncryptionInstall($connection);
    $denied = [
        $admin => [],
        // An editor writes and replaces values without reading them; no decrypt, no encryption manage.
        $editor => [StorageFieldReveal::OPERATION, 'storage.encryption.manage', StorageEncryptionExplain::OPERATION],
    ];
    $runtime = storageEncryptionRuntime($connection, $ddl, $denied, storageEncryptionWrapper());
    $workbench = $runtime['workbench'];
    $storage = $runtime['storage'];
    $events = $runtime['events'];
    $marker = 'MARKER-SECRET-' . bin2hex(random_bytes(4));

    // --- Policy refusals on the structure. ---
    $base = static fn (array $field): array => ['structure_id' => 'workbench.refused', 'label' => 'Refused', 'fields' => [
        storageEncryptionField('title', 10, 'string', ['max_length' => 200]),
        $field,
    ]];
    foreach ([
        [storageEncryptionField('kind', 20, 'choice', ['options' => [['value' => 'a', 'label' => 'A']]], false, ['encrypted' => true]), StorageEncryptionPolicy::TYPE_UNSUPPORTED],
        [storageEncryptionField('flag', 20, 'boolean', [], false, ['encrypted' => true]), StorageEncryptionPolicy::TYPE_UNSUPPORTED],
        [storageEncryptionField('cover', 20, 'file', [], false, ['encrypted' => true]), StorageEncryptionPolicy::TYPE_UNSUPPORTED],
        [array_merge(storageEncryptionField('secret', 20, 'string'), ['visibility' => 'public', 'encrypted' => true]), StorageEncryptionPolicy::VISIBILITY_REFUSED],
        [array_merge(storageEncryptionField('secret', 20, 'string'), ['visibility' => 'protected', 'encrypted' => true]), StorageEncryptionPolicy::VISIBILITY_REFUSED],
        [storageEncryptionField('secret', 20, 'string', [], false, ['encrypted' => true, 'searchable' => true]), StorageEncryptionPolicy::SEARCHABLE_REFUSED],
        [storageEncryptionField('secret', 20, 'string', ['max_length' => 100], false, ['encrypted' => true, 'indexed' => true]), StorageEncryptionPolicy::INDEXED_REFUSED],
        [storageEncryptionField('secret', 20, 'string', ['unique' => true], false, ['encrypted' => true]), StorageEncryptionPolicy::UNIQUE_UNSUPPORTED],
    ] as [$field, $reason]) {
        storageEncryptionRejects(static fn () => $workbench->createStructure($scope, $base($field), $admin), $reason, $label . ': ' . $reason);
    }
    storageEncryptionRejects(static fn () => $workbench->createStructure($scope, $base(storageEncryptionField('secret', 20, 'string', [], false, ['encrypted' => 'yes'])), $admin),
        'storage_workbench_structure_field_invalid', $label . ': a non-boolean encrypted flag');
    storageEncryptionRejects(static fn () => $storage->registerSchemaVersion([
        'schema_id' => 'content.secret', 'owner_package' => 'larena/content',
        'fields' => [['key' => 'secret', 'type' => 'string', 'type_version' => 1, 'required' => false, 'visibility' => 'admin', 'constraints' => [], 'encrypted' => true]],
    ], null, $admin), StorageEncryptionPolicy::OWNER_UNSUPPORTED, $label . ': another owner');
    storageEncryptionDenied(static fn () => $workbench->createStructure($scope, $base(storageEncryptionField('secret', 20, 'string', [], false, ['encrypted' => true])), $editor),
        $label . ': an editor created an encrypted field without storage.encryption.manage');
    storageViewExpect($connection->table('larena_storage_schemas')->count() === 0, $label . ': a refused structure wrote a schema');
    // A false flag normalizes as absent: the definition hashes as one that never said it.
    $plain = $workbench->createStructure($scope, ['structure_id' => 'workbench.plain', 'label' => 'Plain', 'fields' => [
        storageEncryptionField('title', 10, 'string', ['max_length' => 200], false, ['encrypted' => false]),
    ]], $editor);
    storageViewExpect(!array_key_exists('encrypted', $plain->fields[0]), $label . ': a false encrypted flag was kept');
    storageViewExpect($connection->table(StorageDataKeys::TABLE)->count() === 0, $label . ': a structure without encrypted fields got a data key');

    // --- A structure with encrypted fields. ---
    $structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.people', 'label' => 'People', 'fields' => [
        storageEncryptionField('title', 10, 'string', ['max_length' => 200], true),
        storageEncryptionField('passport', 20, 'string', ['max_length' => 40], true, ['encrypted' => true]),
        storageEncryptionField('salary', 30, 'integer', ['min' => 0], false, ['encrypted' => true]),
        storageEncryptionField('born', 40, 'date', [], false, ['encrypted' => true]),
        storageEncryptionField('notes', 50, 'text', [], false, ['encrypted' => true, 'localized' => true, 'searchable' => false]),
    ]], $admin);
    $schemaId = $structure->schema->schemaId;
    $schema = $storage->schemaVersion($structure->schema);
    storageViewExpect(StorageEncryptionPolicy::encryptedKeys($schema->fields) === ['passport', 'salary', 'born', 'notes'], $label . ': the schema does not carry the encrypted flags');
    storageViewExpect(StorageEncryptionPolicy::encryptedKeys($structure->fields) === ['passport', 'salary', 'born', 'notes'], $label . ': the structure does not mirror the encrypted flags');
    $keys = $connection->table(StorageDataKeys::TABLE)->where('schema_id', $schemaId)->get()->all();
    storageViewExpect(count($keys) === 1 && $keys[0]->status === 'active' && (int) $keys[0]->active_slot === 1, $label . ': the structure has no active data key');

    // Validation runs before encryption, and a refused write writes nothing.
    storageEncryptionRejects(static fn () => $workbench->createRecord($scope, 'workbench.people', ['title' => 'Too long', 'passport' => str_repeat('x', 41)], $admin), 'storage_record_field_invalid', $label . ': a too long secret');
    storageEncryptionRejects(static fn () => $workbench->createRecord($scope, 'workbench.people', ['title' => 'Typed', 'passport' => 'P1', 'salary' => 'lots'], $admin), 'storage_record_field_invalid', $label . ': a wrongly typed secret');
    storageEncryptionRejects(static fn () => $workbench->createRecord($scope, 'workbench.people', ['title' => 'Missing'], $admin), 'storage_record_required_field_missing', $label . ': a missing required secret');
    storageViewExpect($connection->table('larena_storage_records')->where('schema_id', $schemaId)->count() === 0, $label . ': a refused write wrote');

    $ada = $workbench->createRecord($scope, 'workbench.people', ['title' => 'Ada', 'passport' => $marker . '-P', 'salary' => 4200, 'born' => '1815-12-10'], $editor);
    foreach (['passport', 'salary', 'born'] as $key) {
        storageViewExpect(StorageFieldEnvelope::isEnvelope($ada->values[$key]), $label . ': ' . $key . ' was returned in plaintext');
        StorageFieldEnvelope::fromValue($ada->values[$key]);
    }
    storageViewExpect($ada->values['title'] === 'Ada', $label . ': a plain field was sealed');
    $raw = (string) $connection->table('larena_storage_record_versions')->where('record_id', $ada->recordId)->value('values_json');
    storageViewExpect(!str_contains($raw, $marker) && !str_contains($raw, '4200') && !str_contains($raw, '1815'), $label . ': values_json holds plaintext');
    $read = $workbench->readRecord($scope, 'workbench.people', $ada->recordId, $admin);
    storageViewExpect(storageEncryptionSame($read->values, $ada->values), $label . ': a read is not the stored envelopes');

    // The витрина: no column, no search text, the title from the plain field.
    $grace = $workbench->createRecord($scope, 'workbench.people', ['title' => 'Grace', 'passport' => $marker . '-G'], $admin);
    $page = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.people', [], null, [], 50), $admin);
    storageViewExpect(count($page->items) === 2, $label . ': the list does not answer');
    foreach ($page->items as $item) {
        storageViewExpect(StorageFieldEnvelope::isEnvelope($item->values['passport']), $label . ': a list returned plaintext');
    }
    $registration = $runtime['views']->registry()->find($schemaId, true);
    storageViewExpect($registration !== null, $label . ': no витрина');
    foreach ($registration->layout->columns as $column) {
        storageViewExpect(!in_array($column->fieldKey, ['passport', 'salary', 'born', 'notes'], true), $label . ': the витрина has a column for ' . $column->fieldKey);
    }
    storageViewExpect(!in_array('notes', $registration->layout->searchFields ?? [], true), $label . ': an encrypted field is a search field');
    foreach ([$registration->tableName, $registration->tableName . '_s'] as $table) {
        $dump = storageEncryptionDump($connection, $table);
        storageViewExpect(!str_contains($dump, $marker) && !str_contains($dump, 'larena_enc'), $label . ': ' . $table . ' holds an encrypted value');
    }
    storageViewExpect((string) $connection->table($registration->tableName)->where('record_id', $ada->recordId)->value('title') === 'Ada', $label . ': the title is not the plain field');

    // Lists refuse to filter, sort or search by an encrypted field.
    storageEncryptionRejects(static fn () => $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.people', ['passport' => ['operator' => 'eq', 'value' => 'x']], null, [], 50), $admin),
        StorageEncryptionPolicy::FILTER_UNSUPPORTED, $label . ': a filter by an encrypted field');
    storageEncryptionRejects(static fn () => $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.people', [], null, [['field' => 'salary', 'direction' => 'asc']], 50), $admin),
        StorageEncryptionPolicy::SORT_UNSUPPORTED, $label . ': a sort by an encrypted field');
    storageEncryptionRejects(static fn () => $storage->listCurrentRecords(new StorageRecordListQuery($schemaId, ['passport' => ['operator' => 'eq', 'value' => 'x']], 10), $admin),
        StorageEncryptionPolicy::FILTER_UNSUPPORTED, $label . ': a record list filter by an encrypted field');
    $vault = $workbench->createStructure($scope, ['structure_id' => 'workbench.vault', 'label' => 'Vault', 'fields' => [
        storageEncryptionField('code', 10, 'string', ['max_length' => 100], false, ['encrypted' => true]),
        storageEncryptionField('qty', 20, 'integer'),
    ]], $admin);
    $vaultRecord = $workbench->createRecord($scope, 'workbench.vault', ['code' => $marker . '-V', 'qty' => 1], $admin);
    storageEncryptionRejects(static fn () => $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.vault', [], 'MARKER', [], 50), $admin),
        StorageEncryptionPolicy::SEARCH_UNSUPPORTED, $label . ': a search where only encrypted fields hold text');
    $vaultView = $runtime['views']->registry()->find($vault->schema->schemaId, true);
    storageViewExpect($vaultView !== null && $runtime['views']->projector()->title($vaultView->layout, $vaultRecord->values) === null,
        $label . ': a storage whose only text is encrypted has a title (the record id must stand in)');

    // --- Reuse: an unchanged value keeps its envelope; a changed one gets a new one. ---
    $envelope = $ada->values['passport'];
    $ada2 = $workbench->updateRecord($scope, 'workbench.people', $ada->recordId, 1, ['title' => 'Ada L.'] + array_intersect_key($ada->values, array_flip(['passport', 'salary', 'born'])), $editor);
    storageViewExpect(storageEncryptionSame($ada2->values['passport'], $envelope) && storageEncryptionSame($ada2->values['salary'], $ada->values['salary']), $label . ': an envelope sent back was not reused');
    $ada3 = $workbench->updateRecord($scope, 'workbench.people', $ada->recordId, 2, ['title' => 'Ada L.', 'passport' => $marker . '-P', 'salary' => 4200], $editor);
    storageViewExpect(storageEncryptionSame($ada3->values['passport'], $envelope) && storageEncryptionSame($ada3->values['salary'], $ada->values['salary']) && !array_key_exists('born', $ada3->values),
        $label . ': an unchanged plaintext did not reuse the previous envelope');
    $ada4 = $workbench->updateRecord($scope, 'workbench.people', $ada->recordId, 3, ['title' => 'Ada L.', 'passport' => $marker . '-P2', 'salary' => 4200], $editor);
    storageViewExpect(!storageEncryptionSame($ada4->values['passport'], $envelope) && storageEncryptionSame($ada4->values['salary'], $ada->values['salary']), $label . ': a changed value kept its envelope');
    $archived = $workbench->archiveRecord($scope, 'workbench.people', $ada->recordId, 4, $editor);
    $restored = $workbench->restoreRecord($scope, 'workbench.people', $ada->recordId, 5, $editor);
    storageViewExpect(storageEncryptionSame($archived->values, $ada4->values) && storageEncryptionSame($restored->values, $ada4->values) && $restored->contentHash === $ada4->contentHash,
        $label . ': archive or restore changed the stored values');
    // A revert: the values of revision 1, sent back as they were read, are stored as they were.
    $history = $workbench->recordHistory($scope, 'workbench.people', $ada->recordId, $admin);
    $first = $history[count($history) - 1];
    storageViewExpect($first->revision === 1, $label . ': the history has no first revision');
    $reverted = $workbench->updateRecord($scope, 'workbench.people', $ada->recordId, 6, $first->values, $editor);
    storageViewExpect(storageEncryptionSame($reverted->values, $first->values) && $reverted->contentHash === $first->contentHash, $label . ': a revert did not restore the first stored form');

    // Envelopes of another record, field or a tampered one do not open.
    storageEncryptionRejects(static fn () => $workbench->updateRecord($scope, 'workbench.people', $grace->recordId, 1, ['title' => 'Grace', 'passport' => $envelope], $editor),
        StorageFieldEnvelope::REASON_INVALID, $label . ': an envelope of another record');
    storageEncryptionRejects(static fn () => $workbench->updateRecord($scope, 'workbench.people', $ada->recordId, 7, ['title' => 'Ada', 'passport' => $envelope, 'born' => $envelope], $editor),
        StorageFieldEnvelope::REASON_INVALID, $label . ': an envelope of another field');
    $tampered = $envelope;
    $tampered['c'] = ($tampered['c'][0] === 'A' ? 'B' : 'A') . substr($tampered['c'], 1);
    storageEncryptionRejects(static fn () => $workbench->updateRecord($scope, 'workbench.people', $ada->recordId, 7, ['title' => 'Ada', 'passport' => $tampered], $editor),
        StorageFieldEnvelope::REASON_INVALID, $label . ': a tampered envelope');
    storageEncryptionRejects(static fn () => $workbench->updateRecord($scope, 'workbench.people', $ada->recordId, 7, ['title' => 'Ada', 'passport' => $envelope + ['x' => 1]], $editor),
        StorageFieldEnvelope::REASON_INVALID, $label . ': an envelope with an extra member');

    // --- Translations of an encrypted localized field. ---
    $localized = $runtime['localized'];
    $localized->write($schemaId, $ada->recordId, 7, 'ru', ['notes' => $marker . '-RU'], ['notes'], $editor);
    $storedNote = $connection->table('larena_storage_localized_values')->where('record_id', $ada->recordId)->where('locale', 'ru')->value('value_json');
    storageViewExpect(is_string($storedNote) && !str_contains($storedNote, $marker), $label . ': a translation is stored in plaintext');
    $note = $localized->read($schemaId, $ada->recordId, 7, 'ru')['notes']->value;
    storageViewExpect(StorageFieldEnvelope::isEnvelope($note), $label . ': a translation read is not an envelope');
    storageEncryptionRejects(static fn () => $localized->write($schemaId, $ada->recordId, 7, 'kk', ['notes' => $note], ['notes'], $editor),
        StorageFieldEnvelope::REASON_INVALID, $label . ': a translation envelope moved to another locale');

    // --- Reveal: the decrypt right, the audit, nothing but field keys in it. ---
    storageEncryptionDenied(static fn () => $runtime['reveal']->revealRecord($scope, 'workbench.people', $ada->recordId, ['passport'], $editor), $label . ': an editor revealed');
    storageViewExpect($events->of(StorageFieldReveal::EVENT) === [], $label . ': a denied reveal was audited as a reveal');
    storageEncryptionRejects(static fn () => $runtime['reveal']->revealRecord($scope, 'workbench.people', $ada->recordId, ['title'], $admin), StorageFieldReveal::REASON_INVALID, $label . ': a plain field revealed');
    $revealed = $runtime['reveal']->revealRecord($scope, 'workbench.people', $ada->recordId, ['passport', 'salary', 'born'], $admin, 'corr-reveal');
    storageViewExpect(storageEncryptionSame($revealed, ['passport' => $marker . '-P', 'salary' => 4200, 'born' => '1815-12-10']), $label . ': the reveal is ' . json_encode($revealed));
    $revealedNote = $runtime['reveal']->revealValues($admin, $schemaId, $ada->recordId, ['notes' => $note], 'ru');
    storageViewExpect($revealedNote === ['notes' => $marker . '-RU'], $label . ': a translation reveal');
    $reveals = $events->of(StorageFieldReveal::EVENT);
    storageViewExpect(count($reveals) === 2 && $reveals[0]->stream === 'encryption' && $reveals[0]->payload['revealed_fields'] === ['born', 'passport', 'salary']
        && $reveals[0]->payload['record_id'] === $ada->recordId && ($reveals[1]->payload['locale'] ?? null) === 'ru', $label . ': the reveal audit is off');
    (new Larena\Storage\Audit\StorageEncryptionAuditEventDescriptor(StorageFieldReveal::EVENT));

    // The audit sink saw no value and no ciphertext, of any event.
    $ciphertexts = [];
    foreach ([$envelope, $ada4->values['passport'], $note, $vaultRecord->values['code']] as $sealed) {
        $ciphertexts[] = $sealed['c'];
        $ciphertexts[] = $sealed['n'];
    }
    foreach ($events->events as $event) {
        $payload = json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        storageViewExpect(is_string($payload) && !str_contains($payload, $marker) && !str_contains($payload, 'larena_enc'), $label . ': ' . $event->type . ' carries a value');
        foreach ($ciphertexts as $ciphertext) {
            storageViewExpect(!str_contains($payload, $ciphertext), $label . ': ' . $event->type . ' carries a ciphertext');
        }
    }

    // --- Explain. ---
    $explained = $runtime['explain']->explain($scope, 'workbench.people', $admin);
    storageViewExpect($explained['available'] === true && $explained['encrypted_fields'] === ['passport', 'salary', 'born', 'notes']
        && count($explained['keys']) === 1 && $explained['keys'][0]['unwrappable'] === true && $explained['keys'][0]['status'] === 'active',
        $label . ': explain is ' . json_encode($explained));
    storageViewExpect(!str_contains((string) json_encode($explained), 'ciphertext') && !str_contains((string) json_encode($explained), (string) $keys[0]->wrapped_key), $label . ': explain carries key material');
    storageEncryptionDenied(static fn () => $runtime['explain']->explain($scope, 'workbench.people', $editor), $label . ': an editor explained');

    // --- Structure changes. ---
    $fields = $structure->fields;
    $byKey = static function (array $fields, string $key, array $change): array {
        foreach ($fields as $index => $field) {
            if ($field['key'] === $key) {
                $fields[$index] = array_merge($field, $change);
            }
        }

        return $fields;
    };
    $descriptor = static fn (array $fields): array => ['structure_id' => 'workbench.people', 'label' => 'People', 'fields' => $fields];
    $current = $workbench->readStructure($scope, 'workbench.people', $admin);
    storageEncryptionRejects(static fn () => $workbench->migrateStructure($scope, 'workbench.people', $current->version, $descriptor($byKey($fields, 'passport', ['type' => 'text', 'constraints' => []])), $admin),
        StorageEncryptionPolicy::CHANGE_REFUSED, $label . ': an encrypted field retyped');
    storageEncryptionRejects(static fn () => $workbench->migrateStructure($scope, 'workbench.people', $current->version, $descriptor($byKey($fields, 'passport', ['constraints' => ['max_length' => 30]])), $admin),
        StorageEncryptionPolicy::CHANGE_REFUSED, $label . ': constraints of an encrypted field with values');
    $off = $byKey($fields, 'passport', []);
    foreach ($off as $index => $field) {
        if ($field['key'] === 'passport') {
            unset($off[$index]['encrypted']);
        }
    }
    storageEncryptionRejects(static fn () => $workbench->updateStructure($scope, 'workbench.people', $current->version, $descriptor(array_values($off)), $admin),
        StorageEncryptionPolicy::REWRITE_REQUIRED, $label . ': encryption switched off a field with values');
    storageEncryptionRejects(static fn () => $workbench->updateStructure($scope, 'workbench.people', $current->version, $descriptor($byKey($fields, 'title', ['encrypted' => true, 'visibility' => 'admin'])), $admin),
        StorageEncryptionPolicy::REWRITE_REQUIRED, $label . ': encryption switched on a field with values');
    // An empty field switches both ways; adding an encrypted field needs the manage right.
    $withNew = array_merge($fields, [storageEncryptionField('pin', 60, 'string')]);
    storageEncryptionDenied(static fn () => $workbench->updateStructure($scope, 'workbench.people', $current->version, $descriptor($byKey($withNew, 'pin', ['encrypted' => true])), $editor),
        $label . ': an editor added an encrypted field');
    $current = $workbench->updateStructure($scope, 'workbench.people', $current->version, $descriptor($withNew), $admin);
    $current = $workbench->updateStructure($scope, 'workbench.people', $current->version, $descriptor($byKey($withNew, 'pin', ['encrypted' => true])), $admin);
    storageViewExpect(in_array('pin', StorageEncryptionPolicy::encryptedKeys($storage->schemaVersion($current->schema)->fields), true), $label . ': an empty field was not switched on');
    $withPin = $workbench->createRecord($scope, 'workbench.people', ['title' => 'Pinned', 'passport' => 'X1', 'pin' => $marker . '-PIN'], $admin);
    storageViewExpect(StorageFieldEnvelope::isEnvelope($withPin->values['pin']), $label . ': a switched field is written in plaintext');
    $layout = $runtime['views']->registry()->find($schemaId, true)?->layout;
    foreach ($layout === null ? [] : $layout->columns as $column) {
        storageViewExpect($column->fieldKey !== 'pin' || $column->state === 'dropping', $label . ': a switched field kept its витрина column');
    }
    storageEncryptionRejects(static fn () => $workbench->updateStructure($scope, 'workbench.people', $current->version, $descriptor($withNew), $admin),
        StorageEncryptionPolicy::REWRITE_REQUIRED, $label . ': a switched field with a value switched back');

    // --- Fail closed: without a key-encryption key, or with another APP_KEY, nothing is written. ---
    $countBefore = $connection->table('larena_storage_record_versions')->count();
    $broken = storageEncryptionRuntime($connection, $ddl, $denied, static function (): DataKeyWrapper {
        throw DataKeyUnavailable::kekUnavailable();
    });
    storageEncryptionRejects(static fn () => $broken['workbench']->createRecord($scope, 'workbench.people', ['title' => 'No key', 'passport' => $marker . '-NK'], $admin),
        StorageEncryptionPolicy::UNAVAILABLE, $label . ': a write without a key-encryption key');
    storageEncryptionRejects(static fn () => $broken['workbench']->createStructure($scope, ['structure_id' => 'workbench.nokey', 'label' => 'No key', 'fields' => [
        storageEncryptionField('secret', 10, 'string', [], false, ['encrypted' => true]),
    ]], $admin), StorageEncryptionPolicy::UNAVAILABLE, $label . ': an encrypted structure without a key-encryption key');
    storageViewExpect($broken['workbench']->createRecord($scope, 'workbench.plain', ['title' => 'Plain still works'], $admin)->values['title'] === 'Plain still works',
        $label . ': a storage without encrypted fields stopped working without a key');
    $other = storageEncryptionRuntime($connection, $ddl, $denied, storageEncryptionWrapper(STORAGE_ENCRYPTION_OTHER_KEY));
    storageEncryptionRejects(static fn () => $other['workbench']->createRecord($scope, 'workbench.people', ['title' => 'Other key', 'passport' => $marker . '-OK'], $admin),
        StorageEncryptionPolicy::KEY_UNAVAILABLE, $label . ': a write under another APP_KEY');
    storageEncryptionRejects(static fn () => $other['reveal']->revealRecord($scope, 'workbench.people', $ada->recordId, ['passport'], $admin),
        StorageEncryptionPolicy::KEY_UNAVAILABLE, $label . ': a reveal under another APP_KEY');
    storageViewExpect($connection->table('larena_storage_record_versions')->count() === $countBefore + 1, $label . ': a refused write wrote a version');
    // A previous APP_KEY keeps unwrapping after a rotation.
    $rotated = storageEncryptionRuntime($connection, $ddl, $denied, static fn (): DataKeyWrapper => new AppKeyDerivedDataKeyWrapper(STORAGE_ENCRYPTION_OTHER_KEY, [STORAGE_ENCRYPTION_APP_KEY]));
    storageViewExpect($rotated['reveal']->revealRecord($scope, 'workbench.people', $ada->recordId, ['passport'], $admin) === ['passport' => $marker . '-P'],
        $label . ': a previous APP_KEY does not unwrap');

    // No exception leaks a value: a failed write's message, chain and trace hold no plaintext.
    try {
        $other['workbench']->createRecord($scope, 'workbench.people', ['title' => 'Leak', 'passport' => $marker . '-LEAK'], $admin);
        throw new RuntimeException($label . ': the write under another key passed');
    } catch (StorageRejected $exception) {
        storageViewExpect(!storageEncryptionLeaks($exception, $marker), $label . ': an exception carries the plaintext');
    }
    try {
        $runtime['workbench']->updateRecord($scope, 'workbench.people', $grace->recordId, 1, ['title' => 'Grace', 'passport' => $marker . '-TOO-LONG-' . str_repeat('y', 40)], $admin);
        throw new RuntimeException($label . ': a too long value passed');
    } catch (StorageRejected $exception) {
        storageViewExpect(!storageEncryptionLeaks($exception, $marker), $label . ': a validation failure carries the plaintext');
    }

    // --- A published key never is an encrypted field. ---
    $reads = new DatabaseReadContracts($connection);
    storageEncryptionRejects(static fn () => $reads->keyValuesAt($schemaId, [['record_id' => $ada->recordId, 'revision' => 7]], 'en', 'passport'),
        StorageEncryptionPolicy::KEY_FIELD_REFUSED, $label . ': an encrypted key field');
    storageEncryptionRejects(static fn () => $reads->keyHolderIds($schemaId, 'site:main', 'en', 'passport', 'x'),
        StorageEncryptionPolicy::KEY_FIELD_REFUSED, $label . ': an encrypted key holder lookup');

    // --- The database holds no plaintext anywhere. ---
    $dump = storageEncryptionDatabaseDump($connection);
    storageViewExpect(!str_contains($dump, $marker), $label . ': a storage table holds the plaintext');

    if ($full) {
        storageEncryptionUnit($connection, $label);
        storageEncryptionSitePack($connection, $ddl, $runtime, $denied, $scope, $admin, $marker, $label);
    }

    // --- Purge deletes the data keys. ---
    $current = $workbench->readStructure($scope, 'workbench.people', $admin);
    $workbench->archiveStructure($scope, 'workbench.people', $current->version, $admin);
    $workbench->purgeStructure($scope, 'workbench.people', $current->version, $admin);
    storageViewExpect($connection->table(StorageDataKeys::TABLE)->where('schema_id', $schemaId)->count() === 0, $label . ': a purged structure kept its data keys');
    storageViewExpect($connection->table(StorageDataKeys::TABLE)->where('schema_id', $vault->schema->schemaId)->count() === 1, $label . ': a purge took another structure\'s key');
}

/** The cipher, the envelope and the converter on their own. */
function storageEncryptionUnit(Connection $connection, string $label): void
{
    $cipher = new StorageFieldCipher();
    $key = random_bytes(32);
    $keyId = 'dk-' . str_repeat('ab', 12);
    foreach (['', 'Привет, мир', 'x', 0, -42, PHP_INT_MAX, '12.50', '2026-10-22', '2026-10-22T10:11:12', str_repeat('long ', 200)] as $value) {
        $envelope = $cipher->encrypt($key, $keyId, 'workbench.s', 'record-' . str_repeat('0', 32), null, 'f', $value);
        $again = StorageFieldEnvelope::fromValue($envelope->toArray());
        storageViewExpect($cipher->decrypt($key, $again, 'workbench.s', 'record-' . str_repeat('0', 32), null, 'f') === $value, $label . ': a round trip of ' . var_export($value, true));
        $bytes = strlen($envelope->ciphertext) - StorageFieldEnvelope::TAG_BYTES;
        $plain = strlen(StorageFieldCipher::canonicalJson(['v' => $value]));
        storageViewExpect($plain < 256 ? ($bytes % 32 === 0 && $bytes <= 256 && $bytes > $plain) : ($bytes % 256 === 0 && $bytes > $plain), $label . ': padding of ' . $plain . ' bytes is ' . $bytes);
    }
    $a = $cipher->encrypt($key, $keyId, 's', 'r', 'en', 'f', 'same');
    $b = $cipher->encrypt($key, $keyId, 's', 'r', 'en', 'f', 'same');
    storageViewExpect($a->nonce !== $b->nonce && $a->ciphertext !== $b->ciphertext, $label . ': the nonce is not random');
    storageViewExpect(strlen($cipher->encrypt($key, $keyId, 's', 'r', null, 'f', 'a')->ciphertext) === strlen($cipher->encrypt($key, $keyId, 's', 'r', null, 'f', str_repeat('a', 20))->ciphertext),
        $label . ': two short values of different length are told apart');
    // The associated data binds schema, record, locale, field and key id.
    foreach ([
        ['t', 'r', 'en', 'f', $keyId],
        ['s', 'q', 'en', 'f', $keyId],
        ['s', 'r', 'ru', 'f', $keyId],
        ['s', 'r', null, 'f', $keyId],
        ['s', 'r', 'en', 'g', $keyId],
        ['s', 'r', 'en', 'f', 'dk-' . str_repeat('cd', 12)],
    ] as [$schema, $record, $locale, $field, $id]) {
        $moved = new StorageFieldEnvelope($id, $a->nonce, $a->ciphertext);
        storageEncryptionRejects(static fn () => $cipher->decrypt($key, $moved, $schema, $record, $locale, $field), StorageFieldEnvelope::REASON_INVALID, $label . ': an AAD swap opened');
    }
    storageEncryptionRejects(static fn () => $cipher->decrypt(random_bytes(32), $a, 's', 'r', 'en', 'f'), StorageFieldEnvelope::REASON_INVALID, $label . ': another key opened');
    $flipped = $a->ciphertext;
    $flipped[0] = chr(ord($flipped[0]) ^ 1);
    storageEncryptionRejects(static fn () => $cipher->decrypt($key, new StorageFieldEnvelope($keyId, $a->nonce, $flipped), 's', 'r', 'en', 'f'), StorageFieldEnvelope::REASON_INVALID, $label . ': a tampered ciphertext opened');
    // The envelope shape is strict.
    $good = $a->toArray();
    foreach ([
        $good + ['extra' => 1],
        array_diff_key($good, ['k' => true]),
        ['larena_enc' => 2] + $good,
        ['larena_enc' => '1'] + $good,
        ['a' => 'aes'] + $good,
        ['k' => 'key-1'] + $good,
        ['n' => $good['n'] . '='] + $good,
        ['n' => substr($good['n'], 0, -2)] + $good,
        ['c' => substr($good['c'], 0, 10)] + $good,
        ['c' => 1] + $good,
        array_values($good),
        'larena_enc',
    ] as $index => $shape) {
        storageEncryptionRejects(static fn () => StorageFieldEnvelope::fromValue($shape), StorageFieldEnvelope::REASON_INVALID, $label . ': envelope shape ' . $index . ' was accepted');
    }
    // No plaintext or key in an exception from the cipher.
    try {
        $cipher->decrypt($key, $a, 's', 'r', 'en', 'wrong');
    } catch (StorageRejected $exception) {
        storageViewExpect($exception->getMessage() === StorageFieldEnvelope::REASON_INVALID && $exception->getPrevious() === null
            && !storageEncryptionLeaks($exception, bin2hex($key)) && !storageEncryptionLeaks($exception, $key), $label . ': the cipher exception carries material');
    }
    try {
        $cipher->encrypt('short', $keyId, 's', 'r', null, 'f', 'MARKER-UNIT-PLAINTEXT');
    } catch (StorageRejected $exception) {
        storageViewExpect($exception->getMessage() === StorageFieldCipher::REASON_UNAVAILABLE && !storageEncryptionLeaks($exception, 'MARKER-UNIT-PLAINTEXT'), $label . ': the encrypt exception carries the value');
    }
    // The converter never retypes an envelope, and drops one whose field is no longer encrypted.
    $converter = new StorageRecordConverter($connection);
    $sealed = ['f' => $good, 'g' => $good];
    $from = ['f' => ['key' => 'f', 'type' => 'string', 'type_version' => 1, 'encrypted' => true], 'g' => ['key' => 'g', 'type' => 'string', 'type_version' => 1, 'encrypted' => true]];
    $to = ['f' => ['key' => 'f', 'type' => 'string', 'type_version' => 1, 'encrypted' => true], 'g' => ['key' => 'g', 'type' => 'string', 'type_version' => 1]];
    storageViewExpect($converter->step($sealed, $from, $to) === ['f' => $good], $label . ': the converter kept an envelope of a field no longer encrypted');
    storageEncryptionRejects(static fn () => $converter->step($sealed, $from, $to, true), 'storage_schema_migration_record_unconvertible', $label . ': a strict step kept an envelope');
}

/**
 * SitePack: an export leaves encrypted values out (`encrypted_values_omitted`), an import plans the warning, relaxes a
 * required encrypted field and writes the records without them.
 *
 * @param array<string, mixed> $runtime
 * @param array<string, list<string>> $denied
 */
function storageEncryptionSitePack(Connection $connection, StorageViewDdl $ddl, array $runtime, array $denied, string $scope, string $admin, string $marker, string $label): void
{
    $directory = sys_get_temp_dir() . '/larena-storage-encryption-sitepack-' . bin2hex(random_bytes(6));
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
    );
    try {
        $exported = $sitePack->run($sitePack->startExport($scope, 'workbench.people', $admin, null, false)->runRef, $admin);
        storageViewExpect($exported->state === 'done', $label . ': the export did not finish: ' . json_encode($exported->toArray()));
        storageViewExpect(($exported->counts['encrypted_values_omitted'] ?? 0) > 0, $label . ': the export does not count the omitted values: ' . json_encode($exported->counts));
        $archive = $sitePack->archive($exported->runRef, $admin)->path;
        $lines = '';
        foreach (sitePackArtifacts($archive) as $bytes) {
            $lines .= $bytes;
        }
        storageViewExpect(!str_contains($lines, $marker) && !str_contains($lines, 'larena_enc'), $label . ': the pack carries an encrypted value');

        $copy = $directory . '-people.zip';
        copy($archive, $copy);
        $planned = sitePackImportPlan($sitePack, 'scope:tenant-beta', $copy, $admin, new StorageSitePackImportOptions(null, null, 'draft', [], true));
        $codes = sitePackImportCodes($planned->plan === null ? [] : $planned->plan->warnings);
        storageViewExpect(in_array('encrypted_values_omitted', $codes, true), $label . ': the plan does not warn: ' . json_encode($planned->plan?->warnings));
        $done = sitePackImportApply($sitePack, $planned, $admin);
        storageViewExpect($done->state === 'done', $label . ': the import did not finish: ' . json_encode($done->toArray()));
        $imported = $runtime['workbench']->readStructure('scope:tenant-beta', 'workbench.people', $admin);
        foreach ($imported->fields as $field) {
            if ($field['key'] === 'passport') {
                storageViewExpect(($field['encrypted'] ?? false) === true && $field['required'] === false, $label . ': the imported encrypted field is ' . json_encode($field));
            }
        }
        $page = $runtime['workbench']->listRecords(new StorageWorkbenchRecordQuery('scope:tenant-beta', 'workbench.people', [], null, [], 50), $admin);
        storageViewExpect($page->items !== [], $label . ': nothing was imported');
        foreach ($page->items as $item) {
            storageViewExpect(!array_key_exists('passport', $item->values) && !array_key_exists('salary', $item->values), $label . ': an imported record carries an encrypted value');
        }
        storageViewExpect($connection->table(StorageDataKeys::TABLE)->where('schema_id', $imported->schema->schemaId)->count() === 1, $label . ': the imported structure has no data key');
    } finally {
        Larena\Storage\SitePack\StorageSitePackExporter::wipe($directory);
        @unlink($directory . '-people.zip');
    }
}
