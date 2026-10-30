<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Closure;
use Illuminate\Database\Connection;
use Larena\Storage\Contracts\StorageSitePackRun;

/** Reads an import's id map for one chunk of records: the targets of the pack's records, references, users and files. */
final class StorageSitePackIdMap
{
    /**
     * @param list<string> $internal records of the pack
     * @param list<string> $external records of other storages
     * @return array{internal: array<string, string>, external: array<string, string>}
     */
    public static function lookup(StorageSitePackRuns $runs, string $runRef, array $internal, array $external): array
    {
        $maps = ['internal' => [], 'external' => []];
        foreach ($internal === [] ? [] : $runs->idsFor($runRef, StorageSitePackRuns::ID_KIND_RECORD, $internal) as $source => $row) {
            if (is_string($row->target_ref) && $row->target_ref !== '') {
                $maps['internal'][$source] = $row->target_ref;
            }
        }
        foreach ($external === [] ? [] : $runs->idsFor($runRef, StorageSitePackRuns::ID_KIND_REFERENCE, $external) as $source => $row) {
            if (is_string($row->target_ref) && $row->target_ref !== '') {
                $maps['external'][$source] = $row->target_ref;
            }
        }

        return $maps;
    }

    /**
     * @param list<string> $users
     * @return array<string, bool> user ref => kept
     */
    public static function users(StorageSitePackRuns $runs, string $runRef, array $users): array
    {
        $kept = [];
        foreach ($users === [] ? [] : $runs->idsFor($runRef, StorageSitePackRuns::ID_KIND_USER, $users) as $source => $row) {
            $kept[$source] = $row->action === 'keep';
        }

        return $kept;
    }

    /**
     * @param list<string> $files
     * @return array<string, string> source logical ref => target logical ref
     */
    public static function files(StorageSitePackRuns $runs, string $runRef, array $files): array
    {
        $targets = [];
        foreach ($files === [] ? [] : $runs->idsFor($runRef, StorageSitePackRuns::ID_KIND_FILE, $files) as $source => $row) {
            if (is_string($row->target_ref) && $row->target_ref !== '') {
                $targets[$source] = $row->target_ref;
            }
        }

        return $targets;
    }

    /**
     * The storage a list block's schema id names on this site, for one import (StorageSitePackValues): the pack's own
     * storage → the one it becomes ($targetSchemaId); another storage → the one the latest earlier import of this
     * scope made of it (a pack that said its schema id) while that one is here; else the same schema when this site
     * has it; else null. The plan and the import ask the same, so they decide the same.
     *
     * @return Closure(string): ?string
     */
    public static function listTargets(Connection $database, string $scopeRef, string $runRef, ?string $sourceSchemaId, ?string $targetSchemaId): Closure
    {
        $known = [];

        return static function (string $schemaId) use ($database, $scopeRef, $runRef, $sourceSchemaId, $targetSchemaId, &$known): ?string {
            if (array_key_exists($schemaId, $known)) {
                return $known[$schemaId];
            }
            if ($sourceSchemaId !== null && $schemaId === $sourceSchemaId) {
                return $known[$schemaId] = $targetSchemaId;
            }
            $exists = static fn (string $id): bool => $database->table('larena_storage_schemas')->where('schema_id', $id)->exists();
            foreach ($database->table(StorageSitePackRuns::TABLE)
                ->where('kind', StorageSitePackRun::KIND_IMPORT)
                ->where('state', StorageSitePackRun::STATE_DONE)
                ->where('scope_ref', $scopeRef)
                ->where('run_ref', '!=', $runRef)
                // A first sieve (`_` matches any letter); each candidate is read below.
                ->where('options_json', 'like', '%"source_schema_id":"' . $schemaId . '"%')
                ->orderByDesc('finished_at')
                ->pluck('options_json') as $json) {
                $options = json_decode((string) $json, true);
                $import = is_array($options) && is_array($options['import'] ?? null) ? $options['import'] : [];
                if (($import['source_schema_id'] ?? null) === $schemaId && is_string($import['schema_id'] ?? null) && $exists($import['schema_id'])) {
                    return $known[$schemaId] = $import['schema_id'];
                }
            }

            return $known[$schemaId] = $exists($schemaId) ? $schemaId : null;
        };
    }
}
