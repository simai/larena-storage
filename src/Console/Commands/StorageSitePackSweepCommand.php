<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\SitePack\DatabaseStorageSitePack;

/**
 * Removes storage SitePack runs older than --days (7 by default): their archives, staging and rows. The application
 * schedules it (daily is enough).
 */
final class StorageSitePackSweepCommand extends Command
{
    protected $signature = 'larena:storage:sitepack:sweep {--days=7 : Remove runs started more than this many days ago}';

    protected $description = 'Remove old Larena Storage SitePack runs and their private archives.';

    public function handle(DatabaseStorageSitePack $sitePack): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($days === false) {
            $this->error('The days must be an integer from 1 to 3650.');

            return self::INVALID;
        }
        try {
            $removed = $sitePack->sweep($days);
        } catch (StorageRejected $exception) {
            $this->error($exception->reasonCode);

            return self::FAILURE;
        }
        $this->line((string) json_encode(['removed' => $removed, 'days' => $days]));

        return self::SUCCESS;
    }
}
