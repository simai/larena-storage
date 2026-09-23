<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/publication-schema.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Storage\Exceptions\PublicationRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;

/**
 * @param callable(): mixed $call
 */
function larena_storage_publication_denied(callable $call, string $expectedReason): void
{
    try {
        $call();
    } catch (PublicationRejected $rejection) {
        larena_storage_role_assert(
            $rejection->reasonCode === $expectedReason,
            'expected ' . $expectedReason . ', got ' . $rejection->reasonCode,
        );

        return;
    }

    throw new RuntimeException('Boundary "' . $expectedReason . '" did not fail closed.');
}

$connection = larena_storage_publication_with_locales_connection();

// A revision check is bound in the composed application; a head pointing at a
// revision that does not exist would be worse than no head, because a reader would
// ask for it and get nothing.
$known = [['site.pages', 'node-1', 1], ['site.pages', 'node-1', 2]];
$publication = new DatabasePublicationLifecycle(
    $connection,
    static fn (string $schemaId, string $recordId, int $revision): bool
        => in_array([$schemaId, $recordId, $revision], $known, true),
);

$publication->publish('site.pages', 'node-1', 'site:main', 'en', 1, 'actor:editor');

larena_storage_publication_denied(
    static fn (): mixed => $publication->publish('site.pages', 'node-1', 'site:main', 'en', 99, 'actor:editor'),
    'unknown_revision',
);
larena_storage_role_assert(
    $publication->head('site.pages', 'node-1', 'site:main', 'en')->publishedRevision === 1,
    'the refused publish left the head alone',
);

larena_storage_publication_denied(
    static fn (): mixed => $publication->publish('site.pages', 'node-1', 'site:main', 'en', 0, 'actor:editor'),
    'invalid_revision',
);
larena_storage_publication_denied(
    static fn (): mixed => $publication->schedule('site.pages', 'node-1', 'site:main', 'en', -1, '2099-01-01 00:00:00', 'actor:editor'),
    'invalid_revision',
);

// A malformed locale is refused before any write.
$localeRefused = 0;
foreach (['', 'E', 'en/us'] as $bad) {
    try {
        $publication->publish('site.pages', 'node-1', 'site:main', $bad, 1, 'actor:editor');
    } catch (\InvalidArgumentException) {
        ++$localeRefused;
    }
}
larena_storage_role_assert($localeRefused === 3);

// Publishing does not touch the revision or its localized values. This is the promise
// that makes an immutable revision worth having, so it is asserted directly.
$localized = new DatabaseLocalizedValues($connection);
$localized->write('site.pages', 'node-1', 1, 'en', ['title' => 'Home'], ['title'], 'actor:editor');
$before = $connection->table(DatabaseLocalizedValues::TABLE)->get()->map(static fn ($r): array => (array) $r)->all();

$publication->publish('site.pages', 'node-1', 'site:main', 'en', 2, 'actor:editor');
$publication->unpublish('site.pages', 'node-1', 'site:main', 'en', 'actor:editor');
$publication->archive('site.pages', 'node-1', 'site:main', 'en', 'actor:editor');

$after = $connection->table(DatabaseLocalizedValues::TABLE)->get()->map(static fn ($r): array => (array) $r)->all();
larena_storage_role_assert($before == $after, 'publishing changed no localized value');

// Without the tables nothing pretends to work.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$bare = new DatabasePublicationLifecycle($capsule->getConnection());
larena_storage_publication_denied(
    static fn (): mixed => $bare->head('site.pages', 'node-1', 'site:main', 'en'),
    'schema_missing',
);
larena_storage_publication_denied(
    static fn (): mixed => $bare->publish('site.pages', 'node-1', 'site:main', 'en', 1, 'actor:editor'),
    'schema_missing',
);
larena_storage_publication_denied(static fn (): mixed => $bare->sweep('actor:scheduler'), 'schema_missing');

// Without a revision check bound, the caller has taken responsibility — stated rather
// than pretended. A package test can then run without the record tables.
$unchecked = new DatabasePublicationLifecycle($connection);
$published = $unchecked->publish('site.pages', 'node-2', 'site:main', 'en', 42, 'actor:editor');
larena_storage_role_assert($published->publishedRevision === 42);

echo "Publication fail-closed boundaries passed.\n";
