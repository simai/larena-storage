<?php

declare(strict_types=1);

namespace Larena\Storage\Console\Commands;

use Illuminate\Console\Command;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Exceptions\PublicationRejected;

/**
 * Publishes every schedule that is due. The Laravel scheduler runs it every
 * minute; on ordinary hosting one cron line starts the scheduler:
 * `* * * * * php artisan schedule:run`. Each publication is recorded in the
 * record's publication history under the scheduler actor.
 */
final class PublicationSweepCommand extends Command
{
    public const ACTOR = 'system:scheduler';

    protected $signature = 'storage:publication:sweep
        {--limit=200 : Most schedules to publish in one run}
        {--now= : Treat this ISO-8601 moment as now (for checks and catching up)}';

    protected $description = 'Publish every Storage publication schedule that is due.';

    public function handle(PublicationLifecycle $publication): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false) {
            $this->error('The limit must be a positive integer.');

            return self::INVALID;
        }
        $now = $this->option('now');
        try {
            $result = $publication->sweep(self::ACTOR, is_string($now) && $now !== '' ? $now : null, $limit);
        } catch (PublicationRejected $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line((string) json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
