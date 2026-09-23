<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/read-contract-schema.php';

use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\SlugUniquenessGuard;

$connection = larena_storage_read_contract_connection();
larena_storage_define_site_node_schema($connection);

$publication = new DatabasePublicationLifecycle($connection);
$read = new DatabaseReadContracts($connection, new DatabaseLocalizedValues($connection));
$guard = new SlugUniquenessGuard($read);

larena_storage_write_record_version($connection, 'home', 1, [
    'slug' => 'about', 'title' => 'About', 'order_index' => 0, 'target' => '/about',
]);
$publication->publish('site.pages', 'home', 'site:main', 'en', 1, 'actor:editor');

// The slug is taken in this scope and locale.
larena_storage_role_assert(!$guard->isAvailable('site.pages', 'site:main', 'en', 'slug', 'about'));

$conflicted = false;
try {
    $guard->assertAvailable('site.pages', 'site:main', 'en', 'slug', 'about');
} catch (ReadContractRejected $rejection) {
    $conflicted = $rejection->reasonCode === 'slug_conflict';
    larena_storage_role_assert(str_contains($rejection->getMessage(), 'home'), 'the conflict names the record that holds it');
}
larena_storage_role_assert($conflicted);

// A different slug is free.
larena_storage_role_assert($guard->isAvailable('site.pages', 'site:main', 'en', 'slug', 'contact'));

// The uniqueness scope is structure, scope and locale — not the whole table. Two sites
// may both have a page at /about, and the same site may have a different about per
// locale.
larena_storage_role_assert($guard->isAvailable('site.pages', 'site:second', 'en', 'slug', 'about'),
    'another scope may reuse the slug');
larena_storage_role_assert($guard->isAvailable('site.pages', 'site:main', 'ru', 'slug', 'about'),
    'another locale may reuse the slug');

// A record is allowed to keep its own slug, which is what makes an update possible.
larena_storage_role_assert($guard->isAvailable('site.pages', 'site:main', 'en', 'slug', 'about', 'home'));
$guard->assertAvailable('site.pages', 'site:main', 'en', 'slug', 'about', 'home');

// And another record still cannot take it.
larena_storage_role_assert(!$guard->isAvailable('site.pages', 'site:main', 'en', 'slug', 'about', 'other'));

// Unpublishing frees the slug: uniqueness is about what is published, because that is
// what resolveKey reads.
$publication->unpublish('site.pages', 'home', 'site:main', 'en', 'actor:editor');
larena_storage_role_assert($guard->isAvailable('site.pages', 'site:main', 'en', 'slug', 'about'),
    'an unpublished record does not hold a slug');

// Publishing it again takes the slug back.
$publication->publish('site.pages', 'home', 'site:main', 'en', 1, 'actor:editor');
larena_storage_role_assert(!$guard->isAvailable('site.pages', 'site:main', 'en', 'slug', 'about'));

// Publishing a conflicting record is what the guard exists to prevent, and it is the
// caller's job to ask first — so the guard catches it after the fact too, which is how
// resolveKey's ambiguous_key and this reason code stay consistent.
larena_storage_write_record_version($connection, 'copy', 1, [
    'slug' => 'about', 'title' => 'Copy', 'order_index' => 1, 'target' => '/about-copy',
]);
$publication->publish('site.pages', 'copy', 'site:main', 'en', 1, 'actor:editor');
larena_storage_role_assert(!$guard->isAvailable('site.pages', 'site:main', 'en', 'slug', 'about', 'home'),
    'the guard sees the second holder even when the first is excluded');

echo "Slug uniqueness passed.\n";
