<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/read-contract-schema.php';

use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;

$connection = larena_storage_read_contract_connection();
larena_storage_define_site_node_schema($connection);

$localized = new DatabaseLocalizedValues($connection);
$publication = new DatabasePublicationLifecycle($connection);
$read = new DatabaseReadContracts($connection, $localized);

// Three records, each with a field of every visibility.
foreach ([['home', 'Home', 0], ['about', 'About', 1], ['contact', 'Contact', 2]] as [$id, $title, $order]) {
    larena_storage_write_record_version($connection, $id, 1, [
        'slug' => $id,
        'title' => $title,
        'order_index' => $order,
        'target' => '/' . $id,
        'internal_note' => 'admin only',
        'draft_comment' => 'protected only',
        'secret_token' => 'encrypted only',
        'unmarked' => 'nobody said what this is',
    ]);
}

$publication->publish('site.pages', 'home', 'site:main', 'en', 1, 'actor:editor');
$publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor');
$publication->schedule('site.pages', 'contact', 'site:main', 'en', 1, '2099-01-01 00:00:00', 'actor:editor');

$page = $read->publishedProjection('site.pages', 'site:main', 'en');

larena_storage_role_assert(count($page->records) === 2, 'only the two published records project');
larena_storage_role_assert($page->filteredCount === 0);
larena_storage_role_assert(!$page->truncated);

$ids = array_column($page->records, 'record_id');
larena_storage_role_assert($ids === ['about', 'home'], 'ordered by record id: ' . implode(', ', $ids));
larena_storage_role_assert(!in_array('contact', $ids, true), 'a scheduled record is not published');

// Only public fields, and a field nobody marked is not public. A reader must never see
// a field because nobody said what it was.
$values = $page->records[0]['values'];
larena_storage_role_assert(array_keys($values) === ['order_index', 'slug', 'target', 'title'],
    'the projection carries exactly the public fields: ' . implode(', ', array_keys($values)));

$rendered = json_encode($page->toArray());
larena_storage_role_assert(is_string($rendered));
foreach (['admin only', 'protected only', 'encrypted only', 'nobody said what this is'] as $leak) {
    larena_storage_role_assert(!str_contains($rendered, $leak), 'the projection leaks no ' . $leak);
}

// A localized value replaces the shared one for its locale, and visibility still
// belongs to the field: a translated protected field stays out.
$publication->publish('site.pages', 'home', 'site:main', 'ru', 1, 'actor:translator');
$localized->write('site.pages', 'home', 1, 'ru', ['title' => 'Главная'], ['title', 'draft_comment'], 'actor:translator');
$localized->write('site.pages', 'home', 1, 'ru', ['draft_comment' => 'секрет'], ['title', 'draft_comment'], 'actor:translator');

$russian = $read->publishedProjection('site.pages', 'site:main', 'ru');
larena_storage_role_assert(count($russian->records) === 1);
larena_storage_role_assert($russian->records[0]['values']['title'] === 'Главная');
larena_storage_role_assert(!array_key_exists('draft_comment', $russian->records[0]['values']),
    'a translated protected field is still protected');
larena_storage_role_assert(!str_contains((string) json_encode($russian->toArray()), 'секрет'));

// An unpublished record leaves the projection.
$publication->unpublish('site.pages', 'about', 'site:main', 'en', 'actor:editor');
larena_storage_role_assert(count($read->publishedProjection('site.pages', 'site:main', 'en')->records) === 1);

// An archived record is not published either.
$publication->archive('site.pages', 'home', 'site:main', 'en', 'actor:editor');
larena_storage_role_assert($read->publishedProjection('site.pages', 'site:main', 'en')->records === []);

// A filtered record is a count, never an identifier.
$publication->publish('site.pages', 'home', 'site:main', 'en', 1, 'actor:editor');
$publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor');

$hidden = $read->publishedProjection(
    'site.pages',
    'site:main',
    'en',
    null,
    static fn (string $recordId): bool => $recordId !== 'about',
);

larena_storage_role_assert(count($hidden->records) === 1);
larena_storage_role_assert($hidden->records[0]['record_id'] === 'home');
larena_storage_role_assert($hidden->filteredCount === 1, 'the hidden record is counted');
larena_storage_role_assert(
    !str_contains((string) json_encode($hidden->toArray()), 'about'),
    'and never named: not the id, not the slug, not the title',
);

// The budget reports truncation rather than returning a silently short page.
$budgeted = $read->publishedProjection('site.pages', 'site:main', 'en', 1);
larena_storage_role_assert(count($budgeted->records) === 1);
larena_storage_role_assert($budgeted->truncated, 'the page says it was truncated');
larena_storage_role_assert($budgeted->budget === 1);

// Explain names the public fields and says the projection is derived.
$explained = $read->projectionExplain('site.pages', 'site:main', 'en');
larena_storage_role_assert($explained['public_field_keys'] === ['order_index', 'slug', 'target', 'title']);
larena_storage_role_assert($explained['published_record_count'] === 2);
larena_storage_role_assert($explained['derived'] === true);

echo "Published projection passed.\n";
