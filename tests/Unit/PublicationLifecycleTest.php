<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/publication-schema.php';

use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;

$publication = new DatabasePublicationLifecycle(larena_storage_publication_connection());

// Nothing is published until something publishes it.
larena_storage_role_assert($publication->head('site.pages', 'node-1', 'site:main', 'en') === null);

$published = $publication->publish('site.pages', 'node-1', 'site:main', 'en', 3, 'actor:editor', 'correlation-1');

larena_storage_role_assert($published->publicationId === 'site.pages|node-1|site:main|en');
larena_storage_role_assert($published->state === PublicationStateValue::Published);
larena_storage_role_assert($published->publishedRevision === 3);
larena_storage_role_assert($published->previousPublishedRevision === null, 'nothing was replaced');
larena_storage_role_assert($published->isPublished());
larena_storage_role_assert($published->publishedAt !== null);

// The head reads back.
$head = $publication->head('site.pages', 'node-1', 'site:main', 'en');
larena_storage_role_assert($head !== null && $head->publishedRevision === 3);

// Publishing a newer revision replaces the head and remembers the old one.
$again = $publication->publish('site.pages', 'node-1', 'site:main', 'en', 4, 'actor:editor');
larena_storage_role_assert($again->publishedRevision === 4);
larena_storage_role_assert($again->previousPublishedRevision === 3, 'the replaced head is remembered');

// One locale published, another still a draft: the whole point of a per-locale head.
larena_storage_role_assert($publication->head('site.pages', 'node-1', 'site:main', 'ru') === null);
$russian = $publication->publish('site.pages', 'node-1', 'site:main', 'ru', 2, 'actor:translator');
larena_storage_role_assert($russian->publishedRevision === 2);
larena_storage_role_assert($publication->head('site.pages', 'node-1', 'site:main', 'en')->publishedRevision === 4,
    'publishing Russian did not touch English');

// And one scope published while another is not.
larena_storage_role_assert($publication->head('site.pages', 'node-1', 'site:second', 'en') === null);
$publication->publish('site.pages', 'node-1', 'site:second', 'en', 1, 'actor:editor');
larena_storage_role_assert($publication->head('site.pages', 'node-1', 'site:second', 'en')->publishedRevision === 1);
larena_storage_role_assert($publication->head('site.pages', 'node-1', 'site:main', 'en')->publishedRevision === 4);

// Unpublishing clears the head and reports what it withdrew.
$withdrawn = $publication->unpublish('site.pages', 'node-1', 'site:main', 'en', 'actor:editor');
larena_storage_role_assert($withdrawn->state === PublicationStateValue::Draft);
larena_storage_role_assert($withdrawn->publishedRevision === null, 'there is no head now');
larena_storage_role_assert($withdrawn->previousPublishedRevision === 4, 'and it says which head it took down');
larena_storage_role_assert(!$withdrawn->isPublished());

// Publishing again after an unpublish works, from the draft state.
$republished = $publication->publish('site.pages', 'node-1', 'site:main', 'en', 5, 'actor:editor');
larena_storage_role_assert($republished->publishedRevision === 5);

// Archiving clears the head and stamps the time.
$archived = $publication->archive('site.pages', 'node-1', 'site:main', 'en', 'actor:editor');
larena_storage_role_assert($archived->state === PublicationStateValue::Archived);
larena_storage_role_assert($archived->publishedRevision === null);
larena_storage_role_assert($archived->previousPublishedRevision === 5);
larena_storage_role_assert($archived->archivedAt !== null);

// A record can be brought back from the archive: archived is a state, not a grave.
$restored = $publication->publish('site.pages', 'node-1', 'site:main', 'en', 5, 'actor:editor');
larena_storage_role_assert($restored->state === PublicationStateValue::Published);
larena_storage_role_assert($restored->archivedAt === null);

// Explain reports the state and which transitions are legal from here.
$explained = $publication->explain('site.pages', 'node-1', 'site:main', 'en');
larena_storage_role_assert($explained['exists'] === true);
larena_storage_role_assert($explained['state'] === 'published');
larena_storage_role_assert($explained['published_revision'] === 5);
larena_storage_role_assert(in_array('unpublish', $explained['allowed_transitions'], true));
larena_storage_role_assert($explained['transition_count'] > 0);

$absent = $publication->explain('site.pages', 'node-9', 'site:main', 'en');
larena_storage_role_assert($absent['exists'] === false);
larena_storage_role_assert(!in_array('unpublish', $absent['allowed_transitions'], true),
    'a record that was never published cannot be unpublished');

echo "Publication lifecycle passed.\n";
