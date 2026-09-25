<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/read-contract-schema.php';

use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;

$connection = larena_storage_read_contract_connection();
larena_storage_define_site_node_schema($connection);

$localized = new DatabaseLocalizedValues($connection);
$publication = new DatabasePublicationLifecycle($connection);
$read = new DatabaseReadContracts($connection, $localized);

larena_storage_write_record_version($connection, 'home', 1, [
    'slug' => 'home', 'title' => 'Home', 'order_index' => 0, 'target' => '/', 'internal_note' => 'do not ship',
]);
larena_storage_write_record_version($connection, 'about', 1, [
    'slug' => 'about', 'title' => 'About', 'order_index' => 1, 'target' => '/about',
]);

// Nothing is resolvable before publication: a draft is not a page.
larena_storage_role_assert(
    $read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'home') === null,
    'an unpublished record does not resolve',
);

$publication->publish('site.pages', 'home', 'site:main', 'en', 1, 'actor:editor');

$resolved = $read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'home');
larena_storage_role_assert($resolved !== null, 'a published record resolves');
larena_storage_role_assert($resolved->recordId === 'home');
larena_storage_role_assert($resolved->revision === 1);
larena_storage_role_assert($resolved->keyField === 'slug' && $resolved->keyValue === 'home');
larena_storage_role_assert($resolved->values['title'] === 'Home');
larena_storage_role_assert(!array_key_exists('internal_note', $resolved->values), 'an admin field never resolves');

// A key nobody has is a miss, not an error.
larena_storage_role_assert($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'nowhere') === null);

// Another scope and another locale are different questions.
larena_storage_role_assert($read->resolveKey('site.pages', 'site:second', 'en', 'slug', 'home') === null);
larena_storage_role_assert($read->resolveKey('site.pages', 'site:main', 'ru', 'slug', 'home') === null);

$publication->publish('site.pages', 'home', 'site:main', 'ru', 1, 'actor:translator');
$localized->write('site.pages', 'home', 1, 'ru', ['title' => 'Главная'], ['title'], 'actor:translator');

$russian = $read->resolveKey('site.pages', 'site:main', 'ru', 'slug', 'home');
larena_storage_role_assert($russian !== null);
larena_storage_role_assert($russian->values['title'] === 'Главная', 'the localized value wins for its locale');
larena_storage_role_assert(
    $read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'home')->values['title'] === 'Home',
    'and English is untouched',
);

// Unpublishing makes the key stop resolving immediately.
$publication->unpublish('site.pages', 'home', 'site:main', 'en', 'actor:editor');
larena_storage_role_assert($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'home') === null);

// A scheduled record does not resolve either: a schedule is not a publication.
$publication->schedule('site.pages', 'about', 'site:main', 'en', 1, '2099-01-01 00:00:00', 'actor:editor');
larena_storage_role_assert($read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about') === null);

// Two published records answering to the same key fail closed rather than returning
// the first: a site that served whichever row came back first would be broken in a way
// nobody could reproduce.
larena_storage_write_record_version($connection, 'duplicate', 1, [
    'slug' => 'about', 'title' => 'Also about', 'order_index' => 2, 'target' => '/about-2',
]);
$publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor');
$publication->publish('site.pages', 'duplicate', 'site:main', 'en', 1, 'actor:editor');

$ambiguous = false;
try {
    $read->resolveKey('site.pages', 'site:main', 'en', 'slug', 'about');
} catch (ReadContractRejected $rejection) {
    $ambiguous = $rejection->reasonCode === 'ambiguous_key';
    larena_storage_role_assert(str_contains($rejection->getMessage(), '2'), 'the refusal says how many candidates there are');
}
larena_storage_role_assert($ambiguous, 'two candidates fail closed');

// A record the caller may not read is a miss, indistinguishable from absence: the
// absence of a page must not confirm its existence.
$filtered = $read->resolveKey(
    'site.pages',
    'site:main',
    'ru',
    'slug',
    'home',
    static fn (string $recordId): bool => $recordId !== 'home',
);
larena_storage_role_assert($filtered === null, 'a filtered record resolves to nothing at all');

echo "Read contract resolve_key passed.\n";
