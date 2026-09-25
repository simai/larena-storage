<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/localized-value-schema.php';

use Larena\Storage\Runtime\DatabaseLocalizedValues;

$values = new DatabaseLocalizedValues(larena_storage_localized_connection());
$fields = ['title', 'description'];
$locales = ['en', 'ru', 'de'];

$values->write('site.pages', 'node-1', 1, 'en', ['title' => 'Home', 'description' => 'Front'], $fields, 'actor:editor');
$values->write('site.pages', 'node-1', 1, 'ru', ['title' => 'Главная'], $fields, 'actor:editor');

$report = $values->coverage('site.pages', 'node-1', 1, $locales, $fields);

larena_storage_role_assert($report->schemaId === 'site.pages');
larena_storage_role_assert($report->revision === 1);
larena_storage_role_assert($report->declaredLocales === $locales);
larena_storage_role_assert($report->localizedFields === $fields);
larena_storage_role_assert(!$report->complete(), 'two locales are unfinished, so the record is not complete');

$perLocale = $report->perLocale;

larena_storage_role_assert($perLocale['en']['complete'] === true, 'English has everything');
larena_storage_role_assert($perLocale['en']['missing'] === []);
larena_storage_role_assert($perLocale['en']['present'] === ['title', 'description']);

larena_storage_role_assert($perLocale['ru']['complete'] === false);
larena_storage_role_assert($perLocale['ru']['present'] === ['title']);
larena_storage_role_assert($perLocale['ru']['missing'] === ['description'], 'the gap is named, not just counted');

larena_storage_role_assert($perLocale['de']['present'] === [], 'German has nothing');
larena_storage_role_assert($perLocale['de']['missing'] === ['title', 'description']);
larena_storage_role_assert($perLocale['de']['complete'] === false);

// Completing every locale makes the record complete.
$values->write('site.pages', 'node-1', 1, 'ru', ['description' => 'Передняя'], $fields, 'actor:editor');
$values->write('site.pages', 'node-1', 1, 'de', ['title' => 'Start', 'description' => 'Vorne'], $fields, 'actor:editor');

$full = $values->coverage('site.pages', 'node-1', 1, $locales, $fields);
larena_storage_role_assert($full->complete(), 'every declared locale has every localized field now');
foreach ($full->perLocale as $locale) {
    larena_storage_role_assert($locale['missing'] === [], $locale['locale'] . ' has no gap');
}

// Coverage is per revision: a new revision starts empty even though the old one is
// complete, which is exactly what an editor needs to see before publishing it.
$next = $values->coverage('site.pages', 'node-1', 2, $locales, $fields);
larena_storage_role_assert(!$next->complete());
larena_storage_role_assert($next->perLocale['en']['present'] === []);

// A locale not declared by the site is not reported at all, even if rows exist for
// it: the declared set is the question being asked.
$values->write('site.pages', 'node-1', 1, 'fr', ['title' => 'Accueil'], $fields, 'actor:editor');
$declaredOnly = $values->coverage('site.pages', 'node-1', 1, ['en', 'ru'], $fields);
larena_storage_role_assert(array_keys($declaredOnly->perLocale) === ['en', 'ru']);

// A schema with no localized fields is trivially complete rather than an error.
$none = $values->coverage('site.pages', 'node-9', 1, $locales, []);
larena_storage_role_assert($none->complete(), 'nothing to translate is complete');

$array = $report->toArray();
larena_storage_role_assert($array['complete'] === false);
larena_storage_role_assert(count($array['per_locale']) === 3);
larena_storage_role_assert($array['per_locale'][0]['locale'] === 'en');
$rendered = json_encode($array);
larena_storage_role_assert(is_string($rendered) && !str_contains($rendered, 'Главная'), 'coverage carries no values');

echo "Locale coverage passed.\n";
