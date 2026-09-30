<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/publication-schema.php';

use Illuminate\Container\Container;
use Larena\Storage\Console\Commands\PublicationSweepCommand;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// The scheduler's command publishes what is due, under the scheduler actor.
$connection = larena_storage_publication_connection();
$publication = new DatabasePublicationLifecycle($connection);
$publication->schedule('site.pages', 'due', 'site:main', 'en', 2, '2020-01-01 00:00:00', 'actor:editor');
$publication->schedule('site.pages', 'later', 'site:main', 'en', 1, '2099-01-01 00:00:00', 'actor:editor');

// A console command asks its application whether it runs under tests.
$container = new class extends Container {
    public function runningUnitTests(): bool
    {
        return true;
    }
};
$container->instance(PublicationLifecycle::class, $publication);
$run = static function (array $input) use ($container): array {
    $command = new PublicationSweepCommand();
    $command->setLaravel($container);
    $output = new BufferedOutput();
    $arguments = new ArrayInput($input);
    $arguments->setInteractive(false);
    $status = $command->run($arguments, $output);

    return [$status, trim($output->fetch())];
};

[$status, $output] = $run(['--now' => '2026-09-30 12:00:00']);
larena_storage_role_assert($status === 0, 'the sweep succeeds');
$result = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
larena_storage_role_assert($result['published_count'] === 1, 'only the due schedule is published');
larena_storage_role_assert($publication->head('site.pages', 'due', 'site:main', 'en')?->publishedRevision === 2);
larena_storage_role_assert(!$publication->head('site.pages', 'later', 'site:main', 'en')?->isPublished());
$history = $publication->history('site.pages', 'due', 'site:main', 'en');
larena_storage_role_assert(end($history)->actorId === PublicationSweepCommand::ACTOR, 'the publication names the scheduler');

// A second run finds nothing due: the sweep is idempotent.
[$status, $output] = $run(['--now' => '2026-09-30 12:01:00']);
larena_storage_role_assert($status === 0 && json_decode($output, true)['published_count'] === 0);

// A bad limit is refused before anything runs.
[$status] = $run(['--limit' => '0']);
larena_storage_role_assert($status === 2, 'a non-positive limit is invalid');

// The provider registers the command and schedules it every minute without overlap.
$provider = (string) file_get_contents(__DIR__ . '/../../src/Providers/StorageServiceProvider.php');
larena_storage_role_assert(str_contains($provider, "\$schedule->command('storage:publication:sweep')"));
larena_storage_role_assert(str_contains($provider, '->everyMinute()') && str_contains($provider, '->withoutOverlapping()'));

echo "Publication sweep command passed.\n";
