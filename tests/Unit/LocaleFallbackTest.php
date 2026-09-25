<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/localized-value-schema.php';

use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Runtime\DatabaseLocalizedValues;

$values = new DatabaseLocalizedValues(larena_storage_localized_connection());
$fields = ['title', 'description', 'footer'];

// English has everything; Russian has only the title; German has nothing.
$values->write('site.pages', 'node-1', 1, 'en', [
    'title' => 'Home',
    'description' => 'The front page',
    'footer' => 'Made here',
], $fields, 'actor:editor');
$values->write('site.pages', 'node-1', 1, 'ru', ['title' => 'Главная'], $fields, 'actor:editor');

// Russian first, English second: the title is exact, the rest fall back and each
// says where it came from.
$ru = $values->resolve('site.pages', 'node-1', 1, LocaleFallbackChain::of('ru', 'en'));

larena_storage_role_assert(array_keys($ru) === ['description', 'footer', 'title']);
larena_storage_role_assert($ru['title']->exact === true, 'the Russian title is exact');
larena_storage_role_assert($ru['title']->sourceLocale === 'ru');
larena_storage_role_assert($ru['title']->requestedLocale === 'ru');

larena_storage_role_assert($ru['description']->exact === false, 'the description falls back');
larena_storage_role_assert($ru['description']->sourceLocale === 'en', 'and says it came from English');
larena_storage_role_assert($ru['description']->requestedLocale === 'ru', 'while remembering what was asked for');
larena_storage_role_assert($ru['description']->value === 'The front page');
larena_storage_role_assert($ru['description']->toArray()['resolution'] === 'fallback');

// A locale with nothing of its own falls back for everything.
$de = $values->resolve('site.pages', 'node-1', 1, LocaleFallbackChain::of('de', 'ru', 'en'));
larena_storage_role_assert(count($de) === 3);
larena_storage_role_assert($de['title']->sourceLocale === 'ru', 'the chain is walked in order, so Russian wins over English');
larena_storage_role_assert($de['title']->exact === false);
larena_storage_role_assert($de['footer']->sourceLocale === 'en', 'and English catches what Russian lacks');

// Reversing the chain changes which locale wins, which is the whole point of an
// ordered chain rather than a set.
$reversed = $values->resolve('site.pages', 'node-1', 1, LocaleFallbackChain::of('de', 'en', 'ru'));
larena_storage_role_assert($reversed['title']->sourceLocale === 'en');

// A chain of one locale does not fall back.
$only = $values->resolve('site.pages', 'node-1', 1, LocaleFallbackChain::of('ru'));
larena_storage_role_assert(array_keys($only) === ['title'], 'no fallback means only what that locale has');
larena_storage_role_assert($only['title']->exact === true);

// A field with no row in any locale of the chain is absent, not null. A reader has
// to be able to tell "there is no German title" from "the German title is empty".
$values->write('site.pages', 'node-2', 1, 'en', ['title' => 'Only a title'], $fields, 'actor:editor');
$sparse = $values->resolve('site.pages', 'node-2', 1, LocaleFallbackChain::of('ru', 'en'));
larena_storage_role_assert(array_keys($sparse) === ['title']);
larena_storage_role_assert(!array_key_exists('description', $sparse), 'an absent field is absent, not null');

// And an empty value really is present, which is the other half of that contract.
$values->write('site.pages', 'node-3', 1, 'en', ['title' => ''], $fields, 'actor:editor');
$empty = $values->resolve('site.pages', 'node-3', 1, LocaleFallbackChain::of('en'));
larena_storage_role_assert(array_key_exists('title', $empty));
larena_storage_role_assert($empty['title']->value === '');

// Asking for a subset of fields returns only those.
$subset = $values->resolve('site.pages', 'node-1', 1, LocaleFallbackChain::of('ru', 'en'), ['title', 'footer']);
larena_storage_role_assert(array_keys($subset) === ['footer', 'title']);

// A malformed locale is refused before any query.
$rejected = 0;
foreach (['', 'E', 'english-language-code-far-too-long', 'en/us'] as $bad) {
    try {
        LocaleFallbackChain::of($bad);
    } catch (InvalidArgumentException) {
        ++$rejected;
    }
}
larena_storage_role_assert($rejected === 4, 'every malformed locale is refused');
larena_storage_role_assert(LocaleFallbackChain::of('en', 'en_GB', 'pt-BR')->requested() === 'en');

echo "Locale fallback resolution passed.\n";
