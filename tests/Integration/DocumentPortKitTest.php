<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Larena\Storage\DocumentPort\AccessDocumentPortRights;
use Larena\Storage\DocumentPort\DatabaseDocumentPorts;
use Larena\Storage\DocumentPort\DatabaseDocumentPortStore;
use Larena\Storage\DocumentPort\DocumentPort;
use Larena\Storage\DocumentPort\DocumentPortBinding;
use Larena\Storage\DocumentPort\DocumentPortElement;
use Larena\Storage\DocumentPort\DocumentPortJson;
use Larena\Storage\DocumentPort\DocumentPortRights;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/published-site-view-fixture.php';

// The Framework's conformance kit of simai.document-port 1.0.0 (draft 2), the acceptance gate of a host: its 39 cases
// read from kit/cases/*.json of the Framework Root pins, with the checks of the kit's own runner (run.mjs), against
// the port on Storage's records. Each case gets a database of its own: a page structure with a composition@1 field,
// the case's drafts written as record versions whose row ids are the case's revisions, its publications published
// through the publication lifecycle. The payloads travel as JSON (DocumentPortJson), as over HTTP. The host
// announces what the case gives (history and publish in production; levels, area inheritance, comes later, and the
// port already answers it for the six cases that need it).

$root = getenv('SIMAI_UI_ROOT');
if (!is_string($root) || $root === '') {
    throw new RuntimeException('Exact SIMAI_UI_ROOT required for the document port kit');
}
$at = $root . '/distr/core/contracts/ports/' . DocumentPort::PORT . '/' . DocumentPort::EDITION;
$read = static fn (string $file, bool $objects = false): mixed => json_decode((string) file_get_contents($file), !$objects, 512, JSON_THROW_ON_ERROR);
$port = $read($at . '/port.json');
$manifest = $read($at . '/kit/manifest.json');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, 'DocumentPortKitTest failed: ' . $message . PHP_EOL);
        exit(1);
    }
};

// The edition this server speaks: the intents, their capabilities and the kit, by its digest (kit/README.md).
$expect($port['port'] === DocumentPort::PORT && $port['version'] === DocumentPort::EDITION && $manifest['version'] === DocumentPort::EDITION, 'the edition');
$expect(array_map(static fn (array $intent): ?string => $intent['capability'], $port['intents']) == DocumentPort::INTENTS, 'the intents and their capabilities');
$expect($port['conformance']['sha256'] === DocumentPort::KIT_SHA256 && $port['conformance']['cases'] === count($manifest['cases']), 'the kit pinned');
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($at . '/kit', FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!str_ends_with($file->getPathname(), '.gz')) {
        $files[] = substr($file->getPathname(), strlen($at . '/kit/'));
    }
}
sort($files, SORT_STRING);
$listing = implode('', array_map(static fn (string $file): string => $file . ' ' . hash_file('sha256', $at . '/kit/' . $file) . "\n", $files));
$expect(hash('sha256', $listing) === DocumentPort::KIT_SHA256, 'the kit on disk is the pinned one');
$reasons = $port['answer']['schema']['properties']['reason']['enum'];
$expect(is_array($reasons) && count($reasons) === 16, 'the reasons');

/** The rights a case gives its reader, as the host would answer them. */
final class KitRights implements DocumentPortRights
{
    /** @param array{save: bool, publish: bool, languages?: list<string>}|null $rights */
    public function __construct(private ?array $rights)
    {
    }

    public function rights(DocumentPortBinding $binding, string $actor): ?array
    {
        return $this->rights;
    }
}

const KIT_ACTOR = 'actor:admin:editor';
const KIT_SCOPE = 'scope:site-main';
const KIT_SITE = 'site:main';

/**
 * A case's host: a fresh database, the record written revision by revision under the case's row ids.
 *
 * @param array<string, mixed> $given
 * @return array{port: DocumentPort, connection: Connection, binding: DocumentPortBinding, ports: DatabaseDocumentPorts}
 */
function kitHost(array $given): array
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();
    publishedSiteViewInstall($connection);
    (require __DIR__ . '/../../database/migrations/platform/2026_11_20_000002_create_larena_storage_document_port_receipts_table.php')->up();
    $runtime = publishedSiteViewRuntime($connection, new SqliteStorageViewDdl($connection), KIT_ACTOR, KIT_SCOPE);
    $workbench = $runtime['workbench'];
    $publication = $runtime['publication'];
    $workbench->createStructure(KIT_SCOPE, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => [
        publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], false, true),
        publishedSiteViewField('document', 20, 'composition', 'public'),
    ]], KIT_ACTOR);

    // The drafts oldest first, then the current one: each a record version whose row id is the case's revision.
    $versions = [...array_reverse($given['drafts'] ?? []), ['revision' => $given['revision'], 'values' => $given['values']]];
    $record = null;
    foreach ($versions as $version) {
        // sqlite_sequence has no key on name: one row per table, updated or added.
        $connection->statement("DELETE FROM sqlite_sequence WHERE name = 'larena_storage_record_versions'");
        $connection->statement("INSERT INTO sqlite_sequence (name, seq) VALUES ('larena_storage_record_versions', ?)", [(int) $version['revision'] - 1]);
        $values = ['title' => 'Команда', 'document' => $version['values']];
        $record = $record === null
            ? $workbench->createRecord(KIT_SCOPE, 'workbench.pages', $values, KIT_ACTOR)
            : $workbench->updateRecord(KIT_SCOPE, 'workbench.pages', $record->recordId, $record->revision, $values, KIT_ACTOR);
        $row = (string) $connection->table('larena_storage_record_versions')->where('record_id', $record->recordId)->where('revision', $record->revision)->value('id');
        storageViewExpect($row === (string) $version['revision'], 'the seeded revision ' . $version['revision'] . ' is row ' . $row);
    }
    $capabilities = $given['capabilities'];
    $binding = new DocumentPortBinding(KIT_SCOPE, 'workbench.pages', $record->recordId, 'document',
        in_array('publish', $capabilities, true) ? KIT_SITE : null, $given['target'], $given['languages'], $given['limits']);
    $store = new DatabaseDocumentPortStore($connection, $workbench, $publication, $binding, KIT_ACTOR);
    // The publications oldest first, each under the case's id and key: the newest is the live one.
    foreach (array_reverse($given['publications'] ?? []) as $one) {
        $store->publish($one['revision'], $one['languages'], $one['from'] ?? null, ['intent' => 'document.publish', 'client_key' => $one['client_key'],
            'params_hash' => hash('sha256', 'seeded ' . $one['id'])], $one['id']);
    }
    storageViewExpect($store->live() === ($given['published'] ?? null), 'the live publication is ' . json_encode($store->live()));

    $ports = new DatabaseDocumentPorts($connection, $workbench, $publication,
        array_key_exists('rights', $given) && $given['rights'] !== null ? new KitRights($given['rights']) : new AccessDocumentPortRights(new StorageViewFixtureAccess(KIT_ACTOR, KIT_SCOPE), new StorageViewFixtureAccess(KIT_ACTOR, KIT_SCOPE)));
    $opened = $ports->open($binding, ($given['fault'] ?? null) === 'session-expired' ? null : KIT_ACTOR);
    if (($given['fault'] ?? null) === 'session-expired') {
        return ['port' => $opened, 'connection' => $connection, 'binding' => $binding, 'ports' => $ports];
    }
    storageViewExpect($opened->capabilities() === $binding->capabilities(), 'the database element announces history and publish');
    // The element as the case announces it, with the reader's rights the host gave and the parent levels.
    $rights = $given['rights'] ?? ['save' => true, 'publish' => in_array('publish', $capabilities, true)];
    $element = new DocumentPortElement($given['target'], $given['languages'], $capabilities, $given['limits'], $rights, $given['parents'] ?? []);
    if (($given['fault'] ?? null) === 'storage-unavailable') {
        $connection->getSchemaBuilder()->drop('larena_storage_record_versions');
    }

    return ['port' => DocumentPort::bound($element, $store), 'connection' => $connection, 'binding' => $binding, 'ports' => $ports];
}

/** A value at a dot path; numeric segments index lists. The sentinel stands for undefined. */
function kitAt(mixed $value, string $path): mixed
{
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists(ctype_digit($segment) ? (int) $segment : $segment, $value)) {
            return KitMissing::class;
        }
        $value = $value[ctype_digit($segment) ? (int) $segment : $segment];
    }

    return $value;
}

final class KitMissing
{
}

function kitSame(mixed $left, mixed $right): bool
{
    return DocumentPort::same($left, $right);
}

/** The layout.region named $name anywhere in a document. @return array<string, mixed>|null */
function kitRegion(mixed $node, string $name): ?array
{
    if (!is_array($node)) {
        return null;
    }
    if (($node['type'] ?? null) === 'layout.region' && ($node['props']['name'] ?? null) === $name) {
        return $node;
    }
    $children = isset($node['root']) ? [$node['root']] : [];
    foreach (is_array($node['slots'] ?? null) ? $node['slots'] : [] as $slot) {
        $children = [...$children, ...(is_array($slot) ? $slot : [])];
    }
    foreach ($children as $child) {
        $found = kitRegion($child, $name);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * The kit's `then`, read as run.mjs reads it.
 *
 * @param array<string, mixed> $answer @param array<string, mixed> $then @param array<string, mixed> $steps
 * @return list<string>
 */
function kitCheck(array $answer, array $then, array $steps): array
{
    $GLOBALS['kitChecks'] = ($GLOBALS['kitChecks'] ?? 0) + 1 + count($then['answer_must_include'] ?? []) + count($then['data_must_include'] ?? []) + count($then['expect'] ?? []);
    $failures = [];
    if (($answer['answer'] ?? null) !== $then['answer']) {
        $failures[] = 'answer is ' . json_encode($answer['answer'] ?? null) . ', expected ' . $then['answer'] . ' ' . json_encode(array_intersect_key($answer, ['reason' => 1, 'message' => 1]));
    }
    foreach ($then['answer_must_include'] ?? [] as $key) {
        if (!array_key_exists($key, $answer)) {
            $failures[] = 'the answer carries no ' . $key;
        }
    }
    foreach ($then['data_must_include'] ?? [] as $key) {
        if (!is_array($answer['data'] ?? null) || !array_key_exists($key, $answer['data'])) {
            $failures[] = 'data carries no ' . $key;
        }
    }
    foreach ($then['expect'] ?? [] as $rule) {
        if (isset($rule['area'])) {
            $region = kitRegion($answer['data']['resolved'][$rule['language']] ?? null, $rule['area']);
            if ($region === null) {
                $failures[] = 'resolved.' . $rule['language'] . ' has no area ' . $rule['area'];
                continue;
            }
            $mark = $region['extensions']['simai:area'] ?? [];
            foreach ($rule['mark'] ?? [] as $key => $value) {
                if (!kitSame($mark[$key] ?? null, $value)) {
                    $failures[] = $rule['language'] . ' ' . $rule['area'] . ': mark ' . $key . ' is ' . json_encode($mark[$key] ?? null) . ', expected ' . json_encode($value);
                }
            }
            if (isset($rule['texts'])) {
                $texts = array_map(static fn (array $node): string => implode('', array_map(static fn (array $run): string => (string) ($run['text'] ?? ''), $node['data']['content'] ?? [])), $region['slots']['default'] ?? []);
                if (!kitSame($texts, $rule['texts'])) {
                    $failures[] = $rule['language'] . ' ' . $rule['area'] . ': texts ' . json_encode($texts, JSON_UNESCAPED_UNICODE) . ', expected ' . json_encode($rule['texts'], JSON_UNESCAPED_UNICODE);
                }
            }
            $listed = $answer['data']['areas'][$rule['area']] ?? null;
            if (is_array($listed) && isset($rule['mark'])) {
                foreach ($rule['mark'] as $key => $value) {
                    if (!kitSame($listed[$key] ?? null, $value)) {
                        $failures[] = 'areas.' . $rule['area'] . ' disagrees with the mark in the document';
                    }
                }
            }
            continue;
        }
        $value = kitAt($answer, $rule['path']);
        $shown = json_encode($value === KitMissing::class ? null : $value, JSON_UNESCAPED_UNICODE);
        if (array_key_exists('equals', $rule) && ($value === KitMissing::class || !kitSame($value, $rule['equals']))) {
            $failures[] = $rule['path'] . ' is ' . substr((string) $shown, 0, 300) . ', expected ' . substr((string) json_encode($rule['equals'], JSON_UNESCAPED_UNICODE), 0, 300);
        }
        if (array_key_exists('not_equals', $rule) && $value !== KitMissing::class && kitSame($value, $rule['not_equals'])) {
            $failures[] = $rule['path'] . ' must not be ' . json_encode($rule['not_equals']);
        }
        if (array_key_exists('not_in', $rule) && $value !== KitMissing::class) {
            foreach ($rule['not_in'] as $one) {
                if (kitSame($value, $one)) {
                    $failures[] = $rule['path'] . ' is ' . $shown . ', one of ' . json_encode($rule['not_in']);
                }
            }
        }
        if (($rule['absent'] ?? false) === true && $value !== KitMissing::class) {
            $failures[] = $rule['path'] . ' must be absent';
        }
        if (array_key_exists('at_most', $rule) && !(is_int($value) && $value <= $rule['at_most'])) {
            $failures[] = $rule['path'] . ' is ' . $shown . ', more than ' . $rule['at_most'];
        }
        if (array_key_exists('length', $rule) && !(is_array($value) && count($value) === $rule['length'])) {
            $failures[] = $rule['path'] . ' has ' . (is_array($value) ? count($value) : 'no') . ' entries, expected ' . $rule['length'];
        }
        if (array_key_exists('equals_step', $rule)) {
            $expected = kitAt($steps[$rule['equals_step']] ?? [], $rule['step_path']);
            if ($value === KitMissing::class || !kitSame($value, $expected)) {
                $failures[] = $rule['path'] . ' is ' . $shown . ', expected the ' . $rule['equals_step'] . ' step\'s ' . $rule['step_path'] . ' ' . json_encode($expected);
            }
        }
    }

    return $failures;
}

/** Raises an intent as the browser would: the payload as JSON, the answer as JSON. @return array<string, mixed> */
function kitSend(DocumentPort $port, string $intent, object $payload): array
{
    $read = DocumentPortJson::payload((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $answer = isset($read['answer']) ? $read['answer'] : $port->send($intent, $read['payload']);

    return json_decode(DocumentPortJson::answer($answer), true, 512, JSON_THROW_ON_ERROR);
}

$passed = 0;
$failed = [];
foreach ($manifest['cases'] as $id) {
    $kase = $read($at . '/kit/cases/' . $id . '.json');
    $objects = $read($at . '/kit/cases/' . $id . '.json', true);
    $host = kitHost($kase['given']);
    $failures = [];
    $steps = [];
    $answer = kitSend($host['port'], $kase['when']['intent'], $objects->when->payload ?? new stdClass());
    $steps['when'] = $answer;
    $failures = kitCheck($answer, $kase['then'], $steps);
    foreach ($kase['after'] ?? [] as $index => $step) {
        $payload = clone ($objects->after[$index]->when->payload ?? new stdClass());
        foreach ($step['when']['bind'] ?? [] as $key => $bind) {
            $payload->{$key} = kitAt($steps[$bind['step']] ?? [], $bind['path']);
        }
        $next = kitSend($host['port'], $step['when']['intent'], $payload);
        $steps['after.' . ($index + 1)] = $next;
        foreach (kitCheck($next, $step['then'], $steps) as $failure) {
            $failures[] = 'after ' . ($index + 1) . ' (' . $step['when']['intent'] . '): ' . $failure;
        }
    }
    // Every reason answered is one of the port's codes.
    foreach ($steps as $one) {
        if (isset($one['reason']) && !in_array($one['reason'], $reasons, true)) {
            $failures[] = 'reason ' . $one['reason'] . ' is not the port\'s';
        }
    }
    if ($failures === []) {
        $passed++;
    } else {
        $failed[$id] = $failures;
    }
}
foreach ($failed as $id => $failures) {
    fwrite(STDERR, 'FAIL ' . $id . PHP_EOL . '     ' . implode(PHP_EOL . '     ', $failures) . PHP_EOL);
}
$expect($failed === [] && $passed === count($manifest['cases']), $passed . ' of ' . count($manifest['cases']) . ' kit cases passed');

// --- The binding as a request makes it: no session, a reader who may not read, the rights Access gives. ---
$kase = $read($at . '/kit/cases/load-answers-every-language-under-one-revision.json');
$host = kitHost($kase['given'] + ['capabilities' => []]);
$ports = $host['ports'];
$expect($ports->open($host['binding'], null)->send('document.load') === ['answer' => 'refused', 'reason' => 'session-expired', 'message' => 'refused: session-expired'], 'no session is session-expired');
$expect($ports->open($host['binding'], 'actor:someone:else')->send('document.save', [])['reason'] === 'access-denied', 'a reader Access refuses is access-denied');
$opened = $ports->open($host['binding'], KIT_ACTOR);
$loaded = $opened->send('document.load');
$expect($loaded['answer'] === 'applied' && $loaded['data']['rights'] === ['save' => true, 'publish' => false] && !array_key_exists('published', $loaded['data'])
    && $loaded['data']['limits'] === ['max_nodes' => 200, 'max_bytes' => 262144], 'the rights and limits Access and the type give: ' . json_encode($loaded['data']['rights'] ?? null));
$expect($opened->send('document.resolve', ['values' => $kase['given']['values']])['reason'] === 'capability-not-provided', 'levels is not announced yet');
$malformed = new DocumentPortBinding(KIT_SCOPE, 'workbench.pages', 'not a record', 'document', null, $kase['given']['target'], ['ru', 'en']);
$expect(($ports->open($malformed, KIT_ACTOR)->send('document.load')['reason'] ?? null) === 'not-found', 'a record id the workbench does not accept');
$missing = new DocumentPortBinding(KIT_SCOPE, 'workbench.pages', 'record-00000000000000000000000000000000', 'document', null, $kase['given']['target'], ['ru', 'en']);
$expect(($ports->open($missing, KIT_ACTOR)->send('document.load')['reason'] ?? null) === 'not-found', 'a record that is not there');
// Own codes are the port's codes.
$expect(DocumentPort::reason('area_locked') === 'area-locked' && DocumentPort::reason('document_access_denied') === 'access-denied'
    && DocumentPort::reason('storage_workbench_scope_denied') === 'access-denied' && DocumentPort::reason('storage_workbench_record_values_too_large') === 'too-large'
    && DocumentPort::reason('storage_persistence_failed') === 'storage-unavailable', 'own codes are translated');

// --- JSON: an empty object the document declares stays {}; one it does not declare is refused would-lose. ---
$document = $kase['given']['values']['ru'];
$document['root']['slots']['default'][0]['slots']['regions'][0]['props'] = ['name' => 'main', 'x-empty' => []];
$plain = (string) json_encode(['values' => ['ru' => $document], 'expected_revision' => '7'], JSON_UNESCAPED_UNICODE);
$expect(isset(DocumentPortJson::payload($plain)['payload']), 'an empty list is kept');
$lost = DocumentPortJson::payload(str_replace('"x-empty":[]', '"x-empty":{}', $plain));
$expect(($lost['answer']['reason'] ?? null) === 'would-lose' && ($lost['answer']['data']['diagnostics'][0]['language'] ?? null) === 'ru', 'an undeclared empty object is would-lose');
$expect(DocumentPortJson::payload('[1]')['answer']['reason'] === 'invalid' && DocumentPortJson::payload('{')['answer']['reason'] === 'invalid', 'a payload that is not an object');
$written = DocumentPortJson::answer(['answer' => 'applied', 'data' => ['revision' => '8', 'values' => ['ru' => ['id' => 'page', 'root' => ['id' => 'r', 'type' => 'layout.page', 'props' => []]]]]]);
$expect(str_contains($written, '"props":{}'), 'an answer writes the declared empty object as {}');

echo 'DocumentPortKitTest passed: ' . $passed . ' of ' . count($manifest['cases']) . ' kit cases, ' . $GLOBALS['kitChecks'] . " checks\n";
