<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\StorageListOrder;
use Larena\Storage\Admin\StorageSectionGateway;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Filesystem\ValueObjects\PersistentLogicalFileInspection;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchPurgeReceipt;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordPage;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\StorageRejected;

/** Denies every operation listed for an actor; allows the rest. */
final class StorageSectionTestAuthorizer implements ActorOperationAuthorizer
{
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string, list<string>> $denied */
    public function __construct(private array $denied = [])
    {
    }

    public function assertAllowed(string $actor, string $operation): void
    {
        $this->asked[] = $operation;
        if (in_array($operation, $this->denied[$actor] ?? [], true)) {
            throw new AccessMutationRejected('access_denied');
        }
    }
}

final class StorageSectionTestScope implements StorageSectionScope
{
    public function scopeForActor(string $actor): string
    {
        return match ($actor) {
            'user:admin_identity:1', 'user:admin_identity:2' => 'scope:alpha',
            default => throw new RuntimeException(StorageSectionScope::SCOPE_DENIED),
        };
    }

    public function fileBelongsToScope(string $logicalRef, string $scopeRef): bool
    {
        return false;
    }
}

final class StorageSectionTestInspector implements PersistentLogicalFileInspector
{
    public function inspect(string $logicalRef): PersistentLogicalFileInspection
    {
        throw new RuntimeException('not_used');
    }
}

/** An in-memory workbench for one scope: enough Storage to drive the section. */
final class StorageSectionTestWorkbench implements StorageWorkbench
{
    /** @var array<string, StorageWorkbenchStructure> */
    public array $structures = [];
    /** @var list<StorageWorkbenchCountMode> */
    private array $modes = [];
    /** Answer lists with an estimated count, as Storage does above its threshold. */
    public bool $approximate = false;
    /** @var list<string> */
    public array $sortUnindexed = [];
    public bool $large = false;
    /** @var list<array<array-key, mixed>> the sorts of the lists asked */
    private array $sorts = [];
    /** @var array<string, array<string, StorageWorkbenchRecord>> */
    public array $records = [];

    public function filterOperators(): array
    {
        return [];
    }

    public function createStructure(string $scopeRef, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $id = (string) $descriptor['structure_id'];
        if (isset($this->structures[$id])) {
            throw new StorageRejected('storage_structure_exists');
        }

        return $this->structures[$id] = $this->structure($scopeRef, $id, (string) $descriptor['label'], $descriptor['fields'], 1, null);
    }

    /** The reason Storage refuses the next structure change with, if any. */
    public ?string $refuseChange = null;
    /** @var list<string> How each structure change went: update (records kept) or migrate. */
    private array $changes = [];

    /**
     * How the structure changes since the last call went; taking them clears them.
     *
     * @phpstan-impure
     * @return list<string>
     */
    public function takeChanges(): array
    {
        [$changes, $this->changes] = [$this->changes, []];

        return $changes;
    }

    public function updateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->change('update', $scopeRef, $structureId, $expectedVersion, $descriptor);
    }

    public function migrateStructure(string $scopeRef, string $structureId, int $expectedVersion, array $descriptor, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->change('migrate', $scopeRef, $structureId, $expectedVersion, $descriptor);
    }

    /** @param array<string, mixed> $descriptor */
    private function change(string $how, string $scopeRef, string $structureId, int $expectedVersion, array $descriptor): StorageWorkbenchStructure
    {
        $current = $this->structures[$structureId] ?? throw new StorageRejected('storage_structure_missing');
        if ($this->refuseChange !== null) {
            [$reason, $this->refuseChange] = [$this->refuseChange, null];
            throw new StorageRejected($reason);
        }
        $this->changes[] = $how;

        return $this->structures[$structureId] = $this->structure($scopeRef, $structureId, (string) $descriptor['label'],
            $descriptor['fields'], $expectedVersion + 1, $current->archivedAt);
    }

    public function readStructure(string $scopeRef, string $structureId, string $actor): StorageWorkbenchStructure
    {
        return $this->structures[$structureId] ?? throw new StorageRejected('storage_structure_missing');
    }

    public function listStructures(string $scopeRef, string $actor, bool $includeArchived = false): array
    {
        return array_values(array_filter($this->structures, static fn (StorageWorkbenchStructure $s): bool => $includeArchived || !$s->isArchived()));
    }

    public function setHistoryKeep(string $scopeRef, string $structureId, ?int $keep, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        return $this->readStructure($scopeRef, $structureId, $actor);
    }

    /** @var list<string> The structures whose field change was cancelled. */
    private array $cancelled = [];

    /**
     * The structures whose field change was cancelled since the last call; taking them clears them.
     *
     * @phpstan-impure
     * @return list<string>
     */
    public function takeCancelled(): array
    {
        [$cancelled, $this->cancelled] = [$this->cancelled, []];

        return $cancelled;
    }

    public function cancelFieldChange(string $scopeRef, string $structureId, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $current = $this->readStructure($scopeRef, $structureId, $actor);
        if ($this->refuseChange !== null) {
            [$reason, $this->refuseChange] = [$this->refuseChange, null];
            throw new StorageRejected($reason);
        }
        $this->cancelled[] = $structureId;

        return $current;
    }

    public function archiveStructure(string $scopeRef, string $structureId, int $expectedVersion, string $actor, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $current = $this->readStructure($scopeRef, $structureId, $actor);

        return $this->structures[$structureId] = $this->structure($scopeRef, $structureId, $current->label, $current->fields, $current->version + 1, '2026-10-08T00:00:00Z');
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
        $id = 'record-' . str_pad(dechex(count($this->records[$structureId] ?? []) + 1), 32, '0', STR_PAD_LEFT);

        return $this->records[$structureId][$id] = new StorageWorkbenchRecord(
            $structureId, $scopeRef, $id, 1, 1, 'active', $values, hash('sha256', json_encode($values, JSON_THROW_ON_ERROR)), 'create', '2026-10-08T00:00:00Z', $actor,
        );
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

    /** The reason Storage refuses lists (and permanent deletions) with, if any: a list still being built. */
    public ?string $refuseList = null;

    public function purgeRecords(string $scopeRef, string $structureId, array $expectedRevisions, string $actor, ?string $correlationId = null): StorageWorkbenchPurgeReceipt
    {
        throw new StorageRejected($this->refuseList ?? 'not_used');
    }

    public function readRecord(string $scopeRef, string $structureId, string $recordId, string $actor): StorageWorkbenchRecord
    {
        return $this->records[$structureId][$recordId] ?? throw new StorageRejected('storage_record_missing');
    }

    public function listRecords(StorageWorkbenchRecordQuery $query, string $actor): StorageWorkbenchRecordPage
    {
        if ($this->refuseList !== null) {
            throw new StorageRejected($this->refuseList);
        }
        $this->modes[] = $query->countMode;
        $this->sorts[] = $query->sort;
        $items = array_values($this->records[$query->structureId] ?? []);
        $approximate = $this->approximate && $query->countMode === StorageWorkbenchCountMode::Auto;

        return new StorageWorkbenchRecordPage($items, null, $approximate ? 12345 : count($items), !$approximate,
            $query->sort === [] ? [] : $this->sortUnindexed, $this->large);
    }

    /**
     * The sorts of the lists asked since the last call.
     *
     * @phpstan-impure
     * @return list<array<array-key, mixed>>
     */
    public function takeSorts(): array
    {
        $sorts = $this->sorts;
        $this->sorts = [];

        return $sorts;
    }

    /**
     * The count modes of the lists asked since the last call.
     *
     * @phpstan-impure
     * @return list<StorageWorkbenchCountMode>
     */
    public function takeModes(): array
    {
        $modes = $this->modes;
        $this->modes = [];

        return $modes;
    }

    public function recordHistory(string $scopeRef, string $structureId, string $recordId, string $actor, int $limit = 50): array
    {
        return [$this->readRecord($scopeRef, $structureId, $recordId, $actor)];
    }

    /** @param list<array<string, mixed>> $fields */
    private function structure(string $scopeRef, string $id, string $label, array $fields, int $version, ?string $archivedAt): StorageWorkbenchStructure
    {
        return new StorageWorkbenchStructure(
            $id, $scopeRef, $version, new StorageSchemaVersionRef($id, 1), $label, $fields,
            hash('sha256', $id . $version), '2026-10-08T00:00:00Z', archivedAt: $archivedAt,
        );
    }
}

$admin = 'user:admin_identity:1';
$reader = 'user:admin_identity:2';
$authorizer = new StorageSectionTestAuthorizer([
    'user:admin_identity:2' => ['storage.workbench.structure.create', 'storage.workbench.record.create'],
    'user:admin_identity:3' => ['admin.frontend.read'],
]);
$workbench = new StorageSectionTestWorkbench();
$gateway = new StorageSectionGateway($authorizer, new StorageSectionTestScope(), $workbench, new StorageSectionTestInspector());
$section = ['surface' => StorageSectionGateway::SURFACE];

// An empty section answers in full shape with the actor's storage permissions.
$empty = $gateway->snapshot($admin, null, $section);
assert($empty['status'] === 'ok' && $empty['reason_code'] === 'storage_workbench_ready');
assert($empty['scope_ref'] === 'scope:alpha' && $empty['structures'] === [] && $empty['selected_structure'] === null);
assert($empty['records'] === ['items' => [], 'continuation' => null, 'matched_count' => 0, 'matched_count_exact' => true,
    'sort_unindexed' => [], 'large' => false, 'sort_default' => false, 'history' => []]);
assert($empty['files'] === ['nodes' => []] && $empty['documents'] === [] && $empty['pages'] === [] && $empty['publication'] === null);
assert(array_keys($empty['permissions']) === [
    'structure.create', 'structure.create_preset', 'structure.unbind_role', 'structure.update', 'record.create', 'record.update', 'record.revert', 'record.archive', 'record.bulk_archive',
    'record.restore', 'record.purge', 'structure.configure', 'structure.history_keep', 'structure.migrate', 'structure.archive', 'structure.restore', 'structure.purge',
    'sitepack.export', 'sitepack.import', 'publication.publish', 'encryption.manage', 'record.decrypt',
]);
assert(!in_array(false, $empty['permissions'], true));
assert($empty['limits']['structures'] === 100 && $empty['limits']['records_per_page'] === 100);
// The section never asks for the workbench's file, content or page permissions; besides the workbench's own it asks
// only whether the person may move a storage to another site (storage.sitepack.export), bring one from another site
// (storage.sitepack.import), keep the publication of what it brings (storage.publication.publish) and manage encryption
// (storage.encryption.manage: «Шифрование», «Повторить»).
assert(array_filter($authorizer->asked, static fn (string $op): bool => !str_starts_with($op, 'storage.workbench.')
    && !in_array($op, ['admin.frontend.read', 'storage.sitepack.export', 'storage.sitepack.import', 'storage.publication.publish', 'storage.encryption.manage'], true)) === []);
$readerState = $gateway->snapshot($reader, 'scope:alpha', $section);
assert($readerState['status'] === 'ok' && $readerState['permissions']['structure.create'] === false && $readerState['permissions']['record.create'] === false);
assert($readerState['permissions']['record.update'] === true);

// No admin access, another scope, or no scope at all: denied without any projection.
$denied = $gateway->snapshot('user:admin_identity:3', null, $section);
assert($denied['status'] === 'denied' && $denied['reason_code'] === 'storage_workbench_access_denied' && $denied['scope_ref'] === null);
assert($denied['permissions'] === [] && $denied['structures'] === []);
$mismatch = $gateway->snapshot($admin, 'scope:beta', $section);
assert($mismatch['status'] === 'denied' && $mismatch['reason_code'] === 'storage_workbench_scope_denied' && $mismatch['scope_ref'] === null);
$noScope = $gateway->snapshot('user:admin_identity:9', null, $section);
assert($noScope['status'] === 'denied' && $noScope['reason_code'] === 'storage_workbench_scope_denied');
$mismatchReceipt = $gateway->dispatch($admin, 'scope:beta', 'structure.create', []);
assert($mismatchReceipt === ['status' => 'denied', 'reason_code' => 'storage_workbench_scope_denied', 'scope_ref' => null, 'notice' => 'storage_workbench_scope_denied', 'redirect' => []]);
$deniedReceipt = $gateway->dispatch('user:admin_identity:3', null, 'record.create', []);
assert($deniedReceipt['status'] === 'denied' && $deniedReceipt['reason_code'] === 'storage_workbench_access_denied');

// Another surface, or a malformed query, is a rejected request.
$workbenchSurface = $gateway->snapshot($admin, null, []);
assert($workbenchSurface['status'] === 'rejected' && $workbenchSurface['reason_code'] === 'storage_workbench_request_rejected');
assert($workbenchSurface['files'] === ['nodes' => []] && $workbenchSurface['permissions'] === []);
$invalid = $gateway->snapshot($admin, null, $section + ['input_invalid' => true]);
assert($invalid['status'] === 'rejected' && $invalid['reason_code'] === 'storage_workbench_request_rejected');

// A storage, then a record in it: the receipts redirect to what was written.
$created = $gateway->dispatch($admin, 'scope:alpha', 'structure.create', [
    'structure_id' => 'workbench.articles',
    'label' => 'Articles',
    'fields' => [
        ['key' => 'title', 'label' => 'Title', 'position' => 0, 'type' => 'string', 'required' => '1'],
        ['key' => 'views', 'label' => 'Views', 'position' => 1, 'type' => 'integer'],
        ['key' => '', 'label' => ''],
    ],
]);
assert($created === [
    'status' => 'ok',
    'reason_code' => 'storage_workbench_action_applied',
    'scope_ref' => 'scope:alpha',
    'notice' => 'storage_workbench_action_applied',
    'redirect' => ['scope_ref' => 'scope:alpha', 'structure_id' => 'workbench.articles'],
]);
$stored = $workbench->structures['workbench.articles'];
assert(count($stored->fields) === 2 && $stored->fields[0]['required'] === true && $stored->fields[0]['visibility'] === 'public');
$record = $gateway->dispatch($admin, null, 'record.create', [
    'structure_id' => 'workbench.articles',
    'values' => ['title' => ' Hello ', 'views' => '42'],
]);
assert($record['status'] === 'ok' && $record['scope_ref'] === 'scope:alpha');
$recordId = $record['redirect']['record_id'];
assert($record['redirect'] === ['scope_ref' => 'scope:alpha', 'structure_id' => 'workbench.articles', 'record_id' => $recordId]);
assert($workbench->records['workbench.articles'][$recordId]->values === ['title' => 'Hello', 'views' => 42]);
// A value one field cannot take is refused with that field and why (QA 2026-10-22: never the bare «not created»).
$missingRequired = $gateway->dispatch($admin, 'scope:alpha', 'record.create', ['structure_id' => 'workbench.articles', 'values' => ['views' => '1']]);
assert($missingRequired === ['status' => 'rejected', 'reason_code' => StorageSectionGateway::FIELD_VALUE_INVALID, 'scope_ref' => 'scope:alpha',
    'notice' => StorageSectionGateway::FIELD_VALUE_INVALID, 'redirect' => [], 'field' => 'title', 'field_reason' => 'storage_workbench_required_field_missing']);
$notInteger = $gateway->dispatch($admin, null, 'record.create', ['structure_id' => 'workbench.articles', 'values' => ['title' => 'A', 'views' => '1,5']]);
assert($notInteger['reason_code'] === StorageSectionGateway::FIELD_VALUE_INVALID && $notInteger['field'] === 'views'
    && StorageSectionGateway::valueErrorKey($notInteger['field_reason']) === 'integer');
// The section's key words are its own: a key such as «secret» or «password» is refused in words that say so.
$unsafe = $gateway->dispatch($admin, null, 'structure.create', [
    'structure_id' => 'workbench.secrets', 'label' => 'Secrets',
    'fields' => [['key' => 'password', 'label' => 'Password', 'position' => 0, 'type' => 'string']],
]);
assert($unsafe['status'] === 'rejected' && $unsafe['reason_code'] === 'storage_workbench_key_unsafe' && $unsafe['scope_ref'] === null);


// The selected storage carries its records and the asked record's history.
$selected = $gateway->snapshot($admin, null, $section + ['structure_id' => 'workbench.articles', 'record_id' => $recordId]);
assert($selected['status'] === 'ok' && $selected['selected_structure']['structure_id'] === 'workbench.articles');
assert($selected['records']['matched_count'] === 1 && $selected['records']['items'][0]['record_id'] === $recordId);
assert($selected['records']['items'][0]['values'] === ['title' => 'Hello', 'views' => 42] && count($selected['records']['history']) === 1);
assert($selected['records']['matched_count_exact'] === true && $workbench->takeModes() === [StorageWorkbenchCountMode::Auto]);

// A large list is counted approximately; asked with count = exact it is counted exactly.
$workbench->approximate = true;
$estimated = $gateway->snapshot($admin, null, $section + ['structure_id' => 'workbench.articles']);
assert($estimated['records']['matched_count'] === 12345 && $estimated['records']['matched_count_exact'] === false);
$counted = $gateway->snapshot($admin, null, $section + ['structure_id' => 'workbench.articles', 'count' => 'exact']);
assert($counted['records']['matched_count'] === 1 && $counted['records']['matched_count_exact'] === true);
assert($workbench->takeModes() === [StorageWorkbenchCountMode::Auto, StorageWorkbenchCountMode::Exact]);
// «Archive all matching» compares the confirmed number with an exact count, never an estimate.
$gateway->dispatch($admin, 'scope:alpha', 'record.bulk_archive_matching', ['structure_id' => 'workbench.articles', 'confirmed' => '1', 'expected_count' => 1]);
assert($workbench->takeModes() === [StorageWorkbenchCountMode::Exact]);
// Quick search shows the first matches and does not count.
$gateway->searchRecords($admin, null, 'hello', 5);
assert($workbench->takeModes() === [StorageWorkbenchCountMode::Skip]);
$workbench->approximate = false;

// A sort Storage has no index for, on a large list, travels with the records for the page's hint.
assert($selected['records']['sort_unindexed'] === [] && $selected['records']['large'] === false);
$workbench->sortUnindexed = ['title'];
$workbench->large = true;
$slow = $gateway->snapshot($admin, null, $section + ['structure_id' => 'workbench.articles', 'sort' => [['field' => 'title', 'direction' => 'asc']]]);
assert($slow['records']['sort_unindexed'] === ['title'] && $slow['records']['large'] === true);
$workbench->sortUnindexed = [];
$workbench->large = false;
$workbench->takeModes();

// A list nobody sorted is in its own order, newest first (`_created_at` desc), and says it is that default; a sort the
// person asks for, the creation time in another direction included, is passed on as asked and is not the default.
$workbench->takeSorts();
$unsorted = $gateway->snapshot($admin, null, $section + ['structure_id' => 'workbench.articles']);
$emptySort = $gateway->snapshot($admin, null, $section + ['structure_id' => 'workbench.articles', 'sort' => []]);
assert($unsorted['records']['sort_default'] === true && $emptySort['records']['sort_default'] === true);
assert($slow['records']['sort_default'] === false);
$byTitle = $gateway->snapshot($admin, null, $section + ['structure_id' => 'workbench.articles', 'sort_field' => 'title', 'sort_direction' => 'desc']);
$oldestFirst = $gateway->snapshot($admin, null, $section + ['structure_id' => 'workbench.articles', 'sort_field' => '_created_at', 'sort_direction' => 'asc']);
assert($byTitle['records']['sort_default'] === false && $oldestFirst['records']['sort_default'] === false);
$sorts = $workbench->takeSorts();
assert($sorts === [
    StorageListOrder::NEWEST_FIRST,
    StorageListOrder::NEWEST_FIRST,
    [['field' => 'title', 'direction' => 'desc']],
    [['field' => '_created_at', 'direction' => 'asc']],
]);
// «Archive all matching» pages through every match by record id, not in the list's order.
$gateway->dispatch($admin, 'scope:alpha', 'record.bulk_archive_matching', ['structure_id' => 'workbench.articles', 'confirmed' => '1', 'expected_count' => 1]);
assert($workbench->takeSorts() === [[['field' => '_record_id', 'direction' => 'asc']]]);
$workbench->takeModes();

// Storage lists records from the storage's list table only. A large storage whose list is still being built, and a
// list that cannot exist here, are unavailable with their own reason, never rejected (a rejected list is asked again
// without its filters); a query Storage cannot answer stays a rejected request.
$listed = $section + ['structure_id' => 'workbench.articles', 'record_id' => $recordId];
foreach ([StorageSectionGateway::VIEW_NOT_READY, StorageSectionGateway::VIEW_UNAVAILABLE] as $reason) {
    $workbench->refuseList = $reason;
    $notReady = $gateway->snapshot($admin, null, $listed);
    assert($notReady['status'] === 'unavailable' && $notReady['reason_code'] === $reason && $notReady['selected_structure'] === null, $reason);
}
$workbench->refuseList = 'storage_workbench_record_query_unsupported';
$unsupported = $gateway->snapshot($admin, null, $listed + ['sort' => [['field' => 'title', 'direction' => 'asc']]]);
assert($unsupported['status'] === 'rejected' && $unsupported['reason_code'] === 'storage_workbench_request_rejected');
// A page that needs the storage and not its list asks with records = skip: it opens meanwhile, with the asked record.
$workbench->refuseList = StorageSectionGateway::VIEW_NOT_READY;
$withoutList = $gateway->snapshot($admin, null, $listed + ['records' => 'skip']);
assert($withoutList['status'] === 'ok' && $withoutList['selected_structure']['structure_id'] === 'workbench.articles');
assert($withoutList['records']['items'] === [] && $withoutList['records']['matched_count'] === 0 && count($withoutList['records']['history']) === 1);
assert($withoutList['permissions']['record.purge'] === true);
// A permanent deletion refused while a referencing storage's list is built passes its reason to the page.
$purgeWaits = $gateway->dispatch($admin, null, 'record.purge', ['structure_id' => 'workbench.articles', 'confirmed' => '1',
    'expected_revisions' => [$recordId => 1]]);
assert($purgeWaits === ['status' => 'rejected', 'reason_code' => StorageSectionGateway::VIEW_NOT_READY, 'scope_ref' => null,
    'notice' => StorageSectionGateway::VIEW_NOT_READY, 'redirect' => []]);
// Quick search leaves out a storage whose list is not ready and does not fail.
assert($gateway->searchRecords($admin, null, 'hello', 5) === []);
$workbench->refuseList = null;
$workbench->takeModes();

// Workbench actions and unknown actions are not the section's: rejected after access and scope.
foreach (['file.upload', 'content.create', 'layout.update', 'publication.publish', 'storage.drop'] as $action) {
    $receipt = $gateway->dispatch($admin, 'scope:alpha', $action, []);
    assert($receipt === ['status' => 'rejected', 'reason_code' => 'storage_workbench_action_rejected', 'scope_ref' => 'scope:alpha', 'notice' => 'storage_workbench_action_rejected', 'redirect' => []]);
}
assert($gateway->dispatch('user:admin_identity:3', null, 'file.upload', [])['reason_code'] === 'storage_workbench_access_denied');

// Search: blank or control input finds nothing; archived storages are left out.
$gateway->dispatch($admin, null, 'structure.create', [
    'structure_id' => 'workbench.article_archive', 'label' => 'Old articles',
    'fields' => [['key' => 'title', 'label' => 'Title', 'position' => 0, 'type' => 'string']],
]);
$both = $gateway->searchStructures($admin, null, 'ARTICLE', 10);
assert(array_column($both, 'structure_id') === ['workbench.articles', 'workbench.article_archive']);
assert($both[0] === ['structure_id' => 'workbench.articles', 'label' => 'Articles', 'field_count' => 2]);
assert(count($gateway->searchStructures($admin, null, 'article', 1)) === 1);
$archived = $gateway->dispatch($admin, null, 'structure.archive', ['structure_id' => 'workbench.article_archive', 'expected_version' => 1, 'confirmed' => '1']);
assert($archived['status'] === 'ok' && $archived['redirect'] === ['scope_ref' => 'scope:alpha', 'structure_id' => 'workbench.article_archive']);
assert(array_column($gateway->searchStructures($admin, null, 'article', 10), 'structure_id') === ['workbench.articles']);
$withArchived = $gateway->snapshot($admin, null, $section + ['include_archived_structures' => true]);
assert(count($withArchived['structures']) === 2 && $withArchived['structures'][1]['archived'] === true);
assert($gateway->searchStructures($admin, null, '   ', 10) === []);
assert($gateway->searchStructures($admin, null, "art\x01", 10) === []);
assert($gateway->searchStructures($admin, 'scope:beta', 'article', 10) === []);
assert($gateway->searchStructures('user:admin_identity:3', null, 'article', 10) === []);

// A field's search and index options, kept as the hidden flag is: a change that names one sets it, one that
// does not leaves it as stored; only a departure from the default is written, and never as a migration.
$gateway->dispatch($admin, null, 'structure.create', [
    'structure_id' => 'workbench.catalog', 'label' => 'Catalog',
    'fields' => [['key' => 'title', 'label' => 'Title', 'position' => 0, 'type' => 'string'], ['key' => 'body', 'label' => 'Body', 'position' => 1, 'type' => 'text']],
]);
$catalog = $workbench->structures['workbench.catalog'];
$workbench->structures['workbench.catalog'] = new StorageWorkbenchStructure('workbench.catalog', 'scope:alpha', 1, $catalog->schema, 'Catalog',
    [['constraints' => ['max_length' => 120]] + $catalog->fields[0], $catalog->fields[1]], $catalog->descriptorHash, $catalog->updatedAt);
$configure = static function (array $fields) use ($gateway, $admin, $workbench): array {
    $receipt = $gateway->dispatch($admin, null, 'structure.configure', ['structure_id' => 'workbench.catalog', 'label' => 'Catalog',
        'expected_version' => $workbench->structures['workbench.catalog']->version, 'fields' => $fields]);

    return [$receipt, array_column($workbench->structures['workbench.catalog']->fields, null, 'key')];
};
[$receipt, $fields] = $configure([['key' => 'title', 'label' => 'Title', 'indexed' => '1', 'searchable' => true], ['key' => 'body', 'label' => 'Body', 'searchable' => '0']]);
assert($receipt['status'] === 'ok' && $workbench->takeChanges() === ['update']);
assert($fields['title']['indexed'] === true && !array_key_exists('searchable', $fields['title']) && $fields['body']['searchable'] === false);
// A change that does not name the options leaves them; turning them back to the default removes them.
[, $fields] = $configure([['key' => 'title', 'label' => 'Name'], ['key' => 'body', 'label' => 'Text']]);
assert($fields['title']['indexed'] === true && $fields['title']['label'] === 'Name' && $fields['body']['searchable'] === false);
[, $fields] = $configure([['key' => 'title', 'label' => 'Name', 'indexed' => false], ['key' => 'body', 'label' => 'Text', 'searchable' => true]]);
assert(!array_key_exists('indexed', $fields['title']) && !array_key_exists('searchable', $fields['body']));
// A retyped field loses the options its new type cannot take.
$configure([['key' => 'title', 'label' => 'Name', 'indexed' => true, 'searchable' => false]]);
$workbench->takeChanges();
[, $fields] = $configure([['key' => 'title', 'label' => 'Name', 'type' => 'integer']]);
assert($workbench->takeChanges() === [] && $fields['title']['indexed'] === true, 'a retype without confirmation changes nothing');
$retyped = $gateway->dispatch($admin, null, 'structure.configure', ['structure_id' => 'workbench.catalog', 'label' => 'Catalog',
    'expected_version' => $workbench->structures['workbench.catalog']->version, 'fields' => [['key' => 'title', 'label' => 'Name', 'type' => 'integer']], 'confirm_migration' => '1']);
$fields = array_column($workbench->structures['workbench.catalog']->fields, null, 'key');
assert($retyped['status'] === 'ok' && $workbench->takeChanges() === ['migrate'] && $fields['title']['type'] === 'integer');
assert(!array_key_exists('indexed', $fields['title']) && !array_key_exists('searchable', $fields['title']));
// A new field takes them too.
[, $fields] = $configure([['key' => 'note', 'label' => 'Note', 'position' => 30, 'type' => 'text', 'searchable' => false]]);
assert($fields['note']['searchable'] === false && !array_key_exists('indexed', $fields['note']));
assert($fields['note']['type_version'] === 1 && $fields['title']['type_version'] === 1, 'text@1 and the retyped integer@1');
// Storage's refusals of the options reach the page by their own reason; other refusals stay general.
foreach (StorageSectionGateway::FIELD_OPTION_REASONS as $reason) {
    $workbench->refuseChange = $reason;
    [$refused] = $configure([['key' => 'note', 'label' => 'Note', 'searchable' => false]]);
    assert($refused['status'] === 'rejected' && $refused['reason_code'] === $reason, $reason);
}
$workbench->refuseChange = 'storage_field_unknown_trouble';
[$refused] = $configure([['key' => 'note', 'label' => 'Note']]);
assert($refused['reason_code'] === 'storage_workbench_action_rejected');

// «Отменить изменение»: a cancel only after the person confirmed it; Storage stops the running change, and one
// that has ended meanwhile is refused by its own reason.
$cancel = static fn (array $payload): array => $gateway->dispatch($admin, null, 'structure.field_change_cancel', $payload);
$unconfirmed = $cancel(['structure_id' => 'workbench.catalog']);
assert($unconfirmed['status'] === 'rejected' && $unconfirmed['reason_code'] === 'storage_workbench_action_rejected' && $workbench->takeCancelled() === []);
$stopped = $cancel(['structure_id' => 'workbench.catalog', 'confirmed' => '1']);
assert($stopped['status'] === 'ok' && $stopped['reason_code'] === StorageSectionGateway::FIELD_CHANGE_CANCELLED
    && $stopped['redirect'] === ['scope_ref' => 'scope:alpha', 'structure_id' => 'workbench.catalog'] && $workbench->takeCancelled() === ['workbench.catalog']);
$workbench->refuseChange = StorageSectionGateway::FIELD_CHANGE_NOT_RUNNING;
$ended = $cancel(['structure_id' => 'workbench.catalog', 'confirmed' => '1']);
assert($ended['status'] === 'rejected' && $ended['reason_code'] === StorageSectionGateway::FIELD_CHANGE_NOT_RUNNING);
assert($cancel(['structure_id' => '../catalog', 'confirmed' => '1'])['status'] === 'rejected' && $workbench->takeCancelled() === []);
assert($gateway->dispatch('user:admin_identity:3', null, 'structure.field_change_cancel', ['structure_id' => 'workbench.catalog', 'confirmed' => '1'])['status'] === 'denied');

// «Число» takes «100,5» and «100.5» alike (and a float, and spaces between thousands) as Property's canonical decimal;
// what is no number is refused under its field (QA 2026-10-22: a comma was dropped silently, a point refused).
$gateway->dispatch($admin, null, 'structure.create', ['structure_id' => 'workbench.prices', 'label' => 'Prices', 'fields' => [
    ['key' => 'title', 'label' => 'Title', 'position' => 0, 'type' => 'string', 'constraints' => ['max_length' => 5]],
    ['key' => 'price', 'label' => 'Price', 'position' => 1, 'type' => 'number'],
]]);
foreach (['100,5' => '100.5', '100.50' => '100.5', '1 000,25' => '1000.25', '-0,0' => '0', '007' => '7', '12345678901234567890.123456789' => '12345678901234567890.123456789'] as $typed => $kept) {
    $priced = $gateway->dispatch($admin, null, 'record.create', ['structure_id' => 'workbench.prices', 'values' => ['price' => $typed]]);
    assert($priced['status'] === 'ok', $typed);
    assert($workbench->records['workbench.prices'][$priced['redirect']['record_id']]->values['price'] === $kept, $typed);
}
$floated = $gateway->dispatch($admin, null, 'record.create', ['structure_id' => 'workbench.prices', 'values' => ['price' => 100.5]]);
assert($workbench->records['workbench.prices'][$floated['redirect']['record_id']]->values['price'] === '100.5');
foreach (['abc', '1,2,3', '1.2,3', 'NaN', ',5'] as $typed) {
    $refusedPrice = $gateway->dispatch($admin, null, 'record.create', ['structure_id' => 'workbench.prices', 'values' => ['price' => $typed]]);
    assert($refusedPrice['reason_code'] === StorageSectionGateway::FIELD_VALUE_INVALID && $refusedPrice['field'] === 'price'
        && StorageSectionGateway::valueErrorKey($refusedPrice['field_reason']) === 'number', $typed);
}
// Property's own refusal (a string longer than its maximum) names the field before Storage is asked.
$long = $gateway->dispatch($admin, null, 'record.create', ['structure_id' => 'workbench.prices', 'values' => ['title' => 'Too long']]);
assert($long['field'] === 'title' && $long['field_reason'] === 'property_string_max_length' && StorageSectionGateway::valueErrorKey($long['field_reason']) === 'too_long');

// The common property types: a link comes as {href, text?} (a bare address is its href), a colour and a phone as typed;
// a link Property refuses is named under its field.
$gateway->dispatch($admin, null, 'structure.create', ['structure_id' => 'workbench.contacts', 'label' => 'Contacts', 'fields' => [
    ['key' => 'title', 'label' => 'Title', 'position' => 0, 'type' => 'string'],
    ['key' => 'more', 'label' => 'More', 'position' => 1, 'type' => 'link'],
    ['key' => 'accent', 'label' => 'Accent', 'position' => 2, 'type' => 'color'],
]]);
$linked = $gateway->dispatch($admin, null, 'record.create', ['structure_id' => 'workbench.contacts',
    'values' => ['title' => 'A', 'more' => ['href' => '/about/', 'text' => 'О нас'], 'accent' => '#f9433a']]);
assert($linked['status'] === 'ok', json_encode($linked));
assert($workbench->records['workbench.contacts'][$linked['redirect']['record_id']]->values['more'] === ['href' => '/about/', 'text' => 'О нас']);
$bare = $gateway->dispatch($admin, null, 'record.create', ['structure_id' => 'workbench.contacts', 'values' => ['title' => 'B', 'more' => '/contacts/']]);
assert($bare['status'] === 'ok' && $workbench->records['workbench.contacts'][$bare['redirect']['record_id']]->values['more'] === '/contacts/');
$hostile = $gateway->dispatch($admin, null, 'record.create', ['structure_id' => 'workbench.contacts', 'values' => ['title' => 'C', 'more' => ['href' => '//evil.example/']]]);
assert($hostile['field'] === 'more' && $hostile['field_reason'] === 'property_link_invalid');
$nested = $gateway->dispatch($admin, null, 'record.create', ['structure_id' => 'workbench.contacts', 'values' => ['title' => 'D', 'more' => ['href' => ['x']]]]);
assert($nested['field'] === 'more' && $nested['reason_code'] === StorageSectionGateway::FIELD_VALUE_INVALID);

// «Максимальная длина» of a string field: given with a new one, changed on an existing one through the migration, refused
// out of range in its own words; a «Storage record» field without a storage to point at says so (QA 2026-10-22).
$configure = static fn (array $fields): array => $gateway->dispatch($admin, null, 'structure.configure', ['structure_id' => 'workbench.prices',
    'label' => 'Prices', 'expected_version' => $workbench->structures['workbench.prices']->version, 'confirm_migration' => '1', 'fields' => $fields]);
$workbench->takeChanges();
assert($configure([['key' => 'code', 'label' => 'Code', 'position' => 20, 'type' => 'string', 'max_length' => '40']])['status'] === 'ok');
$code = array_values(array_filter($workbench->structures['workbench.prices']->fields, static fn (array $field): bool => $field['key'] === 'code'))[0];
assert($code['constraints'] === ['max_length' => 40] && $workbench->takeChanges() === ['update']);
assert($configure([['key' => 'title', 'label' => 'Title', 'max_length' => '200']])['status'] === 'ok');
$titleField = array_values(array_filter($workbench->structures['workbench.prices']->fields, static fn (array $field): bool => $field['key'] === 'title'))[0];
assert($titleField['constraints'] === ['max_length' => 200] && $workbench->takeChanges() === ['migrate']);
// A new field takes its type's current version (string@2, which counts characters), in the form and in the JSON
// structure path that made «title»; an existing one keeps its own.
assert($code['type_version'] === 2 && $titleField['type_version'] === 2);
// The JSON structure path: a field the structure has keeps its version when none is sent, a new one takes the latest.
$gateway->dispatch($admin, null, 'structure.create', ['structure_id' => 'workbench.legacy', 'label' => 'Legacy',
    'fields' => [['key' => 'name', 'label' => 'Name', 'position' => 0, 'type' => 'string', 'type_version' => 1]]]);
assert($workbench->structures['workbench.legacy']->fields[0]['type_version'] === 1);
$legacyUpdate = $gateway->dispatch($admin, null, 'structure.update', ['structure_id' => 'workbench.legacy', 'label' => 'Legacy',
    'expected_version' => $workbench->structures['workbench.legacy']->version, 'fields' => [
        ['key' => 'name', 'label' => 'Name', 'position' => 0, 'type' => 'string'],
        ['key' => 'note', 'label' => 'Note', 'position' => 1, 'type' => 'string'],
    ]]);
assert($legacyUpdate['status'] === 'ok', json_encode($legacyUpdate));
assert(array_column($workbench->structures['workbench.legacy']->fields, 'type_version', 'key') === ['name' => 1, 'note' => 2]);
foreach (['0', '70000', 'many'] as $length) {
    assert($configure([['key' => 'title', 'label' => 'Title', 'max_length' => $length]])['reason_code'] === 'storage_workbench_field_max_length_invalid', $length);
}
assert($configure([['key' => 'section', 'label' => 'Section', 'position' => 30, 'type' => 'record']])['reason_code'] === 'storage_workbench_field_target_invalid');
assert($configure([['key' => 'section', 'label' => 'Section', 'position' => 30, 'type' => 'record', 'target_structure_id' => 'workbench.articles']])['status'] === 'ok');

echo "StorageSectionGatewayTest passed.\n";
