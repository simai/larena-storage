<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/localized-value-schema.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Exceptions\LocalizedValueRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;

/**
 * @param callable(): mixed $call
 */
function larena_storage_locale_denied(callable $call, string $expectedReason): void
{
    try {
        $call();
    } catch (LocalizedValueRejected $rejection) {
        larena_storage_role_assert(
            $rejection->reasonCode === $expectedReason,
            'expected ' . $expectedReason . ', got ' . $rejection->reasonCode,
        );

        return;
    }

    throw new RuntimeException('Boundary "' . $expectedReason . '" did not fail closed.');
}

$connection = larena_storage_localized_connection();
$values = new DatabaseLocalizedValues($connection);
$fields = ['title', 'description'];

$values->write('site.pages', 'node-1', 1, 'en', ['title' => 'Home'], $fields, 'actor:editor');

// A revision is immutable: the same field in the same locale cannot be rewritten.
larena_storage_locale_denied(
    static fn (): mixed => $values->write('site.pages', 'node-1', 1, 'en', ['title' => 'Changed'], $fields, 'actor:editor'),
    'revision_locale_field_immutable',
);
larena_storage_role_assert(
    $values->read('site.pages', 'node-1', 1, 'en')['title']->value === 'Home',
    'the refused write changed nothing',
);

// The unique index is the guarantee, not the check: a direct insert is refused too.
$directRefused = false;
try {
    $connection->table(DatabaseLocalizedValues::TABLE)->insert([
        'schema_id' => 'site.pages',
        'record_id' => 'node-1',
        'revision' => 1,
        'locale' => 'en',
        'field_key' => 'title',
        'value_json' => '"Sneaky"',
        'content_hash' => str_repeat('0', 64),
        'created_by' => 'actor:sneaky',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
} catch (\Throwable) {
    $directRefused = true;
}
larena_storage_role_assert($directRefused, 'immutability is enforced by the unique index');

// A field the schema does not declare localized is refused, and nothing is written.
larena_storage_locale_denied(
    static fn (): mixed => $values->write('site.pages', 'node-2', 1, 'en', ['slug' => 'home'], $fields, 'actor:editor'),
    'field_not_localized',
);
larena_storage_role_assert($values->read('site.pages', 'node-2', 1, 'en') === []);

// A required localized field missing in a locale is refused unless the schema allows
// partial locales. A half-translated record that can be published is worse than a
// refusal, because nothing downstream can tell.
larena_storage_locale_denied(
    static fn (): mixed => $values->write(
        'site.pages',
        'node-3',
        1,
        'ru',
        ['description' => 'Описание'],
        $fields,
        'actor:editor',
        ['title'],
        false,
    ),
    'partial_locale_denied',
);

// With partial locales allowed, the same write succeeds and coverage reports the gap
// rather than hiding it.
$written = $values->write(
    'site.pages',
    'node-3',
    1,
    'ru',
    ['description' => 'Описание'],
    $fields,
    'actor:editor',
    ['title'],
    true,
);
larena_storage_role_assert($written === ['description']);
larena_storage_role_assert(
    $values->coverage('site.pages', 'node-3', 1, ['ru'], $fields)->perLocale['ru']['missing'] === ['title'],
    'the permitted gap is still reported',
);

// An invalid revision and an invalid locale are refused before any query.
larena_storage_locale_denied(
    static fn (): mixed => $values->write('site.pages', 'node-4', 0, 'en', ['title' => 'x'], $fields, 'actor:editor'),
    'invalid_revision',
);

$localeRejected = 0;
foreach (['', 'zz-', 'x'] as $bad) {
    try {
        $values->read('site.pages', 'node-1', 1, $bad);
    } catch (\InvalidArgumentException) {
        ++$localeRejected;
    }
}
larena_storage_role_assert($localeRejected === 3, 'every malformed locale is refused on read');

// Without the table nothing pretends to work.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$bare = new DatabaseLocalizedValues($capsule->getConnection());
larena_storage_locale_denied(static fn (): mixed => $bare->read('site.pages', 'node-1', 1, 'en'), 'schema_missing');
larena_storage_locale_denied(
    static fn (): mixed => $bare->resolve('site.pages', 'node-1', 1, LocaleFallbackChain::of('en')),
    'schema_missing',
);
larena_storage_locale_denied(
    static fn (): mixed => $bare->coverage('site.pages', 'node-1', 1, ['en'], $fields),
    'schema_missing',
);

echo "Localized value fail-closed boundaries passed.\n";
