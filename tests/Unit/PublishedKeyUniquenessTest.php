<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/read-contract-schema.php';

use Larena\Storage\Contracts\PublishedKeyPolicy;
use Larena\Storage\Exceptions\PublicationRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\PublishedKeyUniqueness;
use Larena\Storage\Runtime\SlugUniquenessGuard;

$connection = larena_storage_read_contract_connection();
larena_storage_define_site_node_schema($connection);
$read = new DatabaseReadContracts($connection, new DatabaseLocalizedValues($connection));

// The site route declares slug as the key of site.pages in site:main only.
$keys = new class implements PublishedKeyPolicy {
    public function keyFieldFor(string $schemaId, string $scopeRef): ?string
    {
        return $schemaId === 'site.pages' && $scopeRef === 'site:main' ? 'slug' : null;
    }
};
$publication = new DatabasePublicationLifecycle($connection, null, null, null, new PublishedKeyUniqueness($read, $keys));
$page = static function (string $id, string $slug) use ($connection): void {
    larena_storage_write_record_version($connection, $id, 1, ['slug' => $slug, 'title' => ucfirst($id), 'order_index' => 0, 'target' => '/' . $slug]);
};
$refused = static function (callable $call, string $reason): void {
    try {
        $call();
    } catch (PublicationRejected $rejection) {
        larena_storage_role_assert($rejection->reasonCode === $reason, $rejection->reasonCode . ' instead of ' . $reason);

        return;
    }
    larena_storage_role_assert(false, 'expected ' . $reason);
};

$page('about', 'about');
$page('copy', 'about');
$publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor');

// A second published holder of the key is refused, and nothing is written.
$refused(static fn () => $publication->publish('site.pages', 'copy', 'site:main', 'en', 1, 'actor:editor'), 'key_conflict');
larena_storage_role_assert($publication->head('site.pages', 'copy', 'site:main', 'en') === null, 'a refused publish writes no head');

// Uniqueness is per scope and locale, and a record may publish its own key again.
$publication->publish('site.pages', 'copy', 'site:main', 'ru', 1, 'actor:editor');
$publication->publish('site.pages', 'copy', 'site:other', 'en', 1, 'actor:editor');
$publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor');

// Once the holder is withdrawn, the key is free.
$publication->unpublish('site.pages', 'about', 'site:main', 'en', 'actor:editor');
$publication->publish('site.pages', 'copy', 'site:main', 'en', 1, 'actor:editor');
$refused(static fn () => $publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor'), 'key_conflict');

// The sweep publishes what it may and leaves a conflicting schedule scheduled.
$page('contact', 'contact');
$publication->schedule('site.pages', 'about', 'site:main', 'en', 1, '2020-01-01 00:00:00', 'actor:editor');
$publication->schedule('site.pages', 'contact', 'site:main', 'en', 1, '2020-01-01 00:00:00', 'actor:editor');
$swept = $publication->sweep('system:scheduler', '2026-10-01 12:00:00');
larena_storage_role_assert($swept['published_count'] === 1, 'only the free schedule is published');
larena_storage_role_assert(count($swept['refused']) === 1 && $swept['refused'][0]['reason_code'] === 'key_conflict', 'the refused schedule is reported');
larena_storage_role_assert($publication->head('site.pages', 'about', 'site:main', 'en')?->state->value === 'scheduled', 'it stays scheduled');

// A key beyond the first page of published records is still found and still guarded.
$bulk = new DatabasePublicationLifecycle($connection);
$total = DatabaseReadContracts::DEFAULT_BUDGET + 40;
for ($i = 0; $i < $total; $i++) {
    $id = sprintf('n%04d', $i);
    $page($id, 'page-' . $i);
    $bulk->publish('site.pages', $id, 'site:wide', 'en', 1, 'actor:importer');
}
$last = $read->resolveKey('site.pages', 'site:wide', 'en', 'slug', 'page-' . ($total - 1));
larena_storage_role_assert($last?->recordId === sprintf('n%04d', $total - 1), 'a key on the second page resolves');
$guard = new SlugUniquenessGuard($read);
larena_storage_role_assert(!$guard->isAvailable('site.pages', 'site:wide', 'en', 'slug', 'page-' . ($total - 1)), 'the guard sees past the first page');
larena_storage_role_assert($guard->isAvailable('site.pages', 'site:wide', 'en', 'slug', 'page-unused'));

echo "Published key uniqueness passed.\n";
