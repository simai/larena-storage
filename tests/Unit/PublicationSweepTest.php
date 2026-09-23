<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/publication-schema.php';

use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;

$connection = larena_storage_publication_connection();
$publication = new DatabasePublicationLifecycle($connection);

// Two schedules due in the past, one in the future.
$publication->schedule('site.pages', 'due-1', 'site:main', 'en', 3, '2020-01-01 00:00:00', 'actor:editor');
$publication->schedule('site.pages', 'due-2', 'site:main', 'ru', 1, '2020-06-01 00:00:00', 'actor:editor');
$publication->schedule('site.pages', 'later', 'site:main', 'en', 2, '2099-01-01 00:00:00', 'actor:editor');

// Nothing is published yet: a schedule is an intention, not a publication.
foreach (['due-1', 'due-2', 'later'] as $record) {
    $locale = $record === 'due-2' ? 'ru' : 'en';
    $head = $publication->head('site.pages', $record, 'site:main', $locale);
    larena_storage_role_assert($head !== null && !$head->isPublished(), $record . ' is not published before the sweep');
}

$result = $publication->sweep('actor:scheduler', '2026-09-23 12:00:00');

larena_storage_role_assert($result['published_count'] === 2, 'only the due schedules fire');
larena_storage_role_assert($result['swept_at'] === '2026-09-23 12:00:00');

$publishedIds = array_column($result['published'], 'publication_id');
larena_storage_role_assert(in_array('site.pages|due-1|site:main|en', $publishedIds, true));
larena_storage_role_assert(in_array('site.pages|due-2|site:main|ru', $publishedIds, true));
larena_storage_role_assert(!in_array('site.pages|later|site:main|en', $publishedIds, true), 'the future schedule waits');

// The sweep published the revision the schedule recorded, not some default.
$due1 = $publication->head('site.pages', 'due-1', 'site:main', 'en');
larena_storage_role_assert($due1 !== null && $due1->state === PublicationStateValue::Published);
larena_storage_role_assert($due1->publishedRevision === 3, 'the scheduled revision is what got published');
larena_storage_role_assert($due1->scheduledAt === null, 'the schedule is cleared once it fires');

$due2 = $publication->head('site.pages', 'due-2', 'site:main', 'ru');
larena_storage_role_assert($due2->publishedRevision === 1);

$later = $publication->head('site.pages', 'later', 'site:main', 'en');
larena_storage_role_assert($later->state === PublicationStateValue::Scheduled, 'the future schedule is untouched');
larena_storage_role_assert($later->publishedRevision === null);

// The log distinguishes a sweep from an editor's publish: same outcome, different
// accountability.
$history = $publication->history('site.pages', 'due-1', 'site:main', 'en');
larena_storage_role_assert(count($history) === 2);
larena_storage_role_assert($history[1]->transition->value === 'sweep_publish');
larena_storage_role_assert($history[1]->actorId === 'actor:scheduler');
larena_storage_role_assert($history[1]->fromState === PublicationStateValue::Scheduled);
larena_storage_role_assert($history[1]->toState === PublicationStateValue::Published);

// The sweep is idempotent: running it again publishes nothing, because nothing is
// scheduled any more.
$second = $publication->sweep('actor:scheduler', '2026-09-23 12:00:00');
larena_storage_role_assert($second['published_count'] === 0, 'a second sweep is a no-op');
larena_storage_role_assert(count($publication->history('site.pages', 'due-1', 'site:main', 'en')) === 2,
    'and it appends no log row');

// Time moving forward brings the future schedule due.
$third = $publication->sweep('actor:scheduler', '2099-06-01 00:00:00');
larena_storage_role_assert($third['published_count'] === 1);
larena_storage_role_assert($publication->head('site.pages', 'later', 'site:main', 'en')->publishedRevision === 2);

// The limit is honoured and bounded by the frozen maximum.
$publication->schedule('site.pages', 'batch-1', 'site:main', 'en', 1, '2020-01-01 00:00:00', 'actor:editor');
$publication->schedule('site.pages', 'batch-2', 'site:main', 'en', 1, '2020-01-02 00:00:00', 'actor:editor');
$limited = $publication->sweep('actor:scheduler', '2026-09-23 12:00:00', 1);
larena_storage_role_assert($limited['published_count'] === 1, 'the limit stops the batch');
larena_storage_role_assert($limited['limit'] === 1);

$rest = $publication->sweep('actor:scheduler', '2026-09-23 12:00:00');
larena_storage_role_assert($rest['published_count'] === 1, 'the rest fires on the next run');
larena_storage_role_assert($rest['limit'] === DatabasePublicationLifecycle::SWEEP_LIMIT);

$limitRefused = false;
try {
    $publication->sweep('actor:scheduler', null, 0);
} catch (\Larena\Storage\Exceptions\PublicationRejected $rejection) {
    $limitRefused = $rejection->reasonCode === 'invalid_limit';
}
larena_storage_role_assert($limitRefused);

// dueSchedules is the read a proposal uses: it answers what a sweep would publish
// and writes nothing, which is why the sweep proposal is a genuine dry run.
$publication->schedule('site.pages', 'dry-run', 'site:main', 'en', 9, '2020-01-01 00:00:00', 'actor:editor');
$due = $publication->dueSchedules('2026-09-23 12:00:00');
larena_storage_role_assert(count($due) === 1);
larena_storage_role_assert($due[0]->publicationId === 'site.pages|dry-run|site:main|en');
larena_storage_role_assert(
    $publication->head('site.pages', 'dry-run', 'site:main', 'en')->state === PublicationStateValue::Scheduled,
    'reading the due list published nothing',
);
$publication->sweep('actor:scheduler', '2026-09-23 12:00:00');
larena_storage_role_assert($publication->dueSchedules('2026-09-23 12:00:00') === [], 'and the sweep cleared it');

// A sweep with nothing due touches nothing, which is what makes it safe to call on a
// request boundary.
$empty = $publication->sweep('actor:scheduler', '2026-09-23 12:00:00');
larena_storage_role_assert($empty['published_count'] === 0);
larena_storage_role_assert($empty['published'] === []);

echo "Publication sweep passed.\n";
