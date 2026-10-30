<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Larena\Storage\SiteRedirect\SiteRedirectsStorage;
use Throwable;

/**
 * Gives each site of an existing installation its «Переадресации» / "Redirects" storage (SiteRedirectsStorage), as a
 * new installation's first run does. Run once after the update; running it again changes nothing. --dry-run prints
 * the plan and writes nothing.
 *
 * It runs as the administrator named by --actor, who needs the rights to create storages in each site's scope. A
 * site that cannot get its storage is reported `refused` with the reason (the command then ends with a failure),
 * and the other sites go on.
 *
 *   larena:storage:site-redirects --actor=user:admin_identity:1 [--dry-run]
 *   … --site=site:main   one site only
 *   … --locale=en        the language of the storage's name and fields (the application's by default)
 *   … --json             the machine-readable receipt only
 */
final class StorageSiteRedirectsCommand extends Command
{
    protected $signature = 'larena:storage:site-redirects
        {--actor= : The administrator the run goes as (required)}
        {--site= : One site, by its scope ref (every site by default)}
        {--locale= : The language of the storage name}
        {--dry-run : Print the plan without writing anything}
        {--json : Emit the machine-readable receipt only}';

    protected $description = 'Give each site its redirects storage («Переадресации»); idempotent.';

    public function handle(SiteRedirectsStorage $redirects, ExceptionHandler $errors): int
    {
        $actor = trim((string) $this->option('actor'));
        $site = trim((string) $this->option('site'));
        $locale = trim((string) $this->option('locale'));
        if (preg_match('/\Auser:admin_identity:[1-9][0-9]*\z/D', $actor) !== 1
            || ($site !== '' && preg_match('/\Asite:[a-z0-9][a-z0-9_-]{0,62}\z/D', $site) !== 1)
            || ($locale !== '' && preg_match('/\A[a-z]{2}(?:[-_][A-Za-z0-9]{2,8})?\z/D', $locale) !== 1)) {
            return $this->refuse('storage_site_redirects_input_invalid');
        }
        $dryRun = (bool) $this->option('dry-run');
        try {
            $receipt = $dryRun
                ? $redirects->plan($actor, $site === '' ? null : $site, $locale === '' ? null : $locale)
                : $redirects->create($actor, $site === '' ? null : $site, $locale === '' ? null : $locale);
        } catch (Throwable $failure) {
            $errors->report($failure);

            return $this->refuse('storage_site_redirects_failed');
        }
        $refused = in_array(SiteRedirectsStorage::REFUSED, array_column($receipt['sites'], 'state'), true);
        $this->line(json_encode(['schema' => 'larena.storage_site_redirects', 'status' => $refused ? 'partial' : ($dryRun ? 'planned' : 'ready'),
            'dry_run' => $dryRun, ...$receipt], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return $refused ? self::FAILURE : self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        $this->line(json_encode(['schema' => 'larena.storage_site_redirects', 'status' => 'refused', 'reason_code' => $reason], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
