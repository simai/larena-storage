<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/localized-value-schema.php';

use Larena\Storage\Runtime\DatabaseLocalizedValues;

$connection = larena_storage_localized_connection();
$values = new DatabaseLocalizedValues($connection);
$fields = larena_storage_localized_fields();

// A write returns the field keys it wrote, and a read gives them back for that
// locale marked exact.
$written = $values->write(
    'site.pages',
    'node-1',
    1,
    'en',
    ['title' => 'Home', 'description' => 'The front page'],
    $fields,
    'actor:editor',
    ['title'],
    false,
    'correlation-1',
);

larena_storage_role_assert($written === ['title', 'description'], 'the write reports what it wrote');

$read = $values->read('site.pages', 'node-1', 1, 'en');
larena_storage_role_assert(array_keys($read) === ['description', 'title'], 'a read is ordered by field key');
larena_storage_role_assert($read['title']->value === 'Home');
larena_storage_role_assert($read['title']->exact === true, 'a direct read is always exact');
larena_storage_role_assert($read['title']->sourceLocale === 'en');
larena_storage_role_assert($read['title']->toArray()['resolution'] === 'exact');

// Another locale is another set of rows on the same revision.
$values->write('site.pages', 'node-1', 1, 'ru', ['title' => 'Главная'], $fields, 'actor:editor', ['title']);

$russian = $values->read('site.pages', 'node-1', 1, 'ru');
larena_storage_role_assert(array_keys($russian) === ['title'], 'only what was written for that locale');
larena_storage_role_assert($russian['title']->value === 'Главная');

// The English rows are untouched by the Russian write.
larena_storage_role_assert(count($values->read('site.pages', 'node-1', 1, 'en')) === 2);

// A different revision is a different set of rows, so the old translation stays
// readable — which is what makes a published revision safe.
$values->write('site.pages', 'node-1', 2, 'en', ['title' => 'Home v2'], $fields, 'actor:editor', ['title']);
larena_storage_role_assert($values->read('site.pages', 'node-1', 1, 'en')['title']->value === 'Home');
larena_storage_role_assert($values->read('site.pages', 'node-1', 2, 'en')['title']->value === 'Home v2');

// A structured value survives the round trip: the column is JSON, not text.
$values->write('site.pages', 'node-2', 1, 'en', ['title' => ['main' => 'Hi', 'sub' => ['a', 'b']]], $fields, 'actor:editor', ['title']);
$structured = $values->read('site.pages', 'node-2', 1, 'en')['title']->value;
larena_storage_role_assert($structured === ['main' => 'Hi', 'sub' => ['a', 'b']], 'a structured value round trips');

// An empty string and a null are values, not absences.
$values->write('site.pages', 'node-3', 1, 'en', ['title' => '', 'description' => null], $fields, 'actor:editor', ['title']);
$edge = $values->read('site.pages', 'node-3', 1, 'en');
larena_storage_role_assert(array_key_exists('title', $edge) && $edge['title']->value === '');
larena_storage_role_assert(array_key_exists('description', $edge) && $edge['description']->value === null);

// A locale with nothing written is an empty read, not an error.
larena_storage_role_assert($values->read('site.pages', 'node-1', 1, 'de') === []);

// Explain reports which locales this revision has, without the values.
$explained = $values->explain('site.pages', 'node-1', 1);
larena_storage_role_assert($explained['stored_locales'] === ['en', 'ru']);
larena_storage_role_assert($explained['value_count'] === 3);
larena_storage_role_assert($explained['immutable'] === true);
$rendered = json_encode($explained);
larena_storage_role_assert(is_string($rendered) && !str_contains($rendered, 'Home'), 'explain carries no values');

// Every row records its content hash, which is what lets a later batch detect a
// changed translation without comparing documents.
$hash = $connection->table(DatabaseLocalizedValues::TABLE)
    ->where('record_id', 'node-1')->where('locale', 'en')->where('field_key', 'title')->value('content_hash');
larena_storage_role_assert($hash === hash('sha256', '"Home"'), 'the hash covers the encoded value');

echo "Localized values passed.\n";
