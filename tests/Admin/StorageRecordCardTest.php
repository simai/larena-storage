<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Support/storage-encryption-fixture.php';

use Illuminate\Database\Schema\Blueprint;
use Larena\Admin\Contracts\AdminIdentityLabelResolver;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\StoragePropertyViewPort;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Encryption\StorageFieldEnvelope;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\View\SqliteStorageViewDdl;

// The Framework record card (C305) over a real Storage workbench on SQLite: record.history answers the record's
// changes newest first, a page at a time by the revision cursor, each field before → after with its label, an
// encrypted field only «changed»; kinds filter the events; the actor is a name, never a reference; a short field is
// saved alone with group.save at the record's revision and a stale revision is a conflict with its reason;
// record.neighbours answers the records before and after one in the list's own order; a record with 1000 revisions
// reads its first page and a page deep in it within 0.5 s warm.

/** The section gateway as far as the card needs it: the person may read and change records; writes go to Storage. */
final class RecordCardGateway implements StorageWorkbenchGateway
{
    public function __construct(private StorageWorkbench $workbench)
    {
    }

    public function snapshot(string $actor, ?string $requestedScope, array $query = []): array
    {
        return ['status' => 'ok', 'selected_structure' => ['structure_id' => $query['structure_id'] ?? null, 'label' => 'People', 'archived' => false],
            'permissions' => ['record.update' => true, 'record.archive' => true], 'structures' => []];
    }

    public function dispatch(string $actor, ?string $requestedScope, string $action, array $payload): array
    {
        if ($action !== 'record.update' || !is_string($requestedScope)) {
            return ['status' => 'unavailable', 'reason_code' => 'not_used'];
        }
        try {
            $this->workbench->updateRecord($requestedScope, $payload['structure_id'], $payload['record_id'], (int) $payload['expected_revision'],
                $payload['values'], $actor);
        } catch (StorageConflict) {
            return ['status' => 'rejected', 'reason_code' => 'storage_workbench_revision_conflict'];
        } catch (StorageRejected $exception) {
            return ['status' => 'rejected', 'reason_code' => $exception->reasonCode];
        }

        return ['status' => 'ok'];
    }
}

final class RecordCardNames implements AdminIdentityLabelResolver
{
    public function resolve(string $subjectRef): ?string
    {
        return $subjectRef === 'actor:admin:alpha' ? 'Ада Админова' : null;
    }
}

function recordCardExpect(bool $condition, string $message, mixed $context = null): void
{
    if (!$condition) {
        throw new RuntimeException($message . ($context === null ? '' : ': ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
}

$opened = storageViewQueueOpen();
try {
    $connection = $opened['connection'];
    $admin = 'actor:admin:alpha';
    $scope = 'scope:tenant-alpha';
    storageEncryptionInstall($connection);
    $runtime = storageEncryptionRuntime($connection, new SqliteStorageViewDdl($connection), [$admin => []], storageEncryptionWrapper());
    $workbench = $runtime['workbench'];
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
    $layouts = new AdminRecordLayoutStore(\Larena\Setting\Runtime\ViewSettings::on($connection));
    $port = new StoragePropertyViewPort(new RecordCardGateway($workbench), $workbench, $layouts, names: new RecordCardNames());

    // The history is read by the (schema_id, record_id, revision) index Storage creates with its versions.
    $indexed = false;
    foreach ($connection->select("PRAGMA index_list('larena_storage_record_versions')") as $index) {
        $columns = array_map(static fn (object $column): string => (string) $column->name,
            $connection->select('PRAGMA index_info(' . $connection->getPdo()->quote((string) $index->name) . ')'));
        $indexed = $indexed || $columns === ['schema_id', 'record_id', 'revision'];
    }
    recordCardExpect($indexed, 'the versions have no (schema_id, record_id, revision) index');

    $structureId = 'workbench.people';
    $workbench->createStructure($scope, ['structure_id' => $structureId, 'label' => 'People', 'fields' => [
        storageEncryptionField('name', 10, 'string', ['max_length' => 200]),
        storageEncryptionField('city', 20, 'string', ['max_length' => 200]),
        storageEncryptionField('passport', 30, 'string', [], false, ['encrypted' => true]),
    ]], $admin);
    $ada = $workbench->createRecord($scope, $structureId, ['name' => 'Ada', 'city' => 'London', 'passport' => 'PASSPORT-ONE'], $admin);
    $raise = static fn (string $intent, array $payload): array => $port->raise($admin, $scope, $intent, ['collection' => $structureId, 'record_id' => $ada->recordId] + $payload);

    // 1. A short field saved alone: group.save with one value at the record's revision.
    $saved = $raise('group.save', ['revision' => 1, 'section' => 'main', 'group' => 'main', 'values' => ['city' => 'Paris']]);
    recordCardExpect(($saved['answer'] ?? null) === 'applied', 'one field was not saved', $saved);
    $current = $workbench->readRecord($scope, $structureId, $ada->recordId, $admin);
    recordCardExpect($current->revision === 2 && $current->values['city'] === 'Paris' && $current->values['name'] === 'Ada'
        && StorageFieldEnvelope::isEnvelope($current->values['passport']), 'saving one field changed the others', $current->values);
    // A stale revision is refused as a conflict with its reason, and nothing is written.
    $stale = $raise('group.save', ['revision' => 1, 'section' => 'main', 'group' => 'main', 'values' => ['name' => 'Stale']]);
    recordCardExpect(($stale['answer'] ?? null) === 'conflict' && ($stale['reason'] ?? null) === 'revision_moved' && ($stale['current_revision'] ?? null) === 2
        && is_string($stale['message'] ?? null) && $stale['message'] !== '', 'a stale revision was not a conflict with a reason', $stale);
    recordCardExpect($workbench->readRecord($scope, $structureId, $ada->recordId, $admin)->revision === 2, 'a stale save wrote a version');
    $saved = $raise('group.save', ['revision' => 2, 'section' => 'main', 'group' => 'main', 'values' => ['name' => 'Ada L.', 'passport' => 'PASSPORT-TWO']]);
    recordCardExpect(($saved['answer'] ?? null) === 'applied', 'the second save failed', $saved);

    // 2. Two changes are two events, newest first, each with its own before and after.
    $updates = $raise('record.history', ['kinds' => ['update']]);
    recordCardExpect(($updates['answer'] ?? null) === 'applied' && count($updates['events']) === 2 && $updates['has_more'] === false, 'two changes are not two events', $updates);
    [$second, $first] = $updates['events'];
    recordCardExpect($second['revision'] === 3 && $second['kind'] === 'update' && $first['revision'] === 2, 'the events are not newest first', $updates);
    recordCardExpect($first['changes'] === [['field' => 'city', 'label' => 'City', 'before' => 'London', 'after' => 'Paris']], 'the first change is wrong', $first);
    recordCardExpect($second['changes'] === [
        ['field' => 'name', 'label' => 'Name', 'before' => 'Ada', 'after' => 'Ada L.'],
        // An encrypted field says only that it changed: no before, no after.
        ['field' => 'passport', 'label' => 'Passport', 'sealed' => true],
    ], 'the second change is wrong', $second);
    recordCardExpect($second['actor'] === 'Ада Админова' && preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00\z/', $second['time']) === 1
        && $second['id'] === $ada->recordId . ':3', 'the actor or the time is wrong', $second);
    $all = $raise('record.history', []);
    $json = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    recordCardExpect(count($all['events']) === 3 && $all['events'][2]['kind'] === 'create'
        && in_array(['field' => 'name', 'label' => 'Name', 'before' => null, 'after' => 'Ada'], $all['events'][2]['changes'], true)
        && in_array(['field' => 'passport', 'label' => 'Passport', 'sealed' => true], $all['events'][2]['changes'], true), 'the create event is wrong', $all);
    recordCardExpect(!str_contains($json, 'PASSPORT-') && !str_contains($json, StorageFieldEnvelope::MARKER) && !str_contains($json, 'actor:'),
        'the history carries an encrypted value, an envelope or a reference', $all);
    // Kinds filter by what was done; publication is not kept in the record's versions.
    $created = $raise('record.history', ['kinds' => ['create']]);
    recordCardExpect(count($created['events']) === 1 && $created['events'][0]['revision'] === 1, 'the create filter is wrong', $created);
    recordCardExpect($raise('record.history', ['kinds' => ['publish']])['events'] === [], 'a publish filter found versions');
    recordCardExpect(($raise('record.history', ['kinds' => ['renamed']])['reason'] ?? null) === 'history_invalid', 'an unknown kind was taken');
    // Without a name for the person the actor is empty, never the reference.
    $anonymous = (new StoragePropertyViewPort(new RecordCardGateway($workbench), $workbench, $layouts))
        ->raise($admin, $scope, 'record.history', ['collection' => $structureId, 'record_id' => $ada->recordId]);
    recordCardExpect($anonymous['events'][0]['actor'] === null, 'an unnamed actor showed its reference', $anonymous['events'][0]);

    // 3. Pages by the revision cursor.
    $page = $raise('record.history', ['limit' => 2]);
    recordCardExpect(array_column($page['events'], 'revision') === [3, 2] && $page['has_more'] === true && $page['cursor'] === '2', 'the first page is wrong', $page);
    $next = $raise('record.history', ['limit' => 2, 'cursor' => $page['cursor']]);
    recordCardExpect(array_column($next['events'], 'revision') === [1] && $next['has_more'] === false && $next['cursor'] === null, 'the next page is wrong', $next);
    recordCardExpect(($raise('record.history', ['cursor' => 'abc'])['reason'] ?? null) === 'history_invalid', 'a cursor that is not a revision was taken');

    // 4. The neighbours of a record in the list's own order, not by id.
    $ids = ['Ada L.' => $ada->recordId];
    foreach (['Clara' => 'Paris', 'Boris' => 'Berlin', 'Dmitry' => 'Paris'] as $name => $city) {
        $ids[$name] = $workbench->createRecord($scope, $structureId, ['name' => $name, 'city' => $city], $admin)->recordId;
    }
    $around = static function (string $name, array $query) use ($port, $admin, $scope, $structureId, $ids): array {
        $answer = $port->raise($admin, $scope, 'record.neighbours', ['collection' => $structureId, 'record_id' => $ids[$name], 'query' => $query]);
        recordCardExpect(($answer['answer'] ?? null) === 'applied', 'the neighbours were not answered', $answer);
        $names = array_flip($ids);

        return [$answer['listed'], $answer['previous'] === null ? null : $names[$answer['previous']['record_id']],
            $answer['next'] === null ? null : $names[$answer['next']['record_id']]];
    };
    recordCardExpect($around('Boris', ['sort' => [['key' => 'name', 'direction' => 'asc']]]) === [true, 'Ada L.', 'Clara'], 'by name ascending');
    recordCardExpect($around('Boris', ['sort' => [['field' => 'name', 'dir' => 'desc']]]) === [true, 'Clara', 'Ada L.'], 'by name descending');
    recordCardExpect($around('Ada L.', ['sort' => [['key' => 'name', 'direction' => 'asc']]]) === [true, null, 'Boris'], 'the first record has no previous');
    recordCardExpect($around('Clara', ['filters' => ['city' => ['Paris']], 'sort' => [['key' => 'name', 'direction' => 'asc']]]) === [true, 'Ada L.', 'Dmitry'],
        'the filter of the view is not kept');
    recordCardExpect($around('Boris', ['filters' => ['city' => ['Paris']]]) === [false, null, null], 'a record outside the list has neighbours');
    // Without a sort, the list's own order (newest first, then the id): the rows the list shows around the record.
    $listed = array_map(static fn ($record): string => array_flip($ids)[$record->recordId], $workbench->listRecords(
        new \Larena\Storage\Contracts\StorageWorkbenchRecordQuery($scope, $structureId, sort: \Larena\Storage\Admin\StorageListOrder::NEWEST_FIRST), $admin)->items);
    $at = array_search('Boris', $listed, true);
    recordCardExpect($around('Boris', []) === [true, $listed[$at - 1] ?? null, $listed[$at + 1] ?? null], 'the default order is not the list\'s', $listed);

    // 5. A record with 1000 revisions: the first page and a page deep in it, warm, within 0.5 s.
    $long = $workbench->createRecord($scope, $structureId, ['name' => 'Long', 'city' => 'c0'], $admin);
    $revision = 1;
    for ($i = 1; $i < 1000; $i++) {
        $revision = $workbench->updateRecord($scope, $structureId, $long->recordId, $revision, ['name' => 'Long', 'city' => 'c' . $i], $admin)->revision;
    }
    recordCardExpect($revision === 1000, 'the long record has not 1000 revisions');
    $history = static fn (array $payload): array => $port->raise($admin, $scope, 'record.history', ['collection' => $structureId, 'record_id' => $long->recordId] + $payload);
    $history([]);
    $timings = [];
    foreach ([[], ['cursor' => '500'], ['cursor' => '300', 'kinds' => ['update']]] as $payload) {
        $started = hrtime(true);
        $answer = $history($payload);
        $timings[] = (hrtime(true) - $started) / 1e9;
        recordCardExpect(count($answer['events']) === 20 && $answer['has_more'] === true, 'a long history page is wrong', $payload);
    }
    $deep = $history(['cursor' => '500']);
    recordCardExpect($deep['events'][0]['revision'] === 499 && $deep['events'][0]['changes'] === [['field' => 'city', 'label' => 'City', 'before' => 'c497', 'after' => 'c498']],
        'a deep page is wrong', $deep['events'][0]);
    recordCardExpect(max($timings) <= 0.5, 'a history page of 1000 revisions took longer than 0.5 s', $timings);
    printf("Record history, 1000 revisions, warm: first page %.1f ms, page at 500 %.1f ms, filtered page %.1f ms.\n",
        $timings[0] * 1000, $timings[1] * 1000, $timings[2] * 1000);
} finally {
    @unlink($opened['path']);
}

echo "Storage record card passed.\n";
