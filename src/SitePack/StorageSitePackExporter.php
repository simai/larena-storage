<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Larena\Core\SitePack\SitePackArchiveWriter;
use Larena\Core\SitePack\SitePackEnvelope;
use Larena\Core\SitePack\SitePackLimits;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Storage\SchemaEvolution\StorageRecordConverter;
use stdClass;
use Throwable;

/**
 * The export engine of one run: it reads one structure of one scope and stages what the pack carries, a chunk at a
 * time, in the run's staging directory; DatabaseStorageSitePack saves the position after each chunk.
 *
 * - prepare(): the structure line (structures.jsonl in staging).
 * - records(): the next chunk of records in record id order (keyset), appended to the current part file at the byte
 *   offset the saved position names, so a chunk that was written but not saved is written again, not twice.
 * - files(): the next chunk of the files the live versions reference: one index line each, the content once per
 *   SHA-256, checked against the descriptor's digest and size.
 * - seal(): the archive, with core's SitePack writer, from what was staged.
 *
 * No older revision, actor id, correlation id or reserved field ever reaches a line.
 */
final class StorageSitePackExporter
{
    private const string SCOPE_FIELD = 'larena_scope_ref';
    private const string STATE_FIELD = 'larena_record_state';
    private const string RECORDS = 'larena_storage_records';
    private const string VERSIONS = 'larena_storage_record_versions';
    private const string LOCALIZED = 'larena_storage_localized_values';
    private const string PUBLICATION = 'larena_storage_publication_states';
    private const string PUBLICATION_LOG = 'larena_storage_publication_log';
    private const string RELATIONS = 'larena_storage_record_relations';
    private const string BINDINGS = 'larena_storage_structure_role_bindings';
    private const string STRUCTURES = 'larena_storage_workbench_structures';
    private const int BLOB_CHUNK = 1_048_576;

    /** @var array<string, array<string, mixed>> current fields by key */
    private array $fields = [];

    /** @var array<string, bool> */
    private array $tables = [];

    private ?bool $scheduledRevisionColumn = null;

    public function __construct(
        private readonly Connection $database,
        private readonly StorageSitePackRuns $runs,
        private readonly StorageRecordConverter $converter,
        private readonly SchemaDefinitionNormalizer $normalizer,
        private readonly StorageSitePackFiles $files,
        private readonly SitePackLimits $limits,
        private readonly string $runRef,
        private readonly string $scopeRef,
        private readonly string $siteRef,
        private readonly StorageWorkbenchStructure $structure,
        private readonly string $staging,
    ) {
        foreach ($structure->fields as $field) {
            $this->fields[(string) $field['key']] = $field;
        }
    }

    public function structureKey(): string
    {
        return StorageSitePackFormat::structureKey($this->structure->structureId);
    }

    /**
     * Stages the structure line afresh and counts the records.
     *
     * @return int the records of the structure
     */
    public function prepare(StorageSchemaVersion $schema): int
    {
        self::wipe($this->staging);
        self::directory($this->staging);
        $definitionFields = array_values(array_filter(
            $schema->fields,
            static fn (array $field): bool => !in_array($field['key'] ?? null, StorageSitePackFormat::RESERVED_FIELDS, true),
        ));
        $bindings = [];
        if ($this->hasTable(self::BINDINGS)) {
            $bindings = array_values(array_unique(array_map('strval', $this->database->table(self::BINDINGS)
                ->where('schema_id', $this->structure->schema->schemaId)
                ->where('status', 'active')
                ->orderBy('role_ref')
                ->pluck('role_ref')
                ->all())));
        }
        $line = [
            'id' => $this->structure->structureId,
            'label' => $this->structure->label,
            'history_keep' => $this->structure->historyKeep,
            'fields' => array_map(static fn (array $field): array => self::sorted($field), $this->structure->fields),
            'schema' => [
                'owner_package' => $schema->ownerPackage,
                'fields' => array_map(static fn (array $field): array => self::sorted($field), $definitionFields),
            ],
            'role_bindings' => array_map(static fn (string $role): array => ['role_ref' => $role], $bindings),
            'source' => [
                'structure_version' => $this->structure->version,
                'schema_version' => $this->structure->schema->version,
            ],
        ];
        $this->append($this->staging . '/structures.jsonl', 0, StorageSitePackFormat::line($this->objects($line)));

        return $this->database->table(self::RECORDS)->where('schema_id', $this->structure->schema->schemaId)->count();
    }

    /** Removes part files after $part, left by a chunk that was written but never saved. */
    public function dropPartsAfter(int $part): void
    {
        $directory = $this->staging . '/records';
        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $name) {
            if (preg_match('/\Apart-(\d{6})\.jsonl\z/D', $name, $match) === 1 && (int) $match[1] > $part) {
                @unlink($directory . '/' . $name);
            }
        }
    }

    /**
     * Writes the next chunk of records.
     *
     * @param array{after: ?string, part: int, lines: int, bytes: int} $cursor
     * @return array{cursor: array{after: ?string, part: int, lines: int, bytes: int}, records: int, last: bool, counts: array<string, int>, ids: list<array{run_ref: string, kind: string, source_ref: string, target_ref: ?string, action: string, sha256: ?string}>}
     */
    public function records(array $cursor, int $limit): array
    {
        $schemaId = $this->structure->schema->schemaId;
        $query = $this->database->table(self::RECORDS)->where('schema_id', $schemaId);
        if ($cursor['after'] !== null) {
            $query->where('record_id', '>', $cursor['after']);
        }
        /** @var list<stdClass> $heads */
        $heads = $query->orderBy('record_id')->limit($limit)->get(['record_id', 'current_revision', 'current_hash'])->all();
        $counts = ['records' => 0, 'archived_records' => 0, 'versions' => 0, 'localized_values' => 0, 'publications' => 0, 'relations' => 0];
        if ($heads === []) {
            return ['cursor' => $cursor, 'records' => 0, 'last' => true, 'counts' => $counts, 'ids' => []];
        }
        $recordIds = array_map(static fn (stdClass $head): string => (string) $head->record_id, $heads);
        $publications = $this->publications($recordIds);
        $live = [];
        foreach ($heads as $head) {
            $live[(string) $head->record_id] = [(int) $head->current_revision => true];
        }
        foreach ($publications as $recordId => $states) {
            foreach ($states as $state) {
                if ($state['state'] === 'published' && $state['revision'] !== null) {
                    $live[$recordId][$state['revision']] = true;
                }
                if ($state['scheduled_revision'] !== null) {
                    $live[$recordId][$state['scheduled_revision']] = true;
                }
            }
        }
        $versions = $this->versions($recordIds, $live);
        $localized = $this->localized($recordIds, $live);
        $relations = $this->relations($recordIds);

        $fileRefs = [];
        $references = [];
        $relationTargets = [];
        $data = '';
        foreach ($heads as $head) {
            $recordId = (string) $head->record_id;
            $currentRevision = (int) $head->current_revision;
            $current = $versions[$recordId][$currentRevision] ?? null;
            if ($current === null || !hash_equals((string) $head->current_hash, $current['hash'])) {
                throw new StorageRejected('storage_sitepack_record_corrupt');
            }
            $state = $current['values'][self::STATE_FIELD] ?? null;
            if (!in_array($state, ['active', 'archived'], true)) {
                throw new StorageRejected('storage_sitepack_record_corrupt');
            }
            $revisions = array_keys($live[$recordId]);
            sort($revisions);
            $lines = [];
            foreach ($revisions as $revision) {
                $version = $versions[$recordId][$revision] ?? null;
                if ($version === null) {
                    throw new StorageRejected('storage_sitepack_record_corrupt');
                }
                if (($version['values'][self::SCOPE_FIELD] ?? null) !== $this->scopeRef) {
                    throw new StorageRejected('storage_sitepack_record_corrupt');
                }
                $values = $this->converter->convert($schemaId, $version['schema_version'], $this->structure->schema->version, $version['values']);
                foreach (StorageSitePackFormat::RESERVED_FIELDS as $reserved) {
                    unset($values[$reserved]);
                }
                ksort($values, SORT_STRING);
                $translations = [];
                foreach ($localized[$recordId][$revision] ?? [] as $locale => $fieldValues) {
                    $kept = array_intersect_key($fieldValues, $this->fields);
                    if ($kept === []) {
                        continue;
                    }
                    ksort($kept, SORT_STRING);
                    $translations[$locale] = $kept;
                    $counts['localized_values'] += count($kept);
                }
                ksort($translations, SORT_STRING);
                foreach (array_merge([$values], array_values($translations)) as $set) {
                    $this->collect($set, $fileRefs, $references);
                }
                $publishedIn = [];
                foreach ($publications[$recordId] ?? [] as $publication) {
                    if ($publication['state'] === 'published' && $publication['revision'] === $revision) {
                        $publishedIn[] = $publication['locale'];
                    }
                }
                $valuesObject = $values === [] ? new stdClass() : $values;
                $localizedObject = $translations === [] ? new stdClass() : $translations;
                $lines[] = [
                    'revision' => $revision,
                    'current' => $revision === $currentRevision,
                    'published_in' => $publishedIn,
                    'schema_version' => $version['schema_version'],
                    'values' => $valuesObject,
                    'localized' => $localizedObject,
                    'content_hash' => 'sha256:' . hash('sha256', SitePackEnvelope::canonicalJson(['localized' => $localizedObject, 'values' => $valuesObject])),
                ];
                $counts['versions']++;
            }
            $recordRelations = [];
            foreach ($relations[$recordId] ?? [] as $relation) {
                $recordRelations[] = $relation;
                $relationTargets[$relation['to']] = true;
                $counts['relations']++;
            }
            $publicationLines = [];
            foreach ($publications[$recordId] ?? [] as $publication) {
                $publicationLines[] = [
                    'locale' => $publication['locale'],
                    'state' => $publication['state'],
                    'revision' => $publication['state'] === 'published' ? $publication['revision'] : null,
                    'published_at' => $publication['published_at'],
                    'scheduled_revision' => $publication['scheduled_revision'],
                    'scheduled_at' => $publication['scheduled_revision'] === null ? null : $publication['scheduled_at'],
                ];
                $counts['publications']++;
            }
            $line = StorageSitePackFormat::line([
                'id' => $recordId,
                'state' => $state,
                'versions' => $lines,
                'publication' => $publicationLines,
                'relations' => $recordRelations,
            ]);
            if (strlen($line) > $this->limits->maxLineBytes) {
                throw new StorageRejected('storage_sitepack_line_too_long');
            }
            $data .= $line;
            $counts['records']++;
            if ($state === 'archived') {
                $counts['archived_records']++;
            }
        }
        foreach ($this->outside(array_keys($relationTargets)) as $recordId => $structureId) {
            $references[$recordId] ??= $structureId;
        }
        if ($fileRefs !== [] && !$this->files->available()) {
            throw new StorageRejected('storage_sitepack_files_unavailable');
        }

        $part = $cursor['part'];
        $lines = $cursor['lines'];
        $bytes = $cursor['bytes'];
        if ($lines >= StorageSitePackFormat::PART_RECORDS) {
            $part++;
            $lines = 0;
            $bytes = 0;
        }
        self::directory($this->staging . '/records');
        $bytes = $this->append($this->staging . '/records/' . StorageSitePackFormat::partName($part), $bytes, $data);

        $ids = [];
        ksort($fileRefs, SORT_STRING);
        foreach (array_keys($fileRefs) as $logicalRef) {
            $ids[] = ['run_ref' => $this->runRef, 'kind' => StorageSitePackRuns::ID_KIND_FILE, 'source_ref' => (string) $logicalRef, 'target_ref' => null, 'action' => StorageSitePackRuns::ACTION_EXPORT, 'sha256' => null];
        }
        ksort($references, SORT_STRING);
        foreach ($references as $recordId => $structureId) {
            $ids[] = ['run_ref' => $this->runRef, 'kind' => StorageSitePackRuns::ID_KIND_RECORD, 'source_ref' => (string) $recordId, 'target_ref' => $structureId, 'action' => StorageSitePackRuns::ACTION_REFERENCE, 'sha256' => null];
        }

        return [
            'cursor' => ['after' => $recordIds[count($recordIds) - 1], 'part' => $part, 'lines' => $lines + count($heads), 'bytes' => $bytes],
            'records' => count($heads),
            'last' => count($heads) < $limit,
            'counts' => $counts,
            'ids' => $ids,
        ];
    }

    /**
     * Stages the next chunk of files.
     *
     * @param array{after: ?string, lines: int, bytes: int} $cursor
     * @return array{cursor: array{after: ?string, lines: int, bytes: int}, last: bool, counts: array<string, int>, updates: list<array{source_ref: string, action: string, sha256: ?string}>}
     */
    public function files(array $cursor, int $limit): array
    {
        $rows = $this->runs->idsAfter($this->runRef, StorageSitePackRuns::ID_KIND_FILE, null, $cursor['after'], $limit);
        $counts = ['files' => 0, 'files_missing' => 0, 'blobs' => 0, 'blob_bytes' => 0];
        if ($rows === []) {
            return ['cursor' => $cursor, 'last' => true, 'counts' => $counts, 'updates' => []];
        }
        self::directory($this->staging . '/blobs');
        $data = '';
        $updates = [];
        foreach ($rows as $row) {
            $logicalRef = (string) $row->source_ref;
            $file = $this->files->snapshot($this->siteRef, $logicalRef);
            if ($file === null) {
                $updates[] = ['source_ref' => $logicalRef, 'action' => StorageSitePackRuns::ACTION_MISSING, 'sha256' => null];
                $counts['files_missing']++;
                continue;
            }
            if ($file->logicalRef() !== $logicalRef) {
                throw new StorageRejected('storage_sitepack_file_descriptor_invalid');
            }
            $sha256 = $file->sha256();
            $blob = $this->staging . '/blobs/' . $sha256;
            if (!is_file($blob)) {
                $this->stageBlob($file, $blob);
                $counts['blobs']++;
                $counts['blob_bytes'] += $file->sizeBytes();
            }
            $data .= StorageSitePackFormat::line(['key' => StorageSitePackFormat::assetKey($logicalRef)]
                + $file->descriptor
                + ['blob' => StorageSitePackFormat::blob($sha256)]);
            $updates[] = ['source_ref' => $logicalRef, 'action' => StorageSitePackRuns::ACTION_EXPORT, 'sha256' => $sha256];
            $counts['files']++;
        }
        self::directory($this->staging . '/assets');
        $bytes = $this->append($this->staging . '/assets/index.jsonl', $cursor['bytes'], $data);

        return [
            'cursor' => ['after' => (string) $rows[count($rows) - 1]->source_ref, 'lines' => $cursor['lines'] + $counts['files'], 'bytes' => $bytes],
            'last' => count($rows) < $limit,
            'counts' => $counts,
            'updates' => $updates,
        ];
    }

    /**
     * Packs what was staged into the archive at $archivePath.
     *
     * @param array{part: int, bytes: int, lines: int} $records the last records position
     * @param array{bytes: int, lines: int} $assets the last files position
     * @param (\Closure(): void)|null $checkpoint
     * @return array{envelope: SitePackEnvelope, references: int}
     */
    public function seal(string $archivePath, string $exportedAt, array $records, array $assets, ?\Closure $checkpoint): array
    {
        $pack = $this->staging . '/pack';
        self::wipe($pack);
        $writer = new SitePackArchiveWriter($pack, $this->limits);
        try {
            $writer->beginLines(StorageSitePackFormat::STRUCTURES_ID, StorageSitePackFormat::STRUCTURES);
            $this->feed($writer, StorageSitePackFormat::STRUCTURES, $this->staging . '/structures.jsonl', null);
            $writer->endLines(StorageSitePackFormat::STRUCTURES);

            $key = $this->structureKey();
            $hasRecords = $records['lines'] > 0 || $records['part'] > 1;
            for ($part = 1; $hasRecords && $part <= $records['part']; $part++) {
                $path = StorageSitePackFormat::recordsPart($key, $part);
                $writer->beginLines(StorageSitePackFormat::recordsPartId($key, $part), $path);
                $file = $this->staging . '/records/' . StorageSitePackFormat::partName($part);
                $this->feed($writer, $path, $file, $part === $records['part'] ? $records['bytes'] : null);
                $writer->endLines($path);
                if ($checkpoint !== null) {
                    $checkpoint();
                }
            }

            $writer->beginLines(StorageSitePackFormat::REFERENCES_ID, StorageSitePackFormat::REFERENCES);
            $references = 0;
            $after = null;
            do {
                $rows = $this->runs->idsAfter($this->runRef, StorageSitePackRuns::ID_KIND_RECORD, StorageSitePackRuns::ACTION_REFERENCE, $after, 1000);
                foreach ($rows as $row) {
                    $writer->appendLine(StorageSitePackFormat::REFERENCES, [
                        'id' => (string) $row->source_ref,
                        'structure_id' => $row->target_ref === null || $row->target_ref === '' ? null : (string) $row->target_ref,
                    ]);
                    $references++;
                    $after = (string) $row->source_ref;
                }
            } while (count($rows) === 1000);
            $writer->endLines(StorageSitePackFormat::REFERENCES);

            $writer->beginLines(StorageSitePackFormat::ASSET_INDEX_ID, StorageSitePackFormat::ASSET_INDEX);
            if ($assets['lines'] > 0) {
                $this->feed($writer, StorageSitePackFormat::ASSET_INDEX, $this->staging . '/assets/index.jsonl', $assets['bytes']);
            }
            $writer->endLines(StorageSitePackFormat::ASSET_INDEX);

            $digests = $this->runs->blobDigests($this->runRef);
            foreach ($digests as $sha256) {
                $blob = $this->staging . '/blobs/' . $sha256;
                if (!is_file($blob) || hash_file('sha256', $blob) !== $sha256) {
                    throw new StorageRejected('storage_sitepack_file_digest_mismatch');
                }
                $writer->addBlobFromFile(StorageSitePackFormat::blobId($sha256), StorageSitePackFormat::blob($sha256), $blob);
            }
            if ($checkpoint !== null) {
                $checkpoint();
            }

            $envelope = $writer->finish(
                $archivePath,
                StorageSitePackFormat::PACKAGE_PREFIX,
                $exportedAt,
                $digests === [] ? [StorageSitePackFormat::PROFILE_DATA] : [StorageSitePackFormat::PROFILE_DATA, StorageSitePackFormat::PROFILE_ASSETS],
                StorageSitePackFormat::SCHEMA_NAME,
                StorageSitePackFormat::SCHEMA_VERSION,
                StorageSitePackFormat::annotations($this->siteRef, [$this->structure->structureId], $exportedAt),
            );
        } catch (Throwable $exception) {
            $writer->abort();
            throw $exception;
        } finally {
            self::wipe($pack);
        }

        return ['envelope' => $envelope, 'references' => $references];
    }

    /**
     * The publication states of the records in the export's site, by record, in locale order.
     *
     * @param list<string> $recordIds
     * @return array<string, list<array{locale: string, state: string, revision: ?int, published_at: ?string, scheduled_revision: ?int, scheduled_at: ?string}>>
     */
    private function publications(array $recordIds): array
    {
        if (!$this->hasTable(self::PUBLICATION)) {
            return [];
        }
        $this->scheduledRevisionColumn ??= $this->database->getSchemaBuilder()->hasColumn(self::PUBLICATION, 'scheduled_revision');
        $rows = $this->database->table(self::PUBLICATION)
            ->where('schema_id', $this->structure->schema->schemaId)
            ->where('scope_ref', $this->siteRef)
            ->whereIn('record_id', $recordIds)
            ->orderBy('record_id')
            ->orderBy('locale')
            ->get();
        $states = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $scheduledAt = $row['scheduled_at'] === null ? null : (string) $row['scheduled_at'];
            $scheduledRevision = $this->scheduledRevisionColumn && $row['scheduled_revision'] !== null ? (int) $row['scheduled_revision'] : null;
            if ($scheduledRevision === null && $scheduledAt !== null && $this->hasTable(self::PUBLICATION_LOG)) {
                // A schedule written before the state row kept its revision has it in the log only.
                $logged = $this->database->table(self::PUBLICATION_LOG)
                    ->where('publication_id', (string) $row['publication_id'])
                    ->where('transition', 'schedule')
                    ->orderByDesc('id')
                    ->value('revision');
                $scheduledRevision = $logged === null ? null : (int) $logged;
            }
            $states[(string) $row['record_id']][] = [
                'locale' => (string) $row['locale'],
                'state' => (string) $row['state'],
                'revision' => $row['published_revision'] === null ? null : (int) $row['published_revision'],
                'published_at' => self::moment($row['published_at']),
                'scheduled_revision' => $scheduledRevision,
                'scheduled_at' => self::moment($scheduledAt),
            ];
        }

        return $states;
    }

    /**
     * The live versions of the records, with their stored values checked against their hash.
     *
     * @param list<string> $recordIds
     * @param array<string, array<int, true>> $live
     * @return array<string, array<int, array{schema_version: int, values: array<string, mixed>, hash: string}>>
     */
    private function versions(array $recordIds, array $live): array
    {
        $revisions = [];
        foreach ($live as $set) {
            foreach (array_keys($set) as $revision) {
                $revisions[$revision] = true;
            }
        }
        $rows = $this->database->table(self::VERSIONS)
            ->where('schema_id', $this->structure->schema->schemaId)
            ->whereIn('record_id', $recordIds)
            ->whereIn('revision', array_keys($revisions))
            ->get(['record_id', 'revision', 'schema_version', 'values_json', 'content_hash']);
        $versions = [];
        foreach ($rows as $row) {
            $recordId = (string) $row->record_id;
            $revision = (int) $row->revision;
            if (!isset($live[$recordId][$revision])) {
                continue;
            }
            try {
                $values = json_decode((string) $row->values_json, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new StorageRejected('storage_sitepack_record_corrupt');
            }
            if (!is_array($values) || array_is_list($values)
                || !hash_equals((string) $row->content_hash, hash('sha256', $this->normalizer->canonicalJson($values)))) {
                throw new StorageRejected('storage_sitepack_record_corrupt');
            }
            $versions[$recordId][$revision] = ['schema_version' => (int) $row->schema_version, 'values' => $values, 'hash' => (string) $row->content_hash];
        }

        return $versions;
    }

    /**
     * The translations of the live versions: record → revision → locale → field → value.
     *
     * @param list<string> $recordIds
     * @param array<string, array<int, true>> $live
     * @return array<string, array<int, array<string, array<string, mixed>>>>
     */
    private function localized(array $recordIds, array $live): array
    {
        if (!$this->hasTable(self::LOCALIZED)) {
            return [];
        }
        $revisions = [];
        foreach ($live as $set) {
            foreach (array_keys($set) as $revision) {
                $revisions[$revision] = true;
            }
        }
        $rows = $this->database->table(self::LOCALIZED)
            ->where('schema_id', $this->structure->schema->schemaId)
            ->whereIn('record_id', $recordIds)
            ->whereIn('revision', array_keys($revisions))
            ->orderBy('record_id')
            ->orderBy('revision')
            ->orderBy('locale')
            ->orderBy('field_key')
            ->get(['record_id', 'revision', 'locale', 'field_key', 'value_json']);
        $values = [];
        foreach ($rows as $row) {
            $recordId = (string) $row->record_id;
            $revision = (int) $row->revision;
            if (!isset($live[$recordId][$revision])) {
                continue;
            }
            try {
                $value = json_decode((string) $row->value_json, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw new StorageRejected('storage_sitepack_record_corrupt');
            }
            $values[$recordId][$revision][(string) $row->locale][(string) $row->field_key] = $value;
        }

        return $values;
    }

    /**
     * The active outgoing relations of the records.
     *
     * @param list<string> $recordIds
     * @return array<string, list<array{relation_key: string, kind: string, to: string, order_index: int, depth: int}>>
     */
    private function relations(array $recordIds): array
    {
        if (!$this->hasTable(self::RELATIONS)) {
            return [];
        }
        $rows = $this->database->table(self::RELATIONS)
            ->whereIn('from_record_id', $recordIds)
            ->where('status', 'active')
            ->orderBy('from_record_id')
            ->orderBy('relation_key')
            ->orderBy('to_record_id')
            ->get(['from_record_id', 'relation_key', 'kind', 'to_record_id', 'order_index', 'depth']);
        $relations = [];
        foreach ($rows as $row) {
            $relations[(string) $row->from_record_id][] = [
                'relation_key' => (string) $row->relation_key,
                'kind' => (string) $row->kind,
                'to' => (string) $row->to_record_id,
                'order_index' => (int) $row->order_index,
                'depth' => (int) $row->depth,
            ];
        }

        return $relations;
    }

    /**
     * Of these record ids, those outside this storage, with the structure they belong to (null when none).
     *
     * @param list<string> $recordIds
     * @return array<string, ?string>
     */
    private function outside(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }
        $schemas = [];
        foreach ($this->database->table(self::RECORDS)->whereIn('record_id', $recordIds)->get(['record_id', 'schema_id']) as $row) {
            $schemas[(string) $row->record_id] = (string) $row->schema_id;
        }
        $outside = [];
        $schemaIds = [];
        foreach ($recordIds as $recordId) {
            $schemaId = $schemas[$recordId] ?? null;
            if ($schemaId === $this->structure->schema->schemaId) {
                continue;
            }
            $outside[$recordId] = $schemaId;
            if ($schemaId !== null) {
                $schemaIds[$schemaId] = true;
            }
        }
        $structures = [];
        if ($schemaIds !== []) {
            foreach ($this->database->table(self::STRUCTURES)->whereIn('storage_schema_id', array_keys($schemaIds))->get(['storage_schema_id', 'structure_id']) as $row) {
                $structures[(string) $row->storage_schema_id] = (string) $row->structure_id;
            }
        }
        $result = [];
        foreach ($outside as $recordId => $schemaId) {
            $result[$recordId] = $schemaId === null ? null : ($structures[$schemaId] ?? null);
        }

        return $result;
    }

    /**
     * Files (file@1) and records of other structures (record@1) one set of values points at.
     *
     * @param array<string, mixed> $values
     * @param array<string, true> $files
     * @param array<string, ?string> $references
     */
    private function collect(array $values, array &$files, array &$references): void
    {
        foreach ($values as $key => $value) {
            $field = $this->fields[(string) $key] ?? null;
            if ($field === null || !is_string($value) || $value === '') {
                continue;
            }
            if (($field['type'] ?? null) === 'file') {
                $files[$value] = true;
            } elseif (($field['type'] ?? null) === 'record') {
                $target = is_array($field['constraints'] ?? null) ? ($field['constraints']['target_structure_id'] ?? null) : null;
                if (is_string($target) && $target !== $this->structure->structureId) {
                    $references[$value] ??= $target;
                }
            }
        }
    }

    private function stageBlob(StorageSitePackFile $file, string $blob): void
    {
        $temporary = $blob . '.part';
        $source = $file->openStream();
        $target = @fopen($temporary, 'wb');
        if ($target === false) {
            fclose($source);
            throw new StorageRejected('storage_sitepack_staging_failed');
        }
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, self::BLOB_CHUNK);
                if ($chunk === false) {
                    throw new StorageRejected('storage_sitepack_file_unreadable');
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($hash, $chunk);
                $size += strlen($chunk);
                if ($size > $this->limits->maxTotalBytes) {
                    throw new StorageRejected('sitepack_archive_too_large');
                }
                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new StorageRejected('storage_sitepack_staging_failed');
                }
            }
        } finally {
            fclose($source);
            fclose($target);
        }
        if (hash_final($hash) !== $file->sha256() || $size !== $file->sizeBytes()) {
            @unlink($temporary);
            throw new StorageRejected('storage_sitepack_file_digest_mismatch');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $blob)) {
            @unlink($temporary);
            throw new StorageRejected('storage_sitepack_staging_failed');
        }
    }

    /** Feeds the staged lines of $file (up to $bytes) into the writer, one object per line. */
    private function feed(SitePackArchiveWriter $writer, string $path, string $file, ?int $bytes): void
    {
        $handle = is_file($file) ? @fopen($file, 'rb') : false;
        if ($handle === false) {
            throw new StorageRejected('storage_sitepack_staging_missing');
        }
        try {
            $read = 0;
            while (($bytes === null || $read < $bytes) && ($line = fgets($handle, $this->limits->maxLineBytes + 2)) !== false) {
                $read += strlen($line);
                if (!str_ends_with($line, "\n")) {
                    throw new StorageRejected('storage_sitepack_staging_corrupt');
                }
                try {
                    $object = json_decode($line, false, $this->limits->maxJsonDepth, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    throw new StorageRejected('storage_sitepack_staging_corrupt');
                }
                if (!$object instanceof stdClass) {
                    throw new StorageRejected('storage_sitepack_staging_corrupt');
                }
                $writer->appendLine($path, $object);
            }
            if ($bytes !== null && $read !== $bytes) {
                throw new StorageRejected('storage_sitepack_staging_corrupt');
            }
        } finally {
            fclose($handle);
        }
    }

    /** Writes $data at $offset, dropping whatever followed it. @return int the file's length after */
    private function append(string $file, int $offset, string $data): int
    {
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            throw new StorageRejected('storage_sitepack_staging_failed');
        }
        try {
            if (!ftruncate($handle, $offset) || fseek($handle, $offset) !== 0) {
                throw new StorageRejected('storage_sitepack_staging_failed');
            }
            $length = strlen($data);
            $written = 0;
            while ($written < $length) {
                $result = fwrite($handle, $written === 0 ? $data : substr($data, $written));
                if ($result === false || $result === 0) {
                    throw new StorageRejected('storage_sitepack_staging_failed');
                }
                $written += $result;
            }
            fflush($handle);
        } finally {
            fclose($handle);
        }
        @chmod($file, 0600);

        return $offset + strlen($data);
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= $this->database->getSchemaBuilder()->hasTable($table);
    }

    /**
     * The structure line with empty maps written as objects.
     *
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function objects(array $line): array
    {
        $line['fields'] = array_map(static function (array $field): array {
            if (($field['constraints'] ?? null) === []) {
                $field['constraints'] = new stdClass();
            }

            return $field;
        }, $line['fields']);
        $line['schema']['fields'] = array_map(static function (array $field): array {
            if (($field['constraints'] ?? null) === []) {
                $field['constraints'] = new stdClass();
            }

            return $field;
        }, $line['schema']['fields']);

        return $line;
    }

    /** @param array<string, mixed> $field @return array<string, mixed> */
    private static function sorted(array $field): array
    {
        ksort($field, SORT_STRING);

        return $field;
    }

    /** A stored UTC time (`Y-m-d H:i:s`) as ISO 8601 with Z. */
    private static function moment(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return null;
        }
    }

    public static function directory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new StorageRejected('storage_sitepack_staging_failed');
        }
        @chmod($path, 0700);
    }

    /** Removes a directory tree (no links followed). */
    public static function wipe(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            self::wipe($path . '/' . $name);
        }
        @rmdir($path);
    }
}
