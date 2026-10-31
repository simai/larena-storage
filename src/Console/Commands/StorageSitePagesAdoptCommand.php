<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use InvalidArgumentException;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\FirstRun\SitePagesStorage;
use Throwable;

/**
 * Makes the site pages of an existing installation an ordinary storage, «Страницы сайта» (SitePagesStorage), as a
 * new installation's first run does: the same pages, ids, addresses, translations and publications. Run once after
 * the update; running it again changes nothing. --dry-run prints the plan and writes nothing.
 *
 * It runs as the administrator named by --actor, who needs the rights to create storages in the scope: the storage
 * belongs to the scope they work in (or --scope), the one the storage section lists. A system actor has no scope.
 *
 *   larena:storage:adopt-site-pages --actor=user:admin_identity:1 [--dry-run]
 *   … --scope=scope:main   another scope of that administrator
 *   … --locale=en                 the language of the storage's name (the application's by default)
 *   … --json                      the machine-readable receipt only
 */
final class StorageSitePagesAdoptCommand extends Command
{
    protected $signature = 'larena:storage:adopt-site-pages
        {--actor= : The administrator the adoption runs as (required)}
        {--scope= : The scope to adopt the pages into (the one the administrator works in by default)}
        {--locale= : The language of the storage name}
        {--dry-run : Print the plan without writing anything}
        {--json : Emit the machine-readable receipt only}';

    protected $description = 'Make the site pages an ordinary storage in place (same pages, ids and addresses); idempotent.';

    public function handle(SitePagesStorage $pages, ExceptionHandler $errors): int
    {
        $actor = trim((string) $this->option('actor'));
        $scope = trim((string) $this->option('scope'));
        $locale = trim((string) $this->option('locale'));
        if (preg_match('/\Auser:admin_identity:[1-9][0-9]*\z/D', $actor) !== 1
            || ($locale !== '' && preg_match('/\A[a-z]{2}(?:[-_][A-Za-z0-9]{2,8})?\z/D', $locale) !== 1)) {
            return $this->refuse('storage_site_pages_input_invalid');
        }
        $dryRun = (bool) $this->option('dry-run');
        try {
            $receipt = $dryRun
                ? $pages->plan($actor, $scope === '' ? null : $scope, $locale === '' ? null : $locale)
                : $pages->adopt($actor, $scope === '' ? null : $scope, $locale === '' ? null : $locale);
        } catch (StorageRejected $rejected) {
            return $this->refuse($rejected->reasonCode);
        } catch (InvalidArgumentException $invalid) {
            return $this->refuse($invalid->getMessage());
        } catch (Throwable $failure) {
            $errors->report($failure);

            return $this->refuse('storage_site_pages_failed');
        }
        $this->line(json_encode(['schema' => 'larena.storage_site_pages', 'status' => $dryRun ? 'planned' : 'ready', 'dry_run' => $dryRun, ...$receipt],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        $this->line(json_encode(['schema' => 'larena.storage_site_pages', 'status' => 'refused', 'reason_code' => $reason], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
