<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

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
}
