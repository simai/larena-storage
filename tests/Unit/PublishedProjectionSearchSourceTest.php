<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/read-contract-schema.php';

use Larena\Storage\Contracts\PublicationObserver;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;

/**
 * What a derived index (Search) needs from the published projection: a keyset
 * cursor, one record by id, a projection version that only grows, and a signal on
 * every publication change.
 */
$connection = larena_storage_read_contract_connection();
larena_storage_define_site_node_schema($connection);
foreach (['a', 'b', 'c'] as $id) {
    larena_storage_write_record_version($connection, $id, 1, ['slug' => $id, 'title' => strtoupper($id), 'order_index' => 0, 'target' => '/' . $id]);
}

$observer = new class implements PublicationObserver {
    /** @var list<array{string, string, int}> */
    public array $heard = [];

    public function publicationChanged(PublicationState $state, int $projectionVersion): void
    {
        $this->heard[] = [$state->recordId, $state->state->value, $projectionVersion];
    }
};
$localized = new DatabaseLocalizedValues($connection);
$publication = new DatabasePublicationLifecycle($connection, null, $observer);
$read = new DatabaseReadContracts($connection, $localized);
foreach (['a', 'b', 'c'] as $id) {
    $publication->publish('site.pages', $id, 'site:main', 'en', 1, 'actor:editor');
}

// The keyset cursor walks the projection in record id order.
$first = $read->publishedProjection('site.pages', 'site:main', 'en', 2);
larena_storage_role_assert(array_column($first->records, 'record_id') === ['a', 'b'] && $first->truncated);
$rest = $read->publishedProjection('site.pages', 'site:main', 'en', 2, null, 'b');
larena_storage_role_assert(array_column($rest->records, 'record_id') === ['c'] && !$rest->truncated);

// One record by id, in the same shape, and null where it is not published.
$b = $read->publishedRecord('site.pages', 'site:main', 'en', 'b');
larena_storage_role_assert($b !== null && $b['values']['title'] === 'B' && $b === $first->records[1]);
larena_storage_role_assert($read->publishedRecord('site.pages', 'site:main', 'ru', 'b') === null);

// The observer heard every publication with the version the read reports.
larena_storage_role_assert(count($observer->heard) === 3 && $observer->heard[1][0] === 'b' && $observer->heard[1][2] === $b['projection_version']);

// A translation for the published revision moves the version up.
$publication->publish('site.pages', 'b', 'site:main', 'ru', 1, 'actor:editor');
$beforeTranslation = $read->publishedRecord('site.pages', 'site:main', 'ru', 'b')['projection_version'];
$localized->write('site.pages', 'b', 1, 'ru', ['title' => 'Б'], ['title'], 'actor:translator');
$translated = $read->publishedRecord('site.pages', 'site:main', 'ru', 'b');
larena_storage_role_assert($translated["values"]["title"] === "Б" && $translated["projection_version"] > $beforeTranslation);

// Publishing a newer revision without translations still moves it up.
larena_storage_write_record_version($connection, 'b', 2, ['slug' => 'b', 'title' => 'B2', 'order_index' => 0, 'target' => '/b']);
$publication->publish('site.pages', 'b', 'site:main', 'ru', 2, 'actor:editor');
larena_storage_role_assert($read->publishedRecord('site.pages', 'site:main', 'ru', 'b')['projection_version'] > $translated['projection_version']);

// Unpublishing and publishing the same revision again each move it up, so a removal
// never outlives the republication in an index fenced by the version.
$published = $read->publishedRecord('site.pages', 'site:main', 'en', 'a')['projection_version'];
$publication->unpublish('site.pages', 'a', 'site:main', 'en', 'actor:editor');
$heard = $observer->heard;
$removal = end($heard);
larena_storage_role_assert($removal[0] === 'a' && $removal[1] === 'draft' && $removal[2] > $published);
larena_storage_role_assert($read->publishedRecord('site.pages', 'site:main', 'en', 'a') === null);
$publication->publish('site.pages', 'a', 'site:main', 'en', 1, 'actor:editor');
larena_storage_role_assert($read->publishedRecord('site.pages', 'site:main', 'en', 'a')['projection_version'] > $removal[2]);

// An observer that fails never blocks the publication.
$failing = new DatabasePublicationLifecycle($connection, null, new class implements PublicationObserver {
    public function publicationChanged(PublicationState $state, int $projectionVersion): void
    {
        throw new RuntimeException('index down');
    }
});
$failing->publish('site.pages', 'c', 'site:main', 'ru', 1, 'actor:editor');
larena_storage_role_assert($read->publishedRecord('site.pages', 'site:main', 'ru', 'c') !== null);

echo "Published projection search source passed.\n";
