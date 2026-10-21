<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/publication-schema.php';

use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Exceptions\PublicationRejected;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;

/**
 * Put a record into a given state, then try a transition.
 *
 * @return array{0: bool, 1: string}
 */
function larena_storage_try_transition(string $startState, string $transition): array
{
    $publication = new DatabasePublicationLifecycle(larena_storage_publication_connection());
    $args = ['site.pages', 'node-1', 'site:main', 'en'];

    // Reach the starting state.
    match ($startState) {
        'absent' => null,
        'draft' => (static function () use ($publication, $args): void {
            $publication->publish(...[...$args, 1, 'actor:setup']);
            $publication->unpublish(...[...$args, 'actor:setup']);
        })(),
        'scheduled' => $publication->schedule(...[...$args, 2, '2099-01-01 00:00:00', 'actor:setup']),
        'published' => $publication->publish(...[...$args, 1, 'actor:setup']),
        'archived' => $publication->archive(...[...$args, 'actor:setup']),
        default => throw new RuntimeException('unknown start state: ' . $startState),
    };

    try {
        match ($transition) {
            'publish' => $publication->publish(...[...$args, 7, 'actor:editor']),
            'unpublish' => $publication->unpublish(...[...$args, 'actor:editor']),
            'schedule' => $publication->schedule(...[...$args, 7, '2099-06-01 00:00:00', 'actor:editor']),
            'archive' => $publication->archive(...[...$args, 'actor:editor']),
            default => throw new RuntimeException('unknown transition: ' . $transition),
        };
    } catch (PublicationRejected $rejection) {
        return [false, $rejection->reasonCode];
    }

    return [true, ''];
}

/**
 * The frozen matrix. `absent` behaves like `draft`, because a record nobody has
 * published yet is a draft in every sense that matters.
 */
$matrix = [
    ['absent', 'publish', true],
    ['absent', 'schedule', true],
    ['absent', 'archive', true],
    ['absent', 'unpublish', false],
    ['draft', 'publish', true],
    ['draft', 'schedule', true],
    ['draft', 'archive', true],
    ['draft', 'unpublish', false],
    ['scheduled', 'publish', true],
    ['scheduled', 'unpublish', true],
    ['scheduled', 'schedule', true],
    ['scheduled', 'archive', true],
    ['published', 'publish', true],
    ['published', 'unpublish', true],
    ['published', 'schedule', true],
    ['published', 'archive', true],
    ['archived', 'publish', true],
    ['archived', 'archive', true],
    ['archived', 'unpublish', false],
    ['archived', 'schedule', false],
];

foreach ($matrix as [$from, $transition, $allowed]) {
    [$succeeded, $reason] = larena_storage_try_transition($from, $transition);

    larena_storage_role_assert(
        $succeeded === $allowed,
        $transition . ' from ' . $from . ' expected ' . ($allowed ? 'allowed' : 'refused')
        . ', got ' . ($succeeded ? 'allowed' : 'refused (' . $reason . ')'),
    );

    if (!$allowed) {
        larena_storage_role_assert(
            $reason === 'invalid_transition',
            'a refused transition says invalid_transition, got ' . $reason,
        );
    }
}

// An illegal transition writes nothing at all: not the state, not the log.
$connection = larena_storage_publication_connection();
$publication = new DatabasePublicationLifecycle($connection);
$refused = false;
try {
    $publication->unpublish('site.pages', 'node-1', 'site:main', 'en', 'actor:editor');
} catch (PublicationRejected) {
    $refused = true;
}
larena_storage_role_assert($refused);
larena_storage_role_assert($connection->table(DatabasePublicationLifecycle::STATES_TABLE)->count() === 0);
larena_storage_role_assert(
    $connection->table(DatabasePublicationLifecycle::LOG_TABLE)->count() === 0,
    'a refused transition appends no log row either',
);

// A schedule does not publish. This is the rule the whole sweep exists to serve.
$scheduled = $publication->schedule('site.pages', 'node-2', 'site:main', 'en', 4, '2099-01-01 00:00:00', 'actor:editor');
larena_storage_role_assert($scheduled->state === PublicationStateValue::Scheduled);
larena_storage_role_assert($scheduled->publishedRevision === null, 'scheduling does not set a head');
larena_storage_role_assert(!$scheduled->isPublished(), 'and a scheduled record is not published');
larena_storage_role_assert($scheduled->scheduledAt === '2099-01-01 00:00:00');

larena_storage_role_assert($scheduled->scheduledRevision === 4, 'the schedule keeps its revision on the state row');

// Scheduling a record that is already published leaves it published with the live head:
// readers keep seeing the current version until the schedule fires.
$publication->publish('site.pages', 'node-3', 'site:main', 'en', 1, 'actor:editor');
$rescheduled = $publication->schedule('site.pages', 'node-3', 'site:main', 'en', 2, '2099-01-01 00:00:00', 'actor:editor');
larena_storage_role_assert($rescheduled->state === PublicationStateValue::Published, 'a schedule does not take a published record down');
larena_storage_role_assert($rescheduled->isPublished() && $rescheduled->publishedRevision === 1, 'the live head survives a schedule');
larena_storage_role_assert($rescheduled->scheduledRevision === 2 && $rescheduled->scheduledAt === '2099-01-01 00:00:00');
larena_storage_role_assert($rescheduled->hasPendingRevision() && !$scheduled->hasPendingRevision());
$history = $publication->history('site.pages', 'node-3', 'site:main', 'en');
larena_storage_role_assert(
    $history[1]->fromState === PublicationStateValue::Published && $history[1]->toState === PublicationStateValue::Published,
    'the log says the record stayed published',
);
$explained = $publication->explain('site.pages', 'node-3', 'site:main', 'en');
larena_storage_role_assert($explained['scheduled_revision'] === 2 && $explained['has_pending_revision'] === true);
larena_storage_role_assert(in_array('sweep_publish', $explained['allowed_transitions'], true), 'a pending schedule may be swept');
larena_storage_role_assert(
    !in_array('sweep_publish', $publication->explain('site.pages', 'node-1', 'site:main', 'en')['allowed_transitions'], true),
    'nothing to sweep without a schedule',
);

echo "Publication transition matrix passed.\n";
