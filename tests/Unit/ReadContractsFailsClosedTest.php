<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/read-contract-schema.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;

/**
 * @param callable(): mixed $call
 */
function larena_storage_read_denied(callable $call, string $expectedReason): void
{
    try {
        $call();
    } catch (ReadContractRejected $rejection) {
        larena_storage_role_assert(
            $rejection->reasonCode === $expectedReason,
            'expected ' . $expectedReason . ', got ' . $rejection->reasonCode,
        );

        return;
    }

    throw new RuntimeException('Boundary "' . $expectedReason . '" did not fail closed.');
}

$connection = larena_storage_read_contract_connection();
$read = new DatabaseReadContracts($connection, new DatabaseLocalizedValues($connection));
$publication = new DatabasePublicationLifecycle($connection);

// A schema nobody has defined projects nothing at all rather than the whole document:
// a reader must never see a field because nobody said what it was.
larena_storage_write_record_version($connection, 'orphan', 1, ['slug' => 'orphan', 'secret' => 'leak me'], 'undeclared.schema');
$publication->publish('undeclared.schema', 'orphan', 'site:main', 'en', 1, 'actor:editor');

$page = $read->publishedProjection('undeclared.schema', 'site:main', 'en');
larena_storage_role_assert(count($page->records) === 1, 'the record is published');
larena_storage_role_assert($page->records[0]['values'] === [], 'but no field is public without a schema');
larena_storage_role_assert(!str_contains((string) json_encode($page->toArray()), 'leak me'));

// And a localized value of an undeclared schema stays out too. This is the hole the
// SQLite smoke found: the localized reader treats an empty field list as "every
// field", so the projection has to stop before asking it anything.
$localizedLeak = new DatabaseLocalizedValues($connection);
$localizedLeak->write('undeclared.schema', 'orphan', 1, 'ru', ['secret' => 'перевод утечки'], ['secret'], 'actor:test');
$afterLocalized = $read->publishedProjection('undeclared.schema', 'site:main', 'en');
larena_storage_role_assert($afterLocalized->records[0]['values'] === [], 'still nothing');
$publication->publish('undeclared.schema', 'orphan', 'site:main', 'ru', 1, 'actor:editor');
$russianLeak = $read->publishedProjection('undeclared.schema', 'site:main', 'ru');
larena_storage_role_assert($russianLeak->records[0]['values'] === [], 'no localized value either');
larena_storage_role_assert(
    !str_contains((string) json_encode($russianLeak->toArray()), 'утечки'),
    'a schema with no declared public field projects nothing at all, in any locale',
);
larena_storage_role_assert(
    $read->resolveKey('undeclared.schema', 'site:main', 'en', 'slug', 'orphan') === null,
    'and no key resolves, because no field is readable',
);

// A role reference with no active binding fails closed rather than guessing a schema.
larena_storage_read_denied(
    static fn (): mixed => $read->publishedProjection('site_node@v1', 'site:main', 'en'),
    'unknown_role_binding',
);
larena_storage_read_denied(
    static fn (): mixed => $read->resolveKey('site_node@v1', 'site:main', 'en', 'slug', 'home'),
    'unknown_role_binding',
);

// A malformed locale is refused before any query.
$localeRefused = 0;
foreach (['', 'E', 'en/us'] as $bad) {
    try {
        $read->publishedProjection('site.pages', 'site:main', $bad);
    } catch (\InvalidArgumentException) {
        ++$localeRefused;
    }
}
larena_storage_role_assert($localeRefused === 3);

// A budget must be positive.
larena_storage_read_denied(
    static fn (): mixed => $read->publishedProjection('site.pages', 'site:main', 'en', 0),
    'invalid_budget',
);
larena_storage_read_denied(
    static fn (): mixed => $read->publishedProjection('site.pages', 'site:main', 'en', -5),
    'invalid_budget',
);

// A projection reads nothing but the published head. A record with a newer unpublished
// revision still projects the published one.
larena_storage_define_site_node_schema($connection);
larena_storage_write_record_version($connection, 'home', 1, [
    'slug' => 'home', 'title' => 'First', 'order_index' => 0, 'target' => '/',
]);
larena_storage_write_record_version($connection, 'home', 2, [
    'slug' => 'home', 'title' => 'Second, not published', 'order_index' => 0, 'target' => '/',
]);
$publication->publish('site.pages', 'home', 'site:main', 'en', 1, 'actor:editor');

$projected = $read->publishedProjection('site.pages', 'site:main', 'en');
larena_storage_role_assert($projected->records[0]['revision'] === 1);
larena_storage_role_assert($projected->records[0]['values']['title'] === 'First');
larena_storage_role_assert(
    !str_contains((string) json_encode($projected->toArray()), 'not published'),
    'an unpublished revision never reaches a reader',
);

// Without the tables nothing pretends to work.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$bare = new DatabaseReadContracts($capsule->getConnection());
larena_storage_read_denied(
    static fn (): mixed => $bare->publishedProjection('site.pages', 'site:main', 'en'),
    'schema_missing',
);
larena_storage_read_denied(
    static fn (): mixed => $bare->resolveKey('site.pages', 'site:main', 'en', 'slug', 'home'),
    'schema_missing',
);
larena_storage_read_denied(
    static fn (): mixed => $bare->projectionExplain('site.pages', 'site:main', 'en'),
    'schema_missing',
);

// Without a localized value reader bound, the shared values still project: the locale
// layer is an override, not a requirement.
$withoutLocales = new DatabaseReadContracts($connection);
$shared = $withoutLocales->publishedProjection('site.pages', 'site:main', 'en');
larena_storage_role_assert($shared->records[0]['values']['title'] === 'First');

echo "Read contract fail-closed boundaries passed.\n";
