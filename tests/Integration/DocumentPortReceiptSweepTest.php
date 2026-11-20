<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Storage\DocumentPort\DatabaseDocumentPortStore;
use Larena\Storage\DocumentPort\DocumentPort;
use Larena\Storage\DocumentPort\DocumentPortBinding;
use Larena\Storage\DocumentPort\DocumentPortElement;
use Larena\Storage\DocumentPort\DocumentPortReceiptSweep;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/published-site-view-fixture.php';

// The document port forgets a client_key past its retention (30 days by default, never under 7): a fresh receipt
// survives, an old one goes, a repeat inside the window still gets the stored answer and a repeat of a forgotten key
// is a new operation. A receipt that also holds history (a publication, the revision a restore came from) keeps its
// row without the key, so history.list and the live publication answer as before.

$actor = 'actor:admin:editor';
$scope = 'scope:site-main';
$site = 'site:main';
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$connection = $capsule->getConnection();
publishedSiteViewInstall($connection);
(require __DIR__ . '/../../database/migrations/platform/2026_11_20_000002_create_larena_storage_document_port_receipts_table.php')->up();
$runtime = publishedSiteViewRuntime($connection, new SqliteStorageViewDdl($connection), $actor, $scope);
$workbench = $runtime['workbench'];
$workbench->createStructure($scope, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => [
    publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], false, true),
    publishedSiteViewField('document', 20, 'composition', 'public'),
]], $actor);
$document = static fn (string $text): array => ['schema' => 'simai.composition.document', 'schema_version' => 1, 'id' => 'page',
    'profile' => 'ui-layout', 'locale' => 'ru', 'root' => ['id' => 'root', 'type' => 'layout.page', 'slots' => ['default' => [
        ['id' => 'main-1', 'type' => 'content.paragraph', 'data' => ['content' => [['text' => $text]]]],
    ]]]];
$record = $workbench->createRecord($scope, 'workbench.pages', ['title' => 'Команда', 'document' => ['ru' => $document('Начало')]], $actor);
$target = ['level' => 'page', 'ref' => 'page:/team/'];
$binding = new DocumentPortBinding($scope, 'workbench.pages', $record->recordId, 'document', $site, $target, ['ru']);
$store = new DatabaseDocumentPortStore($connection, $workbench, $runtime['publication'], $binding, $actor);
$port = DocumentPort::bound(new DocumentPortElement($target, ['ru'], ['history', 'publish'], rights: ['save' => true, 'publish' => true]), $store);
$receipts = static fn () => $connection->table(DatabaseDocumentPortStore::RECEIPTS_TABLE)->orderBy('id')->get()->all();
$age = static function (string $key, string $at) use ($connection): void {
    $connection->table(DatabaseDocumentPortStore::RECEIPTS_TABLE)->where('client_key', $key)->update(['created_at' => $at]);
};

$start = $port->send('document.load')['data']['revision'];
// Old: a save, a restore and a publication written 40 days ago.
$oldSave = ['values' => ['ru' => $document('Старое')], 'expected_revision' => $start, 'client_key' => 'save-old'];
$saved = $port->send('document.save', $oldSave);
storageViewExpect($saved['answer'] === 'applied', 'the old save');
$restore = ['revision' => $start, 'expected_revision' => $saved['data']['revision'], 'client_key' => 'restore-old'];
$restored = $port->send('document.restore', $restore);
storageViewExpect($restored['answer'] === 'applied', 'the old restore');
$published = $port->send('document.publish', ['revision' => $restored['data']['revision'], 'expected_published' => null, 'client_key' => 'publish-old']);
storageViewExpect($published['answer'] === 'applied', 'the old publication: ' . json_encode($published['reason'] ?? null));
foreach (['save-old', 'restore-old', 'publish-old'] as $key) {
    $age($key, '2026-10-01 10:00:00');
}
// Fresh: a save of today, and one 29 days old, inside the window.
$freshSave = ['values' => ['ru' => $document('Свежее')], 'expected_revision' => $restored['data']['revision'], 'client_key' => 'save-fresh'];
$fresh = $port->send('document.save', $freshSave);
$lateSave = ['values' => ['ru' => $document('Почти месяц')], 'expected_revision' => $fresh['data']['revision'], 'client_key' => 'save-29'];
$late = $port->send('document.save', $lateSave);
$age('save-29', '2026-10-12 10:00:00');
storageViewExpect($fresh['answer'] === 'applied' && $late['answer'] === 'applied', 'the fresh saves');
$history = $port->send('history.list');

// A retention under 7 days is refused: a client retry must find its key.
$refused = false;
try {
    (new DocumentPortReceiptSweep($connection))->sweep(3);
} catch (InvalidArgumentException) {
    $refused = true;
}
storageViewExpect($refused && count($receipts()) === 5, 'a retention under 7 days is refused and removes nothing');

$swept = (new DocumentPortReceiptSweep($connection))->sweep(now: new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('UTC')));
storageViewExpect($swept === ['removed' => 1, 'forgotten' => 2, 'before' => '2026-10-11 12:00:00'], 'the sweep: ' . json_encode($swept));
$left = $receipts();
storageViewExpect(array_column($left, 'client_key') === [null, null, 'save-fresh', 'save-29'], 'the old keys go, the fresh ones stay: ' . json_encode(array_column($left, 'client_key')));
storageViewExpect($left[0]->restored_from !== null && $left[1]->publication_id !== null && $left[0]->params_hash === null, 'history facts stay without the key');
// history.list answers as before, the publication without its forgotten key.
$after = $port->send('history.list');
storageViewExpect($after['data']['drafts'] === $history['data']['drafts'], 'the drafts are unchanged, the restore still named');
$withoutKey = $history['data']['publications'];
unset($withoutKey[0]['client_key']);
storageViewExpect($after['data']['publications'] === $withoutKey && $after['data']['publications'][0]['state'] === 'published', 'the publication stays live');

// A repeat inside the window gets the stored answer, not a conflict with itself.
$current = $port->send('document.load')['data']['revision'];
$again = $port->send('document.save', $lateSave);
storageViewExpect($again['answer'] === 'applied' && $again['data']['revision'] === $late['data']['revision'], 'a repeat inside the window is the same save');
$again = $port->send('document.save', $freshSave);
storageViewExpect($again['answer'] === 'applied' && $again['data']['revision'] === $fresh['data']['revision'], 'a fresh repeat is the same save');
storageViewExpect($port->send('document.load')['data']['revision'] === $current, 'the repeats wrote nothing');
// A repeat of a forgotten key is a new operation: the save is compared with the revision it names (the draft moved).
$new = $port->send('document.save', $oldSave);
storageViewExpect($new['answer'] === 'conflict' && $new['reason'] === 'stale-revision', 'a forgotten save key is a new save: ' . json_encode($new['answer']));
// A forgotten publish key is a new publish too: its old revision is no longer the draft, and with the current one
// the same key makes a new publication.
$repeat = $port->send('document.publish', ['revision' => $restored['data']['revision'], 'expected_published' => 'p3', 'client_key' => 'publish-old']);
storageViewExpect($repeat['answer'] === 'conflict' && $repeat['reason'] === 'stale-revision', 'a forgotten publish key is compared anew: ' . json_encode($repeat['answer']));
$republished = $port->send('document.publish', ['revision' => $current, 'expected_published' => 'p3', 'client_key' => 'publish-old']);
storageViewExpect($republished['answer'] === 'applied' && ($republished['data']['publication']['id'] ?? null) !== 'p3', 'a forgotten publish key makes a new publication: ' . json_encode($republished));

// A second sweep finds nothing more; the command and its daily schedule are registered by the provider.
storageViewExpect(array_slice((new DocumentPortReceiptSweep($connection))->sweep(now: new DateTimeImmutable('2026-11-10 12:00:00')), 0, 2) === ['removed' => 0, 'forgotten' => 0], 'idempotent');
$provider = (string) file_get_contents(__DIR__ . '/../../src/Providers/StorageServiceProvider.php');
storageViewExpect(str_contains($provider, 'DocumentPortReceiptSweepCommand::class') && preg_match("/'larena:storage:document-port:sweep'\\)\\s*->daily\\(\\)/", $provider) === 1, 'scheduled daily');
storageViewExpect((new DocumentPortReceiptSweep($connection))->sweep(now: new DateTimeImmutable('2026-12-31 00:00:00', new DateTimeZone('UTC')))['before'] === '2026-12-01 00:00:00', 'the default window is 30 days');

echo "DocumentPortReceiptSweepTest passed\n";
