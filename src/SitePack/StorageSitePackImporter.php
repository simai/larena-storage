<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageSitePackPlan;
use Larena\Storage\Contracts\StorageSitePackRun;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Enums\RelationDeletePolicy;
use Larena\Storage\Enums\RelationKind;
use Larena\Storage\Exceptions\PublicationRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\RelationPath;
use Larena\Storage\View\Site\PublishedSiteViewRegistry;
use stdClass;
use Throwable;

/**
 * The import engine of one run: it writes what its plan decided, phase by phase, each chunk with the run's position in
 * the same transaction (DatabaseStorageSitePack's lease-checked save), so a resumed import continues after the last
 * saved chunk and never writes a record twice.
 *
 * - structure: the new structure, «importing» (hidden, refusing workbench reads and writes) until finalize;
 * - files: the files the plan creates or remaps, through StorageSitePackFileImports (each content copied out of the
 *   pack, digest checked);
 * - records: CHUNK records a transaction (VersionedStorage::importRecord under the planned id, revisions renumbered
 *   1..n in source order, the values rewritten through the id map), their translations through the localized-values
 *   write path;
 * - relations: a second pass, in depth order, so a tree edge finds its parent's path;
 * - publications (only with `keep`): the role bindings first, then each publication state through the publication
 *   lifecycle: a key another record holds leaves that locale a draft (`publication_key_conflicts`); a schedule in
 *   the past is published by the sweep;
 * - finalize: counts against the plan, the role bindings (when not bound yet), the importing state cleared, the run
 *   done and audited, in one transaction.
 */
final class StorageSitePackImporter
{
    private const string RECORDS = 'larena_storage_records';
    private const string RELATIONS = 'larena_storage_record_relations';
    private const string BINDINGS = 'larena_storage_structure_role_bindings';

    private StorageSitePackValues $values;

    /** @var list<string> */
    private readonly array $localizedKeys;

    /**
     * @param array<string, mixed> $import the run's import options: structure_id, schema_id, descriptor, history_keep
     * @param Closure(array<string, mixed>): void $save saves the run under its lease (in the caller's transaction)
     * @param Closure(): void $tick between chunks
     * @param Closure(string, array<string, mixed>): void $emit the run's audit event
     */
    public function __construct(
        private readonly Connection $database,
        private readonly StorageSitePackRuns $runs,
        private readonly VersionedStorage $storage,
        private readonly DatabaseStorageWorkbench $workbench,
        private readonly StorageSitePackFileImports $fileImports,
        private readonly ?LocalizedValues $localized,
        private readonly ?PublicationLifecycle $publication,
        private readonly ?StructureRoleRegistry $roles,
        private readonly StorageSitePackPlan $plan,
        private readonly array $import,
        private readonly string $runRef,
        private readonly string $scopeRef,
        private readonly string $siteRef,
        private readonly string $actor,
        private readonly string $correlationId,
        private readonly string $staging,
        private readonly int $chunk,
        private readonly int $fileChunk,
        private readonly Closure $save,
        private readonly Closure $tick,
        private readonly Closure $emit,
    ) {
        $descriptor = is_array($import['descriptor'] ?? null) ? $import['descriptor'] : [];
        $keys = [];
        foreach (is_array($descriptor['fields'] ?? null) ? $descriptor['fields'] : [] as $field) {
            if (is_array($field) && ($field['localized'] ?? false) === true) {
                $keys[] = (string) $field['key'];
            }
        }
        $this->localizedKeys = $keys;
    }

    /**
     * Walks the import from its saved phase to its end.
     *
     * @param array<string, mixed> $cursor
     * @param array<string, int> $counts
     */
    public function walk(StorageSitePackSource $source, string $phase, array $cursor, array $counts, int $done): void
    {
        $structure = $source->structure;
        $sourceFields = is_array($structure['fields'] ?? null) ? $structure['fields'] : [];
        // The values are rewritten as the pack describes its fields (their record@1 targets as the pack names them).
        $this->values = new StorageSitePackValues($sourceFields, (string) $structure['id']);
        if ($phase === StorageSitePackRun::PHASE_STRUCTURE) {
            $this->structure();
            $phase = StorageSitePackRun::PHASE_FILES;
            $cursor = ['line' => 0];
            ($this->save)(['phase' => $phase, 'cursor' => self::json($cursor)]);
        }
        if ($phase === StorageSitePackRun::PHASE_FILES) {
            $counts = $this->files($source, (int) ($cursor['line'] ?? 0), $counts);
            $phase = StorageSitePackRun::PHASE_RECORDS;
            $cursor = ['part' => 1, 'line' => 0, 'depths' => []];
        }
        if ($phase === StorageSitePackRun::PHASE_RECORDS) {
            [$counts, $done, $depths] = $this->records($source, $cursor, $counts, $done);
            $phase = StorageSitePackRun::PHASE_RELATIONS;
            $cursor = ['depths' => $depths, 'index' => 0, 'part' => 1, 'line' => 0];
        }
        if ($phase === StorageSitePackRun::PHASE_RELATIONS) {
            $counts = $this->relations($source, $cursor, $counts);
            $phase = $this->keepsPublication() ? StorageSitePackRun::PHASE_PUBLICATIONS : StorageSitePackRun::PHASE_FINALIZE;
            $cursor = ['part' => 1, 'line' => 0];
        }
        if ($phase === StorageSitePackRun::PHASE_PUBLICATIONS) {
            $counts = $this->publications($source, $cursor, $counts);
            $phase = StorageSitePackRun::PHASE_FINALIZE;
        }
        if ($phase !== StorageSitePackRun::PHASE_FINALIZE) {
            throw new StorageRejected('storage_sitepack_run_corrupt');
        }
        $this->finalize($counts, $done);
    }

    private function structure(): void
    {
        $structureId = (string) $this->import['structure_id'];
        $head = $this->database->table('larena_storage_workbench_structures')
            ->where('scope_ref', $this->scopeRef)
            ->where('structure_id', $structureId)
            ->first();
        if ($head instanceof stdClass) {
            // Created by this run before an interruption: resumed. Anything else took the id since the plan.
            if (($head->import_run_ref ?? null) !== $this->runRef || (string) $head->storage_schema_id !== $this->import['schema_id']) {
                throw new StorageRejected('storage_sitepack_plan_stale');
            }

            return;
        }
        /** @var array<string, mixed> $descriptor */
        $descriptor = $this->import['descriptor'];
        $created = $this->workbench->createImportingStructure(
            $this->scopeRef,
            $descriptor,
            is_int($this->import['history_keep'] ?? null) ? $this->import['history_keep'] : null,
            $this->runRef,
            $this->actor,
            $this->correlationId,
        );
        if ($created->schema->schemaId !== $this->import['schema_id'] || $created->schema->version !== 1) {
            throw new StorageRejected('storage_sitepack_plan_stale');
        }
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function files(StorageSitePackSource $source, int $line, array $counts): array
    {
        $pending = 0;
        foreach ($source->assets($line) as $number => $asset) {
            $descriptor = $asset['descriptor'];
            $logicalRef = (string) $descriptor['logical_ref'];
            $row = $this->runs->idsFor($this->runRef, StorageSitePackRuns::ID_KIND_FILE, [$logicalRef])[$logicalRef] ?? null;
            if (!$row instanceof stdClass) {
                throw new StorageRejected('storage_sitepack_plan_stale');
            }
            $action = (string) $row->action;
            if ($action === 'reuse') {
                $counts['files_reused'] = ($counts['files_reused'] ?? 0) + 1;
            } else {
                $target = (string) $row->target_ref;
                if ($action === 'remap') {
                    $descriptor['logical_ref'] = $target;
                    $descriptor['public_id'] = StorageSitePackPlanner::uuid();
                }
                StorageSitePackExporter::directory($this->staging);
                $copy = $this->staging . '/blob-' . bin2hex(random_bytes(8));
                $source->copyBlob($asset['blob'], $copy);
                try {
                    $file = new StorageSitePackFile($descriptor, static fn (): mixed => @fopen($copy, 'rb'));
                    $created = $this->fileImports->import($this->siteRef, $file);
                } finally {
                    @unlink($copy);
                }
                if ($created !== $target) {
                    throw new StorageRejected('storage_sitepack_file_import_mismatch');
                }
                $counts['files_created'] = ($counts['files_created'] ?? 0) + 1;
                $counts['file_bytes'] = ($counts['file_bytes'] ?? 0) + (int) $descriptor['size_bytes'];
            }
            if (++$pending >= $this->fileChunk) {
                ($this->save)(['cursor' => self::json(['line' => $number]), 'counts_json' => self::json($counts)]);
                ($this->tick)();
                $pending = 0;
            }
        }
        ($this->save)([
            'phase' => StorageSitePackRun::PHASE_RECORDS,
            'cursor' => self::json(['part' => 1, 'line' => 0, 'depths' => []]),
            'counts_json' => self::json($counts),
        ]);
        ($this->tick)();

        return $counts;
    }

    /**
     * @param array<string, mixed> $cursor
     * @param array<string, int> $counts
     * @return array{0: array<string, int>, 1: int, 2: list<int>}
     */
    private function records(StorageSitePackSource $source, array $cursor, array $counts, int $done): array
    {
        $depths = [];
        foreach (is_array($cursor['depths'] ?? null) ? $cursor['depths'] : [] as $depth) {
            $depths[(int) $depth] = true;
        }
        $schema = new StorageSchemaVersionRef((string) $this->import['schema_id'], 1);
        $part = (int) ($cursor['part'] ?? 1);
        $line = (int) ($cursor['line'] ?? 0);
        foreach (StorageSitePackSource::chunks($source->records($part, $line), $this->chunk) as $batch) {
            $lookup = $this->lookups(array_column($batch, 'record'));
            $this->database->transaction(function () use ($batch, &$counts, &$done, &$depths, $schema, $lookup): void {
                $last = ['part' => 1, 'line' => 0];
                foreach ($batch as $item) {
                    $this->record($item['record'], $schema, $lookup, $counts);
                    foreach ($item['record']['relations'] as $relation) {
                        $depths[(int) $relation['depth']] = true;
                    }
                    $done++;
                    $last = ['part' => $item['part'], 'line' => $item['line']];
                }
                $list = array_keys($depths);
                sort($list);
                ($this->save)([
                    'cursor' => self::json($last + ['depths' => $list]),
                    'done' => $done,
                    'counts_json' => self::json($counts),
                ]);
            });
            ($this->tick)();
        }
        $list = array_keys($depths);
        sort($list);
        ($this->save)([
            'phase' => StorageSitePackRun::PHASE_RELATIONS,
            'cursor' => self::json(['depths' => $list, 'index' => 0, 'part' => 1, 'line' => 0]),
        ]);

        return [$counts, $done, $list];
    }

    /**
     * @param array<string, mixed> $record
     * @param array{record: Closure(string, bool): ?string, user: Closure(string): bool, file: Closure(string): ?string, ids: array<string, string>} $lookup
     * @param array<string, int> $counts
     */
    private function record(array $record, StorageSchemaVersionRef $schema, array $lookup, array &$counts): void
    {
        $recordId = $lookup['ids'][(string) $record['id']] ?? throw new StorageRejected('storage_sitepack_plan_stale');
        $versions = [];
        $translations = [];
        $last = count($record['versions']) - 1;
        foreach ($record['versions'] as $index => $version) {
            $rewritten = $this->values->rewrite($version['values'], $lookup['record'], $lookup['user'], $lookup['file'], $this->actor);
            $this->assertWritable($rewritten['issues']);
            $versions[] = $rewritten['values'] + [
                'larena_scope_ref' => $this->scopeRef,
                'larena_record_state' => $index === $last ? (string) $record['state'] : 'active',
            ];
            foreach ($version['localized'] as $locale => $values) {
                $translated = $this->values->rewrite($values, $lookup['record'], $lookup['user'], $lookup['file'], $this->actor);
                $this->assertWritable($translated['issues']);
                if ($translated['values'] !== []) {
                    $translations[] = [$index + 1, (string) $locale, $translated['values']];
                }
            }
        }
        $this->storage->importRecord(
            'workbench.record:' . bin2hex(random_bytes(16)),
            $schema,
            $recordId,
            $versions,
            $record['state'] === 'archived',
            $this->actor,
            $this->correlationId,
        );
        foreach ($translations as [$revision, $locale, $values]) {
            if ($this->localized === null) {
                throw new StorageRejected('storage_sitepack_localized_unavailable');
            }
            $this->localized->write($schema->schemaId, $recordId, $revision, $locale, $values, $this->localizedKeys, $this->actor, [], false, $this->correlationId);
            $counts['localized_values'] = ($counts['localized_values'] ?? 0) + count($values);
        }
        $counts['records'] = ($counts['records'] ?? 0) + 1;
        $counts['versions'] = ($counts['versions'] ?? 0) + count($versions);
        if ($record['state'] === 'archived') {
            $counts['archived_records'] = ($counts['archived_records'] ?? 0) + 1;
        }
    }

    /** @param list<string> $issues */
    private function assertWritable(array $issues): void
    {
        foreach ($issues as $issue) {
            if (StorageSitePackValues::isError($issue)) {
                throw new StorageRejected('storage_sitepack_plan_stale');
            }
        }
    }

    /**
     * The id map for one chunk of records.
     *
     * @param list<array<string, mixed>> $records
     * @return array{record: Closure(string, bool): ?string, user: Closure(string): bool, file: Closure(string): ?string, ids: array<string, string>}
     */
    private function lookups(array $records): array
    {
        $internal = [];
        $external = [];
        $users = [];
        $files = [];
        foreach ($records as $record) {
            $internal[(string) $record['id']] = true;
            foreach ($record['versions'] as $version) {
                foreach (array_merge([$version['values']], array_values($version['localized'])) as $set) {
                    $refs = $this->values->refs($set);
                    foreach ($refs['internal'] as $id) {
                        $internal[$id] = true;
                    }
                    foreach ($refs['external'] as $id) {
                        $external[$id] = true;
                    }
                    foreach ($refs['users'] as $user) {
                        $users[$user] = true;
                    }
                    foreach ($refs['files'] as $file) {
                        $files[$file] = true;
                    }
                }
            }
            foreach ($record['relations'] as $relation) {
                $internal[(string) $relation['to']] = true;
                $external[(string) $relation['to']] = true;
            }
        }
        $maps = StorageSitePackIdMap::lookup($this->runs, $this->runRef, array_keys($internal), array_keys($external));
        $kept = StorageSitePackIdMap::users($this->runs, $this->runRef, array_keys($users));
        $targets = StorageSitePackIdMap::files($this->runs, $this->runRef, array_keys($files));

        return [
            'record' => static fn (string $id, bool $inPack): ?string => $inPack
                ? ($maps['internal'][$id] ?? null)
                : ($maps['external'][$id] ?? $maps['internal'][$id] ?? null),
            'user' => static fn (string $user): bool => $kept[$user] ?? false,
            'file' => static fn (string $logicalRef): ?string => $targets[$logicalRef] ?? null,
            'ids' => $maps['internal'],
        ];
    }

    /**
     * @param array<string, mixed> $cursor
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function relations(StorageSitePackSource $source, array $cursor, array $counts): array
    {
        $depths = array_values(array_map('intval', is_array($cursor['depths'] ?? null) ? $cursor['depths'] : []));
        $schemaId = (string) $this->import['schema_id'];
        for ($index = (int) ($cursor['index'] ?? 0); $index < count($depths); $index++) {
            $depth = $depths[$index];
            $start = $index === (int) ($cursor['index'] ?? 0)
                ? ['part' => (int) ($cursor['part'] ?? 1), 'line' => (int) ($cursor['line'] ?? 0)]
                : ['part' => 1, 'line' => 0];
            foreach (StorageSitePackSource::chunks($this->withRelations($source->records($start['part'], $start['line'])), $this->chunk) as $batch) {
                $lookup = $this->lookups(array_column($batch, 'record'));
                $this->database->transaction(function () use ($batch, &$counts, $depth, $depths, $index, $schemaId, $lookup): void {
                    $last = ['part' => 1, 'line' => 0];
                    foreach ($batch as $item) {
                        foreach ($item['record']['relations'] as $relation) {
                            if ((int) $relation['depth'] === $depth) {
                                $counts = $this->relation($schemaId, $lookup, (string) $item['record']['id'], $relation, $counts);
                            }
                        }
                        $last = ['part' => $item['part'], 'line' => $item['line']];
                    }
                    ($this->save)([
                        'cursor' => self::json(['depths' => $depths, 'index' => $index] + $last),
                        'counts_json' => self::json($counts),
                    ]);
                });
                ($this->tick)();
            }
            ($this->save)(['cursor' => self::json(['depths' => $depths, 'index' => $index + 1, 'part' => 1, 'line' => 0])]);
        }
        ($this->save)([
            'phase' => $this->keepsPublication() ? StorageSitePackRun::PHASE_PUBLICATIONS : StorageSitePackRun::PHASE_FINALIZE,
            'cursor' => self::json(['part' => 1, 'line' => 0]),
            'counts_json' => self::json($counts),
        ]);

        return $counts;
    }

    /**
     * The records that have relations.
     *
     * @param iterable<int, array{part: int, line: int, record: array<string, mixed>}> $records
     * @return \Generator<int, array{part: int, line: int, record: array<string, mixed>}>
     */
    private function withRelations(iterable $records): \Generator
    {
        foreach ($records as $item) {
            if ($item['record']['relations'] !== []) {
                yield $item;
            }
        }
    }

    /**
     * One relation of a record, its ends through the id map; one whose target is not here is dropped.
     *
     * @param array{record: Closure(string, bool): ?string, user: Closure(string): bool, file: Closure(string): ?string, ids: array<string, string>} $lookup
     * @param array<string, mixed> $relation
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function relation(string $schemaId, array $lookup, string $sourceId, array $relation, array $counts): array
    {
        $from = $lookup['ids'][$sourceId] ?? throw new StorageRejected('storage_sitepack_plan_stale');
        $to = ($lookup['record'])((string) $relation['to'], false);
        if ($to === null || $to === $from) {
            $counts['relations_dropped'] = ($counts['relations_dropped'] ?? 0) + 1;

            return $counts;
        }
        $key = (string) $relation['relation_key'];
        $kind = (string) $relation['kind'];
        $policy = (RelationDeletePolicy::tryFrom((string) ($relation['delete_policy'] ?? '')) ?? RelationDeletePolicy::Restrict)->value;
        $path = null;
        $depth = (int) $relation['depth'];
        $child = null;
        if (RelationKind::tryFrom($kind)?->isTree() === true) {
            $parent = $this->database->table(self::RELATIONS)
                ->where('relation_key', $key)
                ->where('tree_child_key', $to)
                ->first(['path']);
            $parentPath = $parent instanceof stdClass && is_string($parent->path) && $parent->path !== ''
                ? RelationPath::parse($parent->path)
                : RelationPath::root($to);
            if ($parentPath->contains($from)) {
                $counts['relations_dropped'] = ($counts['relations_dropped'] ?? 0) + 1;

                return $counts;
            }
            $childPath = $parentPath->child($from);
            $path = $childPath->toString();
            $depth = $childPath->depth();
            $child = $from;
        }
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $this->database->table(self::RELATIONS)->insertOrIgnore([
            'relation_id' => $key . '|' . $from . '|' . $to,
            'relation_key' => $key,
            'schema_id' => $schemaId,
            'from_record_id' => $from,
            'to_record_id' => $to,
            'kind' => $kind,
            'tree_child_key' => $child,
            'path' => $path,
            'depth' => $depth,
            'order_index' => (int) $relation['order_index'],
            'delete_policy' => $policy,
            'status' => 'active',
            'created_by' => $this->actor,
            'correlation_id' => $this->correlationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $counts[$inserted === 1 ? 'relations' : 'relations_dropped'] = ($counts[$inserted === 1 ? 'relations' : 'relations_dropped'] ?? 0) + 1;

        return $counts;
    }

    /**
     * @param array<string, mixed> $cursor
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function publications(StorageSitePackSource $source, array $cursor, array $counts): array
    {
        if ($this->publication === null) {
            throw new StorageRejected('storage_sitepack_publication_unavailable');
        }
        // The site finds the storage by its roles, and a key is unique only once the structure is bound.
        $counts = $this->bindRoles($counts);
        $schemaId = (string) $this->import['schema_id'];
        foreach (StorageSitePackSource::chunks($source->records((int) ($cursor['part'] ?? 1), (int) ($cursor['line'] ?? 0)), $this->chunk) as $batch) {
            $counts = $this->publishBatch($batch, $schemaId, $counts);
        }
        ($this->save)(['phase' => StorageSitePackRun::PHASE_FINALIZE, 'cursor' => null, 'counts_json' => self::json($counts)]);

        return $counts;
    }

    /**
     * Each transition is the lifecycle's own transaction: a chunk replayed after an interruption publishes the same
     * revision again, which changes nothing a reader sees.
     *
     * @param list<array{part: int, line: int, record: array<string, mixed>}> $batch
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function publishBatch(array $batch, string $schemaId, array $counts): array
    {
        if ($this->publication === null) {
            return $counts;
        }
        $ids = [];
        foreach ($this->runs->idsFor($this->runRef, StorageSitePackRuns::ID_KIND_RECORD, array_map(static fn (array $item): string => (string) $item['record']['id'], $batch)) as $source => $row) {
            $ids[$source] = (string) $row->target_ref;
        }
        $last = null;
        foreach ($batch as $item) {
            $record = $item['record'];
            $recordId = $ids[(string) $record['id']] ?? throw new StorageRejected('storage_sitepack_plan_stale');
            $revisions = StorageSitePackSource::revisions($record);
            foreach ($record['publication'] as $state) {
                $locale = (string) $state['locale'];
                if ($state['state'] === 'published' && is_int($state['revision'] ?? null) && isset($revisions[$state['revision']])) {
                    try {
                        $this->publication->publish($schemaId, $recordId, $this->siteRef, $locale, $revisions[$state['revision']], $this->actor, $this->correlationId);
                        $counts['publications'] = ($counts['publications'] ?? 0) + 1;
                    } catch (PublicationRejected $exception) {
                        $key = $exception->reasonCode === 'key_conflict' ? 'publication_key_conflicts' : 'publications_skipped';
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
                if (is_int($state['scheduled_revision'] ?? null) && isset($revisions[$state['scheduled_revision']]) && is_string($state['scheduled_at'] ?? null)) {
                    try {
                        $this->publication->schedule($schemaId, $recordId, $this->siteRef, $locale, $revisions[$state['scheduled_revision']], self::moment($state['scheduled_at']), $this->actor, $this->correlationId);
                        $counts['schedules'] = ($counts['schedules'] ?? 0) + 1;
                    } catch (PublicationRejected) {
                        $counts['publications_skipped'] = ($counts['publications_skipped'] ?? 0) + 1;
                    }
                }
            }
            $last = ['part' => $item['part'], 'line' => $item['line']];
        }
        ($this->save)(['cursor' => self::json($last ?? ['part' => 1, 'line' => 0]), 'counts_json' => self::json($counts)]);
        ($this->tick)();

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function bindRoles(array $counts): array
    {
        if (($counts['roles_done'] ?? 0) === 1) {
            return $counts;
        }
        $schemaId = (string) $this->import['schema_id'];
        $fields = $this->storage->schemaVersion(new StorageSchemaVersionRef($schemaId, 1))->fields;
        foreach ($this->plan->roles as $role) {
            if ($role['action'] !== 'bind' || $this->roles === null) {
                continue;
            }
            $bound = $this->database->getSchemaBuilder()->hasTable(self::BINDINGS) && $this->database->table(self::BINDINGS)
                ->where('role_ref', $role['role_ref'])->where('schema_id', $schemaId)->where('scope_ref', $this->siteRef)
                ->where('status', 'active')->exists();
            if ($bound) {
                continue;
            }
            try {
                // The source structure was bound to this role, so it declared the role's relations and lifecycle:
                // the binding carries them again, or the role (and the key uniqueness it guards) would silently not bind.
                $definition = $this->roles->read($role['role_ref']);
                if ($definition === null) {
                    throw new StorageRejected('storage_sitepack_role_unknown');
                }
                $this->roles->bindStructure($role['role_ref'], $schemaId, $this->siteRef, $fields, $this->actor,
                    array_map(static fn (array $relation): string => $relation['relation_key'], $definition->requiredRelations),
                    $definition->lifecycle->value, $this->correlationId);
                $counts['roles_bound'] = ($counts['roles_bound'] ?? 0) + 1;
            } catch (Throwable) {
                $counts['roles_skipped'] = ($counts['roles_skipped'] ?? 0) + 1;
            }
        }
        $counts['roles_done'] = 1;
        ($this->save)(['counts_json' => self::json($counts)]);

        return $counts;
    }

    /** @param array<string, int> $counts */
    private function finalize(array $counts, int $done): void
    {
        $this->database->transaction(function () use ($counts, $done): void {
            $total = (int) ($this->plan->records['total'] ?? -1);
            if ($done !== $total || (int) ($counts['records'] ?? 0) !== $total
                || (int) ($counts['files_created'] ?? 0) !== (int) ($this->plan->files['create'] ?? 0) + (int) ($this->plan->files['remap'] ?? 0)) {
                throw new StorageRejected('storage_sitepack_import_count_mismatch');
            }
            $counts = $this->bindRoles($counts);
            unset($counts['roles_done']);
            if (!$this->workbench->finishImportingStructure($this->scopeRef, (string) $this->import['structure_id'], $this->runRef)) {
                throw new StorageRejected('storage_sitepack_plan_stale');
            }
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
            ($this->save)([
                'state' => StorageSitePackRun::STATE_DONE,
                'phase' => StorageSitePackRun::PHASE_DONE,
                'cursor' => null,
                'counts_json' => self::json($counts),
                'lease_ref' => null,
                'reason' => null,
                'finished_at' => $now,
            ]);
            $row = $this->runs->row($this->runRef);
            ($this->emit)(\Larena\Storage\Audit\StorageSitePackAuditEventCatalog::IMPORTED, $counts + [
                'plan_digest' => $row === null ? '' : (string) $row->plan_digest,
                'target_structure_id' => (string) $this->import['structure_id'],
                'schema_id' => (string) $this->import['schema_id'],
            ]);
        });
    }

    private function keepsPublication(): bool
    {
        return ($this->plan->publication['mode'] ?? 'draft') === 'keep';
    }

    /**
     * Removes everything an import wrote to the site (records, versions, translations, publication states, relations,
     * the structure and its schema and витрины), while its structure is still importing. Files it created stay; the
     * run reports them. Idempotent; in chunks, each its own transaction.
     */
    public static function discard(Connection $database, DatabaseStorageWorkbench $workbench, string $runRef, string $scopeRef, ?string $structureId, ?string $schemaId): void
    {
        if ($structureId === null || $schemaId === null) {
            return;
        }
        $head = $database->table('larena_storage_workbench_structures')
            ->where('scope_ref', $scopeRef)
            ->where('structure_id', $structureId)
            ->first(['import_run_ref', 'storage_schema_id']);
        if (!$head instanceof stdClass || ($head->import_run_ref ?? null) !== $runRef || (string) $head->storage_schema_id !== $schemaId) {
            return;
        }
        $schema = $database->getSchemaBuilder();
        $tables = [];
        foreach (['larena_storage_localized_values', 'larena_storage_publication_states', 'larena_storage_publication_log', 'larena_storage_published_keys'] as $table) {
            if ($schema->hasTable($table)) {
                $tables[] = $table;
            }
        }
        $relations = $schema->hasTable(self::RELATIONS);
        do {
            /** @var list<string> $ids */
            $ids = array_map('strval', $database->table(self::RECORDS)->where('schema_id', $schemaId)->orderBy('record_id')->limit(500)->pluck('record_id')->all());
            if ($ids === []) {
                break;
            }
            $database->transaction(static function () use ($database, $ids, $schemaId, $tables, $relations): void {
                if ($relations) {
                    $database->table(self::RELATIONS)
                        ->where(static function ($query) use ($ids): void {
                            $query->whereIn('from_record_id', $ids)->orWhereIn('to_record_id', $ids);
                        })
                        ->delete();
                }
                foreach ($tables as $table) {
                    $database->table($table)->where('schema_id', $schemaId)->whereIn('record_id', $ids)->delete();
                }
                (new PublishedSiteViewRegistry($database, 0.0))->deleteRecords($schemaId, $ids);
                $database->table('larena_storage_record_versions')->where('schema_id', $schemaId)->whereIn('record_id', $ids)->delete();
                $database->table(self::RECORDS)->where('schema_id', $schemaId)->whereIn('record_id', $ids)->delete();
            });
        } while (true);
        if ($relations) {
            $database->table(self::RELATIONS)->where('schema_id', $schemaId)->delete();
        }
        if ($schema->hasTable('larena_storage_published_key_sets')) {
            $database->table('larena_storage_published_key_sets')->where('schema_id', $schemaId)->delete();
        }
        $workbench->discardImportingStructure($scopeRef, $structureId, $runRef);
    }

    /** A pack's ISO time (`…T…Z`) as the stored UTC form. */
    private static function moment(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new StorageRejected('storage_sitepack_record_invalid');
        }
    }

    private static function json(mixed $value): string
    {
        return json_encode($value === [] ? new stdClass() : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
