<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/publication-schema.php';

use Larena\Storage\Contracts\PublicationTransition;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;

$connection = larena_storage_publication_connection();
$publication = new DatabasePublicationLifecycle($connection);
$args = ['site.pages', 'node-1', 'site:main', 'en'];

$publication->publish(...[...$args, 1, 'actor:editor', 'correlation-a']);
$publication->publish(...[...$args, 2, 'actor:editor']);
$publication->unpublish(...[...$args, 'actor:reviewer']);
$publication->schedule(...[...$args, 3, '2099-01-01 00:00:00', 'actor:editor']);
$publication->archive(...[...$args, 'actor:admin']);

$history = $publication->history(...$args);

larena_storage_role_assert(count($history) === 5, 'every transition is in the log');

$sequence = array_map(
    static fn (PublicationTransition $t): string => $t->fromState->value . '->' . $t->toState->value . ':' . $t->transition->value,
    $history,
);

larena_storage_role_assert($sequence === [
    'draft->published:publish',
    'published->published:publish',
    'published->draft:unpublish',
    'draft->scheduled:schedule',
    'scheduled->archived:archive',
], 'the log is in order with the from and to state of each transition: ' . implode(' | ', $sequence));

// The revision is recorded where it is meaningful and null where it is not.
larena_storage_role_assert($history[0]->revision === 1);
larena_storage_role_assert($history[1]->revision === 2);
larena_storage_role_assert($history[2]->revision === null, 'an unpublish names no revision');
larena_storage_role_assert($history[3]->revision === 3, 'a schedule records which revision it will publish');
larena_storage_role_assert($history[3]->scheduledAt === '2099-01-01 00:00:00');
larena_storage_role_assert($history[4]->revision === null);

// The actor of each transition is recorded, which is what makes the log accountable.
larena_storage_role_assert($history[2]->actorId === 'actor:reviewer');
larena_storage_role_assert($history[4]->actorId === 'actor:admin');

// The log carries no field values, because a publication event may be read by someone
// who cannot read the record.
$rendered = json_encode(array_map(static fn (PublicationTransition $t): array => $t->toArray(), $history));
larena_storage_role_assert(is_string($rendered));
foreach (['value', 'title', 'body'] as $forbidden) {
    larena_storage_role_assert(!str_contains($rendered, '"' . $forbidden . '"'), 'the log carries no ' . $forbidden);
}

// The log is append only: nothing in this batch updates or deletes a row.
$idsBefore = $connection->table(DatabasePublicationLifecycle::LOG_TABLE)->pluck('id')->all();
$publication->publish(...[...$args, 4, 'actor:editor']);
$idsAfter = $connection->table(DatabasePublicationLifecycle::LOG_TABLE)->pluck('id')->all();
larena_storage_role_assert(
    array_slice($idsAfter, 0, count($idsBefore)) === $idsBefore,
    'existing log rows keep their ids: the log only grows',
);
larena_storage_role_assert(count($idsAfter) === count($idsBefore) + 1);

// History is per record, scope and locale.
$publication->publish('site.pages', 'node-1', 'site:main', 'ru', 1, 'actor:translator');
larena_storage_role_assert(count($publication->history('site.pages', 'node-1', 'site:main', 'ru')) === 1);
larena_storage_role_assert(count($publication->history(...$args)) === 6, 'the English log is unaffected');
larena_storage_role_assert($publication->history('site.pages', 'node-9', 'site:main', 'en') === [],
    'a record with no transitions has an empty history, not an error');

// The limit is honoured and must be positive.
larena_storage_role_assert(count($publication->history(...[...$args, 2])) === 2);
$limitRefused = false;
try {
    $publication->history(...[...$args, 0]);
} catch (\Larena\Storage\Exceptions\PublicationRejected $rejection) {
    $limitRefused = $rejection->reasonCode === 'invalid_limit';
}
larena_storage_role_assert($limitRefused);

echo "Publication history passed.\n";
