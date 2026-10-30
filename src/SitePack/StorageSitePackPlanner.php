<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Closure;
use Illuminate\Database\Connection;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Contracts\StorageSitePackPlan;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use stdClass;
use Throwable;

/**
 * The dry run of one import: it reads the whole pack (every artifact streamed to its end, so every digest is checked)
 * and decides, without writing anything outside the run tables:
 *
 * - the structure id (the pack's when it is free in the target scope, else `<id>_2`, `<id>_3` …, or the person's);
 * - each record id (kept when no record of the installation holds it, else a new one; `copy` remaps every one);
 * - each file (reuse, create, remap) through StorageSitePackFileImports;
 * - each record of another storage the pack points at (the person's mapping, a record an earlier import of this scope
 *   brought, or the same id in the structure of the same id), each user a user@1 value names (this installation has
 *   it or not);
 *
 * and checks every version as the import will write it: its values rewritten (StorageSitePackValues) and normalized
 * under the new structure's schema, its translations against the localized fields and the declared locales. The
 * decisions go to the run's id map; the counts, warnings and errors make the plan.
 *
 * A pack of a structure that adopted a schema in place (the site pages, `adoption` in its structure line) goes into
 * the target's structure that adopted the same schema, in the target scope (id map: structure `merge`): no new
 * structure, no new schema. Its fields must be the target's (same key, type and type version); a missing one refuses
 * the plan (`storage_sitepack_site_pages_fields_missing`: larena:storage:adopt-site-pages gives the site pages their
 * content fields), and so does a target whose schema is there but not adopted (`storage_sitepack_site_pages_not_adopted`)
 * or adopted in another scope (`storage_sitepack_site_pages_scope_mismatch`). The target holds no record, or only the
 * starter site's Home page as the first run left it (replaced: id map `replaced`, removed when the records are
 * written); any other page refuses it (`storage_sitepack_site_pages_not_empty`). A role the target schema plays
 * already is `already_bound`, never bound twice.
 */
final class StorageSitePackPlanner
{
    private const int CHUNK = 500;

    /** Plan errors of a pack whose structure adopted a schema (the site pages). */
    public const string SITE_PAGES_NOT_EMPTY = 'storage_sitepack_site_pages_not_empty';
    public const string SITE_PAGES_FIELDS_MISSING = 'storage_sitepack_site_pages_fields_missing';
    public const string SITE_PAGES_NOT_ADOPTED = 'storage_sitepack_site_pages_not_adopted';
    public const string SITE_PAGES_SCOPE_MISMATCH = 'storage_sitepack_site_pages_scope_mismatch';

    /** @var array<string, array{code: string, count: int, subject: ?string}> */
    private array $warnings = [];

    /** @var array<string, array{code: string, count: int, subject: ?string}> */
    private array $errors = [];

    /** @var array<string, bool> user ref => exists here */
    private array $users = [];

    /** @var array<string, ?string> source logical ref => target logical ref */
    private array $files = [];

    /** @var array<string, true> */
    private array $locales = [];

    /**
     * @param Closure(string): bool $userExists
     * @param Closure(): void $tick
     */
    public function __construct(
        private readonly Connection $database,
        private readonly StorageSitePackRuns $runs,
        private readonly DatabaseStorageWorkbench $workbench,
        private readonly VersionedStorage $storage,
        private readonly SchemaDefinitionNormalizer $normalizer,
        private readonly PropertyTypeRegistry $propertyTypes,
        private readonly StorageSitePackFileImports $fileImports,
        private readonly ActorOperationAuthorizer $authorizer,
        private readonly ?LocalizedValues $localized,
        private readonly ?PublicationLifecycle $publication,
        private readonly ?StructureRoleRegistry $roles,
        private readonly Closure $userExists,
        private readonly Closure $tick,
        private readonly string $runRef,
        private readonly string $scopeRef,
        private readonly string $siteRef,
        private readonly string $actor,
    ) {
    }

    /**
     * @return array{plan: StorageSitePackPlan, import: array<string, mixed>}
     */
    public function plan(StorageSitePackSource $source, StorageSitePackImportOptions $options): array
    {
        $this->runs->clearIds($this->runRef);
        $structure = $source->structure;
        $sourceId = (string) $structure['id'];

        // --- The pack's other artifacts first: what the records point at. ---
        $references = $this->references($source, $options);
        $assets = $this->assets($source);
        ($this->tick)();

        // --- The structure: the target's adopted one, or a new one. ---
        $adoption = $source->adoption();
        $merge = $adoption !== null && $this->schemaExists($adoption['schema_id']);
        $target = $merge ? $this->mergeTarget($structure, $adoption['schema_id']) : null;
        $schema = null;
        $schemaId = null;
        if ($merge) {
            $schemaId = $adoption['schema_id'];
            $targetId = $target === null ? null : $target['structure']->structureId;
            $schema = $target === null ? null : $target['schema'];
            $descriptor = $target === null
                // No target to check the records against: the pack's own fields, so only the refusal above is said.
                ? ['structure_id' => $sourceId, 'label' => (string) $structure['label'], 'fields' => array_values(array_filter($structure['fields'], 'is_array'))]
                : ['structure_id' => $target['structure']->structureId, 'label' => $target['structure']->label, 'fields' => $target['structure']->fields];
            if ($targetId !== null) {
                $this->runs->addIds([$this->idRow(StorageSitePackRuns::ID_KIND_STRUCTURE, $sourceId, $targetId, 'merge')]);
            }
        } else {
            $targetId = $this->structureId($sourceId, $options);
            $descriptor = $this->descriptor($structure, $targetId ?? $sourceId, $options, $source->containsDecryptedValues());
            $definition = null;
            if ($targetId !== null) {
                try {
                    $definition = $this->workbench->importDefinition($this->scopeRef, $descriptor);
                    $schemaId = $definition['schema_id'];
                    $descriptor = $definition['descriptor'];
                } catch (StorageRejected $exception) {
                    $this->error('structure_invalid', $exception->reasonCode);
                } catch (\InvalidArgumentException $exception) {
                    $this->error('structure_invalid', $exception->getMessage());
                }
                $this->runs->addIds([$this->idRow(StorageSitePackRuns::ID_KIND_STRUCTURE, $sourceId, $targetId, $targetId === $sourceId ? 'keep' : 'remap')]);
            }
            $schema = $definition === null ? null : new StorageSchemaVersion(
                new StorageSchemaVersionRef((string) $schemaId, 1),
                'larena/storage',
                $definition['definition']['fields'],
                hash('sha256', $this->normalizer->canonicalJson($definition['definition'])),
                $this->actor,
                null,
                gmdate('Y-m-d H:i:s'),
            );
        }

        // --- Records, first pass: their ids; the pages a merge replaces. ---
        $records = $this->recordIds($source, $options);
        if ($merge) {
            $records['replaced'] = $target === null ? 0 : $this->replacedRecords((string) $schemaId);
        }
        ($this->tick)();

        // --- Records, second pass: every version as it will be written. ---
        $values = new StorageSitePackValues($structure['fields'], $sourceId,
            StorageSitePackIdMap::listTargets($this->database, $this->scopeRef, $this->runRef, $source->sourceSchemaId(), $schemaId));
        $checked = $this->check($source, $values, $schema, $descriptor);

        // --- Replay, publication, roles, locales, files. ---
        $replay = $this->replay($source->packageId());
        if ($replay !== null && !$options->copy) {
            $this->error('already_imported', $replay['structure_id']);
        }
        if ($options->keepsPublication()) {
            if ($this->publication === null) {
                $this->error('publication_unavailable', null);
            } elseif (!$this->allowed('storage.publication.publish')
                || ($checked['scheduled'] > 0 && !$this->allowed('storage.publication.schedule'))) {
                $this->error('publication_denied', null);
            }
        }
        if ($checked['localized'] > 0 && $this->localized === null) {
            $this->error('localized_unavailable', null);
        }
        $roles = $this->roles($structure, $merge ? $schemaId : null);
        $declared = $options->declaredLocales ?? [];
        $locales = array_keys($this->locales);
        sort($locales, SORT_STRING);
        foreach ($locales as $locale) {
            if (!in_array($locale, $declared, true)) {
                $this->error('locale_not_declared', $locale);
            }
        }
        if ($assets['total'] > 0 && !$this->fileImports->importAvailable()) {
            $this->error('files_unavailable', null);
        }
        $source->verifyBlobs($this->tick);

        $idMap = $this->idMapDigest();
        $plan = new StorageSitePackPlan(
            [
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'kept' => !$merge && $targetId === $sourceId,
                'merge' => $merge,
                'label' => (string) $descriptor['label'],
                'history_keep' => is_int($structure['history_keep'] ?? null) ? $structure['history_keep'] : null,
                'fields' => count($descriptor['fields']),
            ],
            $records + ['versions' => $checked['versions'], 'localized_values' => $checked['localized'], 'relations' => $checked['relations']],
            $assets,
            $locales,
            $references,
            $roles,
            ['mode' => $options->publication, 'published' => $checked['published'], 'scheduled' => $checked['scheduled']],
            ['total' => count($this->users), 'missing' => count(array_filter($this->users, static fn (bool $exists): bool => !$exists))],
            $replay,
            self::listed($this->warnings),
            self::listed($this->errors),
            $options->toArray(),
            $idMap,
        );

        return [
            'plan' => $plan,
            'import' => [
                'source_structure_id' => $sourceId,
                'source_schema_id' => $source->sourceSchemaId(),
                'structure_id' => $targetId,
                'schema_id' => $schemaId,
                'descriptor' => $descriptor,
                'history_keep' => $plan->structure['history_keep'],
                'package_id' => $source->packageId(),
            ] + ($merge && $target !== null ? [
                'merge' => true,
                'schema_version' => $target['schema']->ref->version,
                'owner_prefix' => $target['structure']->recordOwnerPrefix ?? $adoption['owner_prefix'],
                'owner_field' => $target['structure']->recordOwnerField,
            ] : []),
        ];
    }

    private function schemaExists(string $schemaId): bool
    {
        return $this->database->table('larena_storage_schemas')->where('schema_id', $schemaId)->exists();
    }

    /**
     * The target's structure that adopted the schema, and its current schema, when the pack's records may go into it:
     * adopted in the target scope, with every field of the pack (same key, type and type version). Otherwise null,
     * the reason among the errors.
     *
     * @param array<string, mixed> $structure
     * @return array{structure: StorageWorkbenchStructure, schema: StorageSchemaVersion}|null
     */
    private function mergeTarget(array $structure, string $schemaId): ?array
    {
        $head = $this->database->getSchemaBuilder()->hasColumn('larena_storage_workbench_structures', 'adopted_schema_id')
            ? $this->database->table('larena_storage_workbench_structures')->where('adopted_schema_id', $schemaId)->first(['scope_ref', 'structure_id'])
            : null;
        if (!$head instanceof stdClass) {
            $this->error(self::SITE_PAGES_NOT_ADOPTED, $schemaId);

            return null;
        }
        if ((string) $head->scope_ref !== $this->scopeRef) {
            $this->error(self::SITE_PAGES_SCOPE_MISMATCH, (string) $head->scope_ref);

            return null;
        }
        try {
            $target = $this->workbench->readStructure($this->scopeRef, (string) $head->structure_id, $this->actor);
            $schema = $this->storage->schemaVersion($target->schema);
        } catch (StorageRejected $exception) {
            $this->error('structure_invalid', $exception->reasonCode);

            return null;
        }
        $fields = [];
        foreach ($target->fields as $field) {
            $fields[(string) ($field['key'] ?? '')] = $field;
        }
        $missing = false;
        foreach ($structure['fields'] as $field) {
            $key = is_array($field) && is_string($field['key'] ?? null) ? $field['key'] : null;
            $here = $key === null ? null : ($fields[$key] ?? null);
            if ($key === null || $here === null || ($here['type'] ?? null) !== ($field['type'] ?? null)
                || (int) ($here['type_version'] ?? 0) !== (int) ($field['type_version'] ?? 0)) {
                $this->error(self::SITE_PAGES_FIELDS_MISSING, $key);
                $missing = true;
            }
        }

        return $missing ? null : ['structure' => $target, 'schema' => $schema];
    }

    /**
     * The records a merge replaces: none when the target holds none, the starter site's Home page when it is the only
     * record and untouched. Any other record refuses the plan: a site in use is not merged into.
     */
    private function replacedRecords(string $schemaId): int
    {
        $ids = array_map('strval', $this->database->table('larena_storage_records')->where('schema_id', $schemaId)
            ->orderBy('record_id')->limit(2)->pluck('record_id')->all());
        if ($ids === []) {
            return 0;
        }
        if (count($ids) > 1 || !self::untouchedStarterPage($this->database, $schemaId, $ids[0])) {
            $this->error(self::SITE_PAGES_NOT_EMPTY, null);

            return 0;
        }
        $this->runs->addIds([$this->idRow(StorageSitePackRuns::ID_KIND_REPLACED, $ids[0], null, 'replaced')]);

        return 1;
    }

    /**
     * Whether a record is the starter site's Home page as the first run wrote it (StarterSite): its owner ref, one
     * version, the Home slug, and nothing anyone did since: no translation, no publication state (ever published or
     * scheduled), no relation.
     */
    public static function untouchedStarterPage(Connection $database, string $schemaId, string $recordId): bool
    {
        if ($schemaId !== StarterSite::SCHEMA_ID) {
            return false;
        }
        $head = $database->table('larena_storage_records')->where('schema_id', $schemaId)->where('record_id', $recordId)
            ->first(['owner_ref', 'current_revision']);
        if (!$head instanceof stdClass || (string) $head->owner_ref !== StarterSite::RECORD_OWNER_REF
            || (int) $head->current_revision !== 1) {
            return false;
        }
        $versions = $database->table('larena_storage_record_versions')->where('schema_id', $schemaId)->where('record_id', $recordId)
            ->get(['values_json'])->all();
        $values = count($versions) === 1 ? json_decode((string) $versions[0]->values_json, true) : null;
        if (!is_array($values) || ($values['slug'] ?? null) !== StarterSite::HOME_SLUG) {
            return false;
        }
        $schema = $database->getSchemaBuilder();
        foreach (['larena_storage_localized_values', 'larena_storage_publication_states', 'larena_storage_publication_log'] as $table) {
            if ($schema->hasTable($table) && $database->table($table)->where('schema_id', $schemaId)->where('record_id', $recordId)->exists()) {
                return false;
            }
        }

        return !$schema->hasTable('larena_storage_record_relations') || !$database->table('larena_storage_record_relations')
            ->where(static function ($query) use ($recordId): void {
                $query->where('from_record_id', $recordId)->orWhere('to_record_id', $recordId);
            })->exists();
    }

    /**
     * Resolves the records of other storages the pack points at.
     *
     * @return array<string, int>
     */
    private function references(StorageSitePackSource $source, StorageSitePackImportOptions $options): array
    {
        $counts = ['total' => 0, 'resolved' => 0, 'unresolved' => 0];
        foreach (StorageSitePackSource::chunks($source->references(), self::CHUNK) as $chunk) {
            $rows = [];
            $byStructure = [];
            foreach ($chunk as $reference) {
                $counts['total']++;
                $byStructure[$reference['structure_id'] ?? ''][] = $reference['id'];
            }
            foreach ($byStructure as $structureId => $ids) {
                $targets = $this->resolveReferences($structureId === '' ? null : (string) $structureId, $ids, $options);
                foreach ($ids as $id) {
                    $target = $targets[$id] ?? null;
                    $rows[] = $this->idRow(StorageSitePackRuns::ID_KIND_REFERENCE, $id, $target, $target === null ? 'unresolved' : 'map');
                    $counts[$target === null ? 'unresolved' : 'resolved']++;
                }
            }
            $this->runs->addIds($rows);
        }

        return $counts;
    }

    /**
     * Local targets of records of one external structure: the person's mapping or the structure of the same id in the
     * target scope; there, a record an earlier import of this scope brought under a new id, or the same id.
     *
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function resolveReferences(?string $structureId, array $ids, StorageSitePackImportOptions $options): array
    {
        $schemaId = null;
        if ($structureId !== null) {
            $local = $options->referenceTargets[$structureId] ?? $structureId;
            $head = $this->database->table('larena_storage_workbench_structures')
                ->where('scope_ref', $this->scopeRef)
                ->where('structure_id', $local)
                ->first();
            if (!$head instanceof stdClass || ($head->import_run_ref ?? null) !== null) {
                return [];
            }
            $schemaId = (string) $head->storage_schema_id;
        }
        $candidates = [];
        foreach ($this->database->table(StorageSitePackRuns::IDS_TABLE . ' as ids')
            ->join(StorageSitePackRuns::TABLE . ' as runs', 'runs.run_ref', '=', 'ids.run_ref')
            ->where('runs.kind', StorageSitePackRun::KIND_IMPORT)
            ->where('runs.state', StorageSitePackRun::STATE_DONE)
            ->where('runs.scope_ref', $this->scopeRef)
            ->where('ids.kind', StorageSitePackRuns::ID_KIND_RECORD)
            ->whereIn('ids.source_ref', $ids)
            ->whereNotNull('ids.target_ref')
            ->orderBy('runs.finished_at')
            ->get(['ids.source_ref', 'ids.target_ref']) as $row) {
            $candidates[(string) $row->source_ref] = (string) $row->target_ref;
        }
        $lookup = array_values(array_unique(array_merge($ids, array_values($candidates))));
        $query = $this->database->table('larena_storage_records')->whereIn('record_id', $lookup);
        if ($schemaId !== null) {
            $query->where('schema_id', $schemaId);
        }
        $present = array_flip(array_map('strval', $query->pluck('record_id')->all()));
        $targets = [];
        foreach ($ids as $id) {
            if (isset($candidates[$id], $present[$candidates[$id]])) {
                $targets[$id] = $candidates[$id];
            } elseif (isset($present[$id])) {
                $targets[$id] = $id;
            }
        }

        return $targets;
    }

    /**
     * Decides each file of the pack: reuse, create or remap.
     *
     * @return array<string, int>
     */
    private function assets(StorageSitePackSource $source): array
    {
        $counts = ['total' => 0, 'reuse' => 0, 'create' => 0, 'remap' => 0, 'missing' => 0, 'bytes' => 0];
        $rows = [];
        $available = $this->fileImports->importAvailable();
        foreach ($source->assets() as $asset) {
            $descriptor = $asset['descriptor'];
            $logicalRef = (string) $descriptor['logical_ref'];
            $sha256 = (string) $descriptor['sha256'];
            $counts['total']++;
            $action = 'create';
            $target = $logicalRef;
            if ($available) {
                if ($this->fileImports->belongsToSite($this->siteRef, $logicalRef)) {
                    $action = 'reuse';
                } else {
                    $matches = $this->fileImports->contentMatches($this->siteRef, $sha256, (int) $descriptor['size_bytes'], (string) $descriptor['extension']);
                    if ($matches !== []) {
                        $action = 'reuse';
                        $target = $matches[0];
                    } elseif ($this->fileImports->logicalRefTaken($logicalRef) || $this->fileImports->publicIdTaken((string) $descriptor['public_id'])) {
                        $action = 'remap';
                        $target = self::uuid();
                    }
                }
            }
            $counts[$action]++;
            if ($action !== 'reuse') {
                $counts['bytes'] += (int) $descriptor['size_bytes'];
            }
            $this->files[$logicalRef] = $target;
            $rows[] = $this->idRow(StorageSitePackRuns::ID_KIND_FILE, $logicalRef, $target, $action, $sha256);
            if (count($rows) >= self::CHUNK) {
                $this->runs->addIds($rows);
                $rows = [];
            }
        }
        $this->runs->addIds($rows);

        return $counts;
    }

    private function structureId(string $sourceId, StorageSitePackImportOptions $options): ?string
    {
        if ($options->targetStructureId !== null) {
            if (!$this->structureFree($options->targetStructureId)) {
                $this->error('structure_id_taken', $options->targetStructureId);

                return null;
            }

            return $options->targetStructureId;
        }
        if ($this->structureFree($sourceId)) {
            return $sourceId;
        }
        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = substr($sourceId, 0, 120 - strlen('_' . $suffix)) . '_' . $suffix;
            if ($this->structureFree($candidate)) {
                return $candidate;
            }
        }
        $this->error('structure_id_taken', $sourceId);

        return null;
    }

    private function structureFree(string $structureId): bool
    {
        if (preg_match('/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D', $structureId) !== 1) {
            return false;
        }
        if ($this->database->table('larena_storage_workbench_structures')
            ->where('scope_ref', $this->scopeRef)->where('structure_id', $structureId)->exists()) {
            return false;
        }
        $schemaId = \Larena\Storage\Runtime\ScopedStorageWorkbenchSchemaIdentity::derive($this->scopeRef, $structureId);

        return !$this->database->table('larena_storage_schemas')->where('schema_id', $schemaId)->exists();
    }

    /**
     * The workbench descriptor of the new structure: the pack's fields, record@1 targets rewritten (the pack's own
     * structure → the new id; another structure → the person's mapping).
     *
     * @param array<string, mixed> $structure
     * @return array{structure_id: string, label: string, fields: list<array<string, mixed>>}
     */
    private function descriptor(array $structure, string $targetId, StorageSitePackImportOptions $options, bool $decrypted = false): array
    {
        $fields = [];
        foreach ($structure['fields'] as $field) {
            if (!is_array($field)) {
                throw new StorageRejected('storage_sitepack_structure_invalid');
            }
            $field = array_intersect_key($field, array_flip([
                'constraints', 'key', 'label', 'position', 'required', 'type', 'type_version', 'visibility',
                'localized', 'hidden', 'searchable', 'indexed', 'encrypted',
            ]));
            if (($field['encrypted'] ?? false) === true && $decrypted) {
                // The pack carries the values in plaintext: they are sealed again with this site's data key.
                $this->warning(StorageSitePackValues::ENCRYPTED_DECRYPTED, is_string($field['key'] ?? null) ? $field['key'] : null);
            } elseif (($field['encrypted'] ?? false) === true) {
                // The pack carries no encrypted value: the field comes as it was, optional, and the plan says so.
                $field['required'] = false;
                $this->warning(StorageSitePackValues::ENCRYPTED_OMITTED, is_string($field['key'] ?? null) ? $field['key'] : null);
            }
            if (($field['type'] ?? null) === 'record' && is_array($field['constraints'] ?? null)
                && is_string($field['constraints']['target_structure_id'] ?? null)) {
                $target = $field['constraints']['target_structure_id'];
                $field['constraints']['target_structure_id'] = $target === $structure['id']
                    ? $targetId
                    : ($options->referenceTargets[$target] ?? $target);
            }
            $fields[] = $field;
        }

        return [
            'structure_id' => $targetId,
            'label' => $options->targetLabel ?? (string) $structure['label'],
            'fields' => $fields,
        ];
    }

    /**
     * Decides each record id: kept when no record of the installation holds it, else (or with copy) a new one.
     *
     * @return array<string, int>
     */
    private function recordIds(StorageSitePackSource $source, StorageSitePackImportOptions $options): array
    {
        $counts = ['total' => 0, 'keep' => 0, 'new' => 0, 'archived' => 0];
        foreach (StorageSitePackSource::chunks($source->records(), self::CHUNK) as $batch) {
            $chunk = [];
            foreach ($batch as $item) {
                $record = $item['record'];
                $id = (string) $record['id'];
                if (isset($chunk[$id])) {
                    $this->error('record_duplicate', null);
                    continue;
                }
                $chunk[$id] = true;
                $counts['total']++;
                if ($record['state'] === 'archived') {
                    $counts['archived']++;
                }
            }
            $ids = array_keys($chunk);
            $seen = $this->database->table(StorageSitePackRuns::IDS_TABLE)
                ->where('run_ref', $this->runRef)
                ->where('kind', StorageSitePackRuns::ID_KIND_RECORD)
                ->whereIn('source_ref', $ids)
                ->exists();
            if ($seen) {
                $this->error('record_duplicate', null);
            }
            $taken = $options->copy ? [] : array_flip(array_map('strval', $this->database->table('larena_storage_records')
                ->whereIn('record_id', $ids)->pluck('record_id')->all()));
            $rows = [];
            foreach ($ids as $id) {
                $keep = !$options->copy && !isset($taken[$id]);
                $rows[] = $this->idRow(StorageSitePackRuns::ID_KIND_RECORD, $id, $keep ? $id : 'record-' . bin2hex(random_bytes(16)), $keep ? 'keep' : 'remap');
                $counts[$keep ? 'keep' : 'new']++;
            }
            $this->runs->addIds($rows);
            ($this->tick)();
        }

        return $counts;
    }

    /**
     * Checks every version as the import will write it.
     *
     * @param array{structure_id: string, label: string, fields: list<array<string, mixed>>} $descriptor
     * @return array{versions: int, localized: int, relations: int, published: int, scheduled: int}
     */
    private function check(StorageSitePackSource $source, StorageSitePackValues $values, ?StorageSchemaVersion $schema, array $descriptor): array
    {
        $localizedFields = [];
        foreach ($descriptor['fields'] as $field) {
            if (($field['localized'] ?? false) === true) {
                $localizedFields[(string) $field['key']] = $field;
            }
        }
        $counts = ['versions' => 0, 'localized' => 0, 'relations' => 0, 'published' => 0, 'scheduled' => 0];
        foreach (StorageSitePackSource::chunks($source->records(), self::CHUNK) as $batch) {
            $chunk = array_column($batch, 'record');
            $lookup = $this->lookups($chunk, $values);
            foreach ($chunk as $record) {
                foreach ($record['versions'] as $index => $version) {
                    $counts['versions']++;
                    $rewritten = $values->rewrite($version['values'], $lookup['record'], $this->userLookup(...), $lookup['file'], $this->actor);
                    $this->issues($rewritten['issues']);
                    if ($schema !== null && !$this->hasErrors($rewritten['issues'])) {
                        try {
                            $this->normalizer->normalizeValues($schema, $rewritten['values'] + [
                                'larena_scope_ref' => $this->scopeRef,
                                'larena_record_state' => $index === count($record['versions']) - 1 ? $record['state'] : 'active',
                            ]);
                        } catch (StorageRejected $exception) {
                            $this->error('record_invalid', $exception->reasonCode);
                        }
                    }
                    foreach ($version['localized'] as $locale => $translation) {
                        $this->locales[(string) $locale] = true;
                        $rewrittenTranslation = $values->rewrite($translation, $lookup['record'], $this->userLookup(...), $lookup['file'], $this->actor);
                        $this->issues($rewrittenTranslation['issues']);
                        foreach ($rewrittenTranslation['values'] as $fieldKey => $value) {
                            $counts['localized']++;
                            $field = $localizedFields[(string) $fieldKey] ?? null;
                            if ($field === null) {
                                $this->error('record_invalid', 'storage_sitepack_field_not_localized');
                                continue;
                            }
                            if ($value === null) {
                                continue;
                            }
                            $result = $this->propertyTypes->normalizeAndValidate(
                                (string) $field['type'],
                                (int) $field['type_version'],
                                $value,
                                is_array($field['constraints'] ?? null) ? $field['constraints'] : [],
                            );
                            if (!$result->canBePersistedByOwner()) {
                                $this->error('record_invalid', 'storage_sitepack_translation_invalid');
                            }
                        }
                    }
                }
                if (count($record['versions']) > \Larena\Storage\Contracts\VersionedStorage::HISTORY_KEEP_LIMIT) {
                    $this->error('record_invalid', 'storage_sitepack_too_many_versions');
                }
                foreach ($record['relations'] as $relation) {
                    $counts['relations']++;
                    if (($lookup['record'])((string) $relation['to'], false) === null) {
                        $this->warning('relation_dropped', null);
                    }
                }
                $revisions = StorageSitePackSource::revisions($record);
                foreach ($record['publication'] as $publication) {
                    $this->locales[(string) $publication['locale']] = true;
                    if ($publication['state'] === 'published' && is_int($publication['revision'] ?? null)) {
                        $counts['published']++;
                        if (!isset($revisions[$publication['revision']])) {
                            $this->error('record_invalid', 'storage_sitepack_publication_revision_missing');
                        }
                    }
                    if (is_int($publication['scheduled_revision'] ?? null)) {
                        $counts['scheduled']++;
                        if (!isset($revisions[$publication['scheduled_revision']]) || !is_string($publication['scheduled_at'] ?? null)) {
                            $this->error('record_invalid', 'storage_sitepack_publication_revision_missing');
                        }
                    }
                }
            }
            ($this->tick)();
        }
        $this->saveUsers();

        return $counts;
    }

    /**
     * The id map lookups for one chunk of records: records of the pack, references, files.
     *
     * @param list<array<string, mixed>> $records
     * @return array{record: Closure(string, bool): ?string, file: Closure(string): ?string}
     */
    private function lookups(array $records, StorageSitePackValues $values): array
    {
        $internal = [];
        $external = [];
        foreach ($records as $record) {
            foreach ($record['versions'] as $version) {
                foreach (array_merge([$version['values']], array_values($version['localized'])) as $set) {
                    $refs = $values->refs($set);
                    foreach ($refs['internal'] as $id) {
                        $internal[$id] = true;
                    }
                    foreach ($refs['external'] as $id) {
                        $external[$id] = true;
                    }
                }
            }
            foreach ($record['relations'] as $relation) {
                $internal[(string) $relation['to']] = true;
                $external[(string) $relation['to']] = true;
            }
        }
        $maps = StorageSitePackIdMap::lookup($this->runs, $this->runRef, array_keys($internal), array_keys($external));
        $files = $this->files;

        return [
            'record' => static fn (string $id, bool $inPack): ?string => $inPack
                ? ($maps['internal'][$id] ?? null)
                : ($maps['external'][$id] ?? $maps['internal'][$id] ?? null),
            'file' => static fn (string $logicalRef): ?string => $files[$logicalRef] ?? null,
        ];
    }

    private function userLookup(string $userRef): bool
    {
        if (!array_key_exists($userRef, $this->users)) {
            try {
                $this->users[$userRef] = ($this->userExists)($userRef) === true;
            } catch (Throwable) {
                $this->users[$userRef] = false;
            }
        }

        return $this->users[$userRef];
    }

    private function saveUsers(): void
    {
        $rows = [];
        foreach ($this->users as $userRef => $exists) {
            $rows[] = $this->idRow(StorageSitePackRuns::ID_KIND_USER, (string) $userRef, $exists ? (string) $userRef : null, $exists ? 'keep' : 'missing');
        }
        $this->runs->addIds($rows);
    }

    /** @return array<string, string>|null */
    private function replay(string $packageId): ?array
    {
        $row = $this->database->table(StorageSitePackRuns::TABLE)
            ->where('kind', StorageSitePackRun::KIND_IMPORT)
            ->where('state', StorageSitePackRun::STATE_DONE)
            ->where('package_id', $packageId)
            ->where('site_ref', $this->siteRef)
            ->where('run_ref', '!=', $this->runRef)
            ->orderByDesc('finished_at')
            ->first(['run_ref', 'structure_id', 'finished_at']);
        if (!$row instanceof stdClass) {
            return null;
        }

        return ['run_ref' => (string) $row->run_ref, 'structure_id' => (string) $row->structure_id, 'imported_at' => (string) $row->finished_at];
    }

    /**
     * @param array<string, mixed> $structure
     * @return list<array{role_ref: string, action: string}>
     */
    private function roles(array $structure, ?string $mergeSchemaId = null): array
    {
        $roles = [];
        foreach (is_array($structure['role_bindings'] ?? null) ? $structure['role_bindings'] : [] as $binding) {
            $roleRef = is_array($binding) && is_string($binding['role_ref'] ?? null) ? $binding['role_ref'] : null;
            if ($roleRef === null) {
                continue;
            }
            $action = 'bind';
            if ($mergeSchemaId !== null && $this->bound($roleRef, $mergeSchemaId)) {
                // The structure the records go into plays the role already: it is never bound twice.
                $roles[] = ['role_ref' => $roleRef, 'action' => 'already_bound'];
                continue;
            }
            try {
                if ($this->roles === null || $this->roles->read($roleRef) === null) {
                    $action = 'skip';
                    $this->warning('role_unknown', $roleRef);
                } elseif (!$this->allowed('storage.role.manage')) {
                    $action = 'skip';
                    $this->warning('role_binding_skipped', $roleRef);
                }
            } catch (Throwable) {
                $action = 'skip';
                $this->warning('role_unknown', $roleRef);
            }
            $roles[] = ['role_ref' => $roleRef, 'action' => $action];
        }

        return $roles;
    }

    private function bound(string $roleRef, string $schemaId): bool
    {
        return $this->database->getSchemaBuilder()->hasTable('larena_storage_structure_role_bindings')
            && $this->database->table('larena_storage_structure_role_bindings')
                ->where('role_ref', $roleRef)->where('schema_id', $schemaId)->where('status', 'active')->exists();
    }

    private function allowed(string $operation): bool
    {
        try {
            $this->authorizer->assertAllowed($this->actor, $operation);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{rows: int, sha256: string} */
    private function idMapDigest(): array
    {
        $hash = hash_init('sha256');
        $rows = 0;
        foreach ([StorageSitePackRuns::ID_KIND_STRUCTURE, StorageSitePackRuns::ID_KIND_RECORD, StorageSitePackRuns::ID_KIND_REFERENCE, StorageSitePackRuns::ID_KIND_USER, StorageSitePackRuns::ID_KIND_FILE, StorageSitePackRuns::ID_KIND_REPLACED] as $kind) {
            $after = null;
            do {
                $page = $this->runs->idsAfter($this->runRef, $kind, null, $after, 1000);
                foreach ($page as $row) {
                    hash_update($hash, implode("\x1f", [$kind, (string) $row->source_ref, (string) ($row->target_ref ?? ''), (string) $row->action, (string) ($row->sha256 ?? '')]) . "\n");
                    $rows++;
                    $after = (string) $row->source_ref;
                }
            } while (count($page) === 1000);
        }

        return ['rows' => $rows, 'sha256' => hash_final($hash)];
    }

    /** @param list<string> $issues */
    private function issues(array $issues): void
    {
        foreach ($issues as $issue) {
            StorageSitePackValues::isError($issue) ? $this->error($issue, null) : $this->warning($issue, null);
        }
    }

    /** @param list<string> $issues */
    private function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (StorageSitePackValues::isError($issue)) {
                return true;
            }
        }

        return false;
    }

    private function error(string $code, ?string $subject): void
    {
        $key = $code . "\x1f" . ($subject ?? '');
        $this->errors[$key] ??= ['code' => $code, 'count' => 0, 'subject' => $subject];
        $this->errors[$key]['count']++;
    }

    private function warning(string $code, ?string $subject): void
    {
        $key = $code . "\x1f" . ($subject ?? '');
        $this->warnings[$key] ??= ['code' => $code, 'count' => 0, 'subject' => $subject];
        $this->warnings[$key]['count']++;
    }

    /**
     * @param array<string, array{code: string, count: int, subject: ?string}> $issues
     * @return list<array{code: string, count: int, subject: ?string}>
     */
    private static function listed(array $issues): array
    {
        ksort($issues, SORT_STRING);

        return array_values($issues);
    }

    /** @return array{run_ref: string, kind: string, source_ref: string, target_ref: ?string, action: string, sha256: ?string} */
    private function idRow(string $kind, string $source, ?string $target, string $action, ?string $sha256 = null): array
    {
        return ['run_ref' => $this->runRef, 'kind' => $kind, 'source_ref' => $source, 'target_ref' => $target, 'action' => $action, 'sha256' => $sha256];
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
