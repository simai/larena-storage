<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Illuminate\Database\Connection;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;

/**
 * Reads a site's redirect rules from the storages bound to site_redirect@v1 in that site: the current ones (what the
 * checks of a write see, drafts included, archived records aside) and the published ones (what the site answers:
 * the published revision of each record, the newest when its languages publish different ones).
 */
final readonly class SiteRedirectRules
{
    private const RECORDS = 'larena_storage_records';

    private const VERSIONS = 'larena_storage_record_versions';

    private const STRUCTURES = 'larena_storage_workbench_structures';

    public function __construct(private Connection $database)
    {
    }

    /** @return list<string> the schemas bound to the role in the site */
    public function schemas(string $siteRef): array
    {
        if (!$this->database->getSchemaBuilder()->hasTable(DatabaseStructureRoleRegistry::BINDINGS_TABLE)) {
            return [];
        }

        return array_values(array_map('strval', $this->database->table(DatabaseStructureRoleRegistry::BINDINGS_TABLE)
            ->where('role_ref', StarterStructureRoles::SITE_REDIRECT_REF)
            ->where('scope_ref', $siteRef)
            ->where('status', 'active')
            ->orderBy('schema_id')
            ->pluck('schema_id')
            ->all()));
    }

    /** Whether the schema holds redirect rules of the site. */
    public function holdsRules(string $schemaId, string $siteRef): bool
    {
        return in_array($schemaId, $this->schemas($siteRef), true);
    }

    /**
     * The workbench storages of the site's rules, oldest first: the first is where a new rule goes.
     *
     * @return list<array{scope_ref: string, structure_id: string, schema_id: string}>
     */
    public function storages(string $siteRef): array
    {
        $schemas = $this->schemas($siteRef);
        if ($schemas === []) {
            return [];
        }
        $storages = [];
        foreach ($this->database->table(self::STRUCTURES)
            ->whereIn('storage_schema_id', $schemas)
            ->whereNull('archived_at')
            ->whereNull('import_run_ref')
            ->orderBy('created_at')
            ->orderBy('structure_id')
            ->get(['scope_ref', 'structure_id', 'storage_schema_id']) as $row) {
            $storages[] = ['scope_ref' => (string) $row->scope_ref, 'structure_id' => (string) $row->structure_id, 'schema_id' => (string) $row->storage_schema_id];
        }

        return $storages;
    }

    /** @return list<SiteRedirectRule> the current rules of the site's active records */
    public function current(string $siteRef): array
    {
        $schemas = $this->schemas($siteRef);
        if ($schemas === []) {
            return [];
        }
        $rows = $this->database->table(self::RECORDS . ' as heads')
            ->join(self::VERSIONS . ' as versions', static function ($join): void {
                $join->on('versions.schema_id', '=', 'heads.schema_id')
                    ->on('versions.record_id', '=', 'heads.record_id')
                    ->on('versions.revision', '=', 'heads.current_revision');
            })
            ->whereIn('heads.schema_id', $schemas)
            ->orderBy('heads.schema_id')
            ->orderBy('heads.record_id')
            ->get(['heads.schema_id', 'heads.record_id', 'versions.values_json']);
        $rules = [];
        foreach ($rows as $row) {
            $values = json_decode((string) $row->values_json, true);
            if (is_array($values) && ($values['larena_record_state'] ?? 'active') === 'active') {
                $rule = SiteRedirectRule::fromValues((string) $row->schema_id, (string) $row->record_id, $values);
                if ($rule !== null) {
                    $rules[] = $rule;
                }
            }
        }

        return $rules;
    }

    /** @return list<SiteRedirectRule> the published rules of the site */
    public function published(string $siteRef): array
    {
        $schemas = $this->schemas($siteRef);
        if ($schemas === [] || !$this->database->getSchemaBuilder()->hasTable(DatabasePublicationLifecycle::STATES_TABLE)) {
            return [];
        }
        $rows = $this->database->table(DatabasePublicationLifecycle::STATES_TABLE . ' as states')
            ->join(self::VERSIONS . ' as versions', static function ($join): void {
                $join->on('versions.schema_id', '=', 'states.schema_id')
                    ->on('versions.record_id', '=', 'states.record_id')
                    ->on('versions.revision', '=', 'states.published_revision');
            })
            ->whereIn('states.schema_id', $schemas)
            ->where('states.scope_ref', $siteRef)
            ->where('states.state', PublicationStateValue::Published->value)
            ->orderBy('states.schema_id')
            ->orderBy('states.record_id')
            ->orderByDesc('states.published_revision')
            ->get(['states.schema_id', 'states.record_id', 'versions.values_json']);
        $rules = [];
        foreach ($rows as $row) {
            $key = $row->schema_id . '|' . $row->record_id;
            if (isset($rules[$key])) {
                continue;
            }
            $values = json_decode((string) $row->values_json, true);
            $rule = is_array($values) ? SiteRedirectRule::fromValues((string) $row->schema_id, (string) $row->record_id, $values) : null;
            if ($rule !== null) {
                $rules[$key] = $rule;
            }
        }

        return array_values($rules);
    }

    /** @return list<string> the languages a record is published in, in the site */
    public function publishedLocales(string $schemaId, string $recordId, string $siteRef): array
    {
        if (!$this->database->getSchemaBuilder()->hasTable(DatabasePublicationLifecycle::STATES_TABLE)) {
            return [];
        }

        return array_values(array_map('strval', $this->database->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->where('schema_id', $schemaId)
            ->where('record_id', $recordId)
            ->where('scope_ref', $siteRef)
            ->where('state', PublicationStateValue::Published->value)
            ->orderBy('locale')
            ->pluck('locale')
            ->all()));
    }
}
