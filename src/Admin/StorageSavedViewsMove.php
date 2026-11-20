<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Database\Connection;
use Larena\Setting\Contracts\ViewSettingRow;
use Larena\Setting\Persistence\DatabaseViewSettingsStore;
use Larena\Setting\ViewConfig\ViewConfigDocument;
use Larena\Setting\ViewConfig\ViewConfigLevel;
use Larena\Setting\ViewConfig\ViewConfigStore;
use stdClass;

/**
 * The one-time move of the Storage lists' saved views into the one view settings store (`larena_view_settings`, as
 * `simai.view-config/1` documents; owner decision C298 and the joint contract, Larena 2026-11-19).
 *
 * Until now they were kept in `larena_setting_values`, namespace `larena_dataview`: per person (scope `user`) and for
 * everyone in a Storage scope (scope `system`), each one list under `list_views`; a list not yet moved from its
 * earlier key is under `saved_views.v2`, and the oldest views of a person (without a layout) under `saved_views`.
 * Both levels move: a person's views become their personal views and the scope's views for everyone its shared
 * views, list by list (`storage/<scope>/<storage>`). A person's views were not kept per scope, so each goes to every
 * scope that has its storage.
 *
 * - The move only adds rows: the old ones are never changed or removed here; they stay until a later cleanup.
 * - It is idempotent: a view already in the store (moved before, or written since) is left as it is.
 * - down() removes the rows the move wrote that nobody has changed since (they carry the mark `updated_by`); a view a
 *   person saved after the move stays, and the old rows read as before.
 * - A view the contract cannot hold (a key longer than it allows, a broken old row) is not moved and is reported;
 *   its old row keeps it. A view larger than 8 KB moves as it is; the limit applies to its next save.
 */
final readonly class StorageSavedViewsMove
{
    public const MARK = 'move:storage-saved-views';
    public const NAMESPACE = 'larena_dataview';
    /** The keys in the order the lists read them: the current one, the one before, the oldest (people's only). */
    public const KEYS = ['list_views', 'saved_views.v2', 'saved_views'];
    private const VALUES = 'larena_setting_values';
    private const STRUCTURES = 'larena_storage_workbench_structures';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{moved: int, present: int, oversized: int, skipped: list<string>}
     */
    public function up(): array
    {
        $report = ['moved' => 0, 'present' => 0, 'oversized' => 0, 'skipped' => []];
        $schema = $this->connection->getSchemaBuilder();
        if (!$schema->hasTable(self::VALUES) || !$schema->hasTable(DatabaseViewSettingsStore::TABLE)) {
            return $report;
        }
        $store = new DatabaseViewSettingsStore($this->connection);
        foreach ($this->owners() as $owner) {
            foreach ($this->ownerViews($owner['scope'], $owner['scope_id']) as $position => $view) {
                $structureId = (string) $view['structure_id'];
                $scopes = $owner['scope'] === 'system' ? [$owner['scope_id']] : $this->scopesOf($structureId);
                if ($scopes === []) {
                    $report['skipped'][] = $owner['scope'].':'.$view['saved_view_id'].':storage_gone';
                    continue;
                }
                $document = StorageViewConfigCodec::document($view);
                $problems = ViewConfigDocument::problems($document);
                if ($problems !== []) {
                    $report['skipped'][] = $owner['scope'].':'.$view['saved_view_id'].':'.$problems[0];
                    continue;
                }
                $report['oversized'] += ViewConfigDocument::bytes($document) > ViewConfigDocument::VIEW_BYTES ? 1 : 0;
                foreach ($scopes as $scope) {
                    $list = DataviewListPreferences::listOf($scope, $structureId);
                    $level = $owner['scope'] === 'system' ? ViewConfigLevel::Shared : ViewConfigLevel::Personal;
                    $person = $level === ViewConfigLevel::Personal ? $owner['scope_id'] : '';
                    $ownerRef = $level === ViewConfigLevel::Personal ? $person.'/'.$document['id'] : (string) $document['id'];
                    if (preg_match(ViewSettingRow::PLACEMENT, $list) !== 1
                        || ($level === ViewConfigLevel::Personal && preg_match(ViewSettingRow::PERSON, $person) !== 1)) {
                        $report['skipped'][] = $owner['scope'].':'.$view['saved_view_id'].':address';
                        continue;
                    }
                    $row = new ViewSettingRow(ViewConfigStore::COMPONENT, $list, $level->layer(), $ownerRef, $document, (int) $document['revision'],
                        $person, mb_substr((string) $document['name'], 0, 120), null, $position, '', ViewConfigDocument::CONTRACT_VERSION);
                    $store->insertIfMissing($row, self::MARK) ? $report['moved']++ : $report['present']++;
                }
            }
        }

        return $report;
    }

    /** Removes what the move wrote and nobody changed since; the old rows were never touched. */
    public function down(): int
    {
        if (!$this->connection->getSchemaBuilder()->hasTable(DatabaseViewSettingsStore::TABLE)) {
            return 0;
        }

        return $this->connection->table(DatabaseViewSettingsStore::TABLE)->where('component', ViewConfigStore::COMPONENT)
            ->where('placement', 'like', 'storage/%')->where('updated_by', self::MARK)->delete();
    }

    /** @return list<array{scope: string, scope_id: string}> who kept saved views: people, and Storage scopes for everyone */
    private function owners(): array
    {
        $owners = [];
        foreach ($this->connection->table(self::VALUES)->where('setting_namespace', self::NAMESPACE)->whereIn('setting_key', self::KEYS)
            ->whereIn('scope', ['user', 'system'])->orderBy('scope')->orderBy('scope_id')->get(['scope', 'scope_id']) as $row) {
            $owners[$row->scope."\0".$row->scope_id] = ['scope' => (string) $row->scope, 'scope_id' => (string) $row->scope_id];
        }

        return array_values($owners);
    }

    /**
     * The views one owner had, from the key the lists read first that holds any (as the lists read them).
     *
     * @return list<array<string, mixed>>
     */
    private function ownerViews(string $scope, string $scopeId): array
    {
        foreach (self::KEYS as $key) {
            if ($key === 'saved_views' && $scope !== 'user') {
                continue;
            }
            $row = $this->connection->table(self::VALUES)->where('setting_namespace', self::NAMESPACE)->where('setting_key', $key)
                ->where('scope', $scope)->where('scope_id', $scopeId)->first();
            if (!$row instanceof stdClass || (property_exists($row, 'revision') && (int) $row->revision < 1)) {
                continue;
            }
            $value = json_decode((string) $row->value, true);
            // The oldest form is a setting value document: {kind, value}.
            if (is_array($value) && !array_is_list($value) && array_key_exists('value', $value)) {
                $value = $value['value'];
            }
            if (!is_array($value) || !array_is_list($value)) {
                continue;
            }
            $views = [];
            foreach ($value as $view) {
                if (!is_array($view) || !is_string($view['saved_view_id'] ?? null) || preg_match('/^saved-view-[a-f0-9]{24}$/D', $view['saved_view_id']) !== 1
                    || !is_string($view['structure_id'] ?? null) || !is_string($view['name'] ?? null) || !is_array($view['query'] ?? null)) {
                    continue;
                }
                $views[] = $view + ['revision' => 1, 'roles' => [], 'layout' => ['columns' => $key === 'saved_views'
                    ? $this->columns($scopeId, (string) $view['structure_id']) : []], 'settings' => []];
            }

            return $views;
        }

        return [];
    }

    /**
     * The columns a person had arranged for a storage: the oldest views took them as their layout.
     *
     * @return array<string, mixed>
     */
    private function columns(string $person, string $structureId): array
    {
        foreach ($this->scopesOf($structureId) as $scope) {
            $row = $this->connection->table(self::VALUES)->where('setting_namespace', self::NAMESPACE)
                ->where('setting_key', 'columns.'.hash('sha256', $scope."\0".$structureId))->where('scope', 'user')->where('scope_id', $person)->first();
            $columns = $row instanceof stdClass ? json_decode((string) $row->value, true) : null;
            if (is_array($columns) && $columns !== [] && !array_is_list($columns)) {
                return $columns;
            }
        }

        return [];
    }

    /** @return list<string> the Storage scopes that have the storage */
    private function scopesOf(string $structureId): array
    {
        if (!$this->connection->getSchemaBuilder()->hasTable(self::STRUCTURES)) {
            return [];
        }

        return array_values(array_map('strval', $this->connection->table(self::STRUCTURES)->where('structure_id', $structureId)
            ->orderBy('scope_ref')->pluck('scope_ref')->all()));
    }
}
