<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

/**
 * The Storage section's parts that follow background work by themselves ({@see AdminLiveRegion}), and when each one
 * is read again: only while its work is queued or running. A finished, failed or cancelled job or run, and a page with
 * no work at all, are drawn without the live mark, so nothing is asked.
 *
 * - the jobs panel (CSV imports and exports) while a job is queued, waiting for its list, running, retrying or being cancelled;
 * - «Перенос на другой сайт» on the settings page while an export is queued or running;
 * - a SitePack import run's page while its archive is checked or the import runs;
 * - the field change or encryption run notice of the records and settings pages while the change or run goes on.
 */
final class StorageLiveRegions
{
    public const JOBS = 'storage-jobs';
    public const SITEPACK_EXPORT = 'storage-sitepack-export';
    public const SITEPACK_IMPORT = 'storage-sitepack-import';
    public const FIELD_CHANGE = 'storage-field-change';

    private const JOB_ACTIVE = ['queued', 'waiting', 'running', 'retrying', 'cancelling'];
    private const RUN_ACTIVE = ['queued', 'running'];
    private const NOTICE_ACTIVE = ['running', 'encryption_running'];

    /** @param list<array<string, mixed>> $jobs the person's jobs as StorageBackgroundJobs lists them */
    public static function jobsActive(array $jobs): bool
    {
        foreach ($jobs as $job) {
            if (in_array($job['state'] ?? null, self::JOB_ACTIVE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the jobs panel says now in a few words: each job's title and state.
     *
     * @param list<array<string, mixed>> $items the panel's items (StorageJobsPanel::items())
     */
    public static function jobsSaid(array $items): string
    {
        $said = [];
        foreach ($items as $item) {
            if (is_string($item['title'] ?? null) && is_string($item['state'] ?? null)) {
                $said[] = $item['title'].': '.$item['state'];
            }
        }

        return implode('; ', $said);
    }

    /** @param list<array<string, mixed>>|null $runs the person's SitePack exports (StorageSitePackExports::runs()) */
    public static function sitePackExportActive(?array $runs): bool
    {
        foreach ($runs ?? [] as $run) {
            if (in_array($run['state'] ?? null, self::RUN_ACTIVE, true)) {
                return true;
            }
        }

        return false;
    }

    /** A SitePack import run whose archive is being checked or whose import runs. @param array<string, mixed> $run */
    public static function sitePackImportActive(array $run): bool
    {
        return ($run['planning'] ?? false) === true || ($run['active'] ?? false) === true;
    }

    /** A field change or an encryption run going on (not one that failed or was cancelled). @param array<string, mixed>|null $notice */
    public static function fieldChangeActive(?array $notice): bool
    {
        return $notice !== null && in_array($notice['kind'] ?? null, self::NOTICE_ACTIVE, true);
    }
}
