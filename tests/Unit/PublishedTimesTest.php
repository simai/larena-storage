<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/read-contract-schema.php';

use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;

// When each published record was published in a scope and locale: what a sitemap's
// lastmod is written from, beside the projection whose entries keep their shape.

$connection = larena_storage_read_contract_connection();
larena_storage_define_site_node_schema($connection);

$publication = new DatabasePublicationLifecycle($connection);
$read = new DatabaseReadContracts($connection, new DatabaseLocalizedValues($connection));

foreach (['home', 'about', 'draft', 'later', 'gone'] as $id) {
    larena_storage_write_record_version($connection, $id, 1, ['slug' => $id, 'title' => ucfirst($id)]);
}
larena_storage_write_record_version($connection, 'home', 2, ['slug' => 'home', 'title' => 'Home again']);

$setPublishedAt = static function (string $recordId, string $locale, string $at) use ($connection): void {
    $connection->table(DatabasePublicationLifecycle::STATES_TABLE)
        ->where('schema_id', 'site.pages')->where('record_id', $recordId)
        ->where('scope_ref', 'site:main')->where('locale', $locale)
        ->update(['published_at' => $at]);
};

$publication->publish('site.pages', 'home', 'site:main', 'en', 1, 'actor:editor');
$publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor');
$publication->publish('site.pages', 'about', 'site:main', 'ru', 1, 'actor:editor');
$publication->publish('site.pages', 'gone', 'site:main', 'en', 1, 'actor:editor');
$publication->unpublish('site.pages', 'gone', 'site:main', 'en', 'actor:editor');
$publication->schedule('site.pages', 'later', 'site:main', 'en', 1, '2099-01-01 00:00:00', 'actor:editor');
$setPublishedAt('home', 'en', '2026-01-02 03:04:05');
$setPublishedAt('about', 'en', '2026-02-03 04:05:06');
$setPublishedAt('about', 'ru', '2026-03-04 05:06:07');

$all = ['home', 'about', 'draft', 'later', 'gone', 'missing'];
$times = $read->publishedTimes('site.pages', 'site:main', 'en', $all);
ksort($times);
larena_storage_role_assert($times === [
    'about' => '2026-02-03T04:05:06+00:00',
    'home' => '2026-01-02T03:04:05+00:00',
], 'only published records, each at its ISO-8601 UTC publication time: ' . json_encode($times));
larena_storage_role_assert(
    $read->publishedTimes('site.pages', 'site:main', 'ru', $all) === ['about' => '2026-03-04T05:06:07+00:00'],
    'per locale',
);
larena_storage_role_assert($read->publishedTimes('site.pages', 'site:other', 'en', $all) === [], 'per scope');
larena_storage_role_assert($read->publishedTimes('site.pages', 'site:main', 'en', []) === [], 'no ids, no query');

// Every record the projection lists has a time.
$listed = array_column($read->publishedProjection('site.pages', 'site:main', 'en')->records, 'record_id');
larena_storage_role_assert(array_diff($listed, array_keys($times)) === [], 'every projected record has a publication time');

// A newer revision scheduled while the old one is live: the old head and its time stay
// until the sweep promotes the new one; then the time is the promotion's.
$publication->schedule('site.pages', 'home', 'site:main', 'en', 2, '2020-01-01 00:00:00', 'actor:editor');
larena_storage_role_assert(
    ($read->publishedTimes('site.pages', 'site:main', 'en', ['home'])['home'] ?? null) === '2026-01-02T03:04:05+00:00',
    'a pending schedule leaves the live head and its publication time alone',
);
$before = time();
$swept = $publication->sweep('actor:scheduler');
$after = time();
larena_storage_role_assert($swept['published_count'] === 1, 'the due schedule fires');
$promoted = $read->publishedTimes('site.pages', 'site:main', 'en', ['home'])['home'] ?? null;
larena_storage_role_assert(is_string($promoted) && str_ends_with($promoted, '+00:00'), 'the promoted head has a UTC time');
$promotedAt = (new DateTimeImmutable($promoted))->getTimestamp();
larena_storage_role_assert($promotedAt >= $before - 1 && $promotedAt <= $after + 1, 'the time is when the sweep promoted the newer revision: ' . $promoted);
larena_storage_role_assert($read->publishedRecord('site.pages', 'site:main', 'en', 'home')['revision'] === 2, 'and the promoted revision is the one projected');

// Many ids are read in batches and still answer for each.
$many = array_merge(array_map(static fn (int $n): string => 'x' . $n, range(1, DatabaseReadContracts::BATCH + 5)), ['about']);
larena_storage_role_assert(array_keys($read->publishedTimes('site.pages', 'site:main', 'en', $many)) === ['about'], 'ids past one batch are read too');

// It fails closed as the projection does.
$refused = false;
try {
    $read->publishedTimes('undeclared.role@v1', 'site:main', 'en', ['home']);
} catch (ReadContractRejected) {
    $refused = true;
}
larena_storage_role_assert($refused, 'a role bound to no structure is refused, as the projection refuses it');
$badLocale = false;
try {
    $read->publishedTimes('site.pages', 'site:main', 'EN!', ['home']);
} catch (Throwable) {
    $badLocale = true;
}
larena_storage_role_assert($badLocale, 'an invalid locale is refused');

echo "published times: ok\n";
