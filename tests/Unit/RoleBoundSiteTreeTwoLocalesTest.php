<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/read-contract-schema.php';
require_once __DIR__ . '/../Support/record-relation-schema.php';

use Illuminate\Database\Schema\Blueprint;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\DatabaseRecordRelations;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;

/**
 * The acceptance proof for `role_bound_site_tree_two_locales`.
 *
 * A site tree of role-bound site_node records with localized values renders in two
 * locales **from one record set**. Not two record sets, not a translated copy of the
 * tree — one set of records, one tree, two renders.
 */
$connection = larena_storage_read_contract_connection();
larena_storage_define_site_node_schema($connection);

// The structure role tables and the relation table, so the whole chain is present.
$schema = $connection->getSchemaBuilder();
$schema->create('larena_storage_structure_roles', static function (Blueprint $table): void {
    $table->string('role_ref', 140)->primary();
    $table->string('role_code', 120);
    $table->unsignedInteger('role_version')->default(1);
    $table->string('title', 191);
    $table->json('required_fields');
    $table->json('optional_fields');
    $table->json('required_relations');
    $table->string('lifecycle', 24);
    $table->string('owner_package', 120);
    $table->string('status', 16)->default('active');
    $table->string('created_by', 191);
    $table->string('correlation_id', 191)->nullable();
    $table->timestamps();
    $table->unique(['role_code', 'role_version'], 'storage_roles_code_version_uq');
});
$schema->create('larena_storage_record_relations', static function (Blueprint $table): void {
    $table->string('relation_id', 190)->primary();
    $table->string('relation_key', 64);
    $table->string('schema_id', 120);
    $table->string('from_record_id', 39);
    $table->string('to_record_id', 39);
    $table->string('kind', 16);
    $table->string('tree_child_key', 39)->nullable();
    $table->string('path', 2048)->nullable();
    $table->unsignedSmallInteger('depth')->default(0);
    $table->integer('order_index')->default(0);
    $table->string('delete_policy', 16);
    $table->string('status', 16)->default('active');
    $table->string('created_by', 191);
    $table->string('correlation_id', 191)->nullable();
    $table->timestamps();
    $table->unique(['relation_key', 'from_record_id', 'to_record_id'], 'storage_relations_key_from_to_uq');
    $table->unique(['relation_key', 'tree_child_key'], 'storage_relations_key_child_uq');
});

$roles = new DatabaseStructureRoleRegistry($connection);
(new StarterStructureRoles($roles))->install('actor:installer');

// 1. The structure is bound to the site_node role, and it conforms.
$binding = $roles->bindStructure(
    'site_node@v1',
    'site.pages',
    'site:main',
    larena_storage_site_node_fields(),
    'actor:admin',
    ['site_tree_parent'],
    'publishable',
);
larena_storage_role_assert($binding->schemaId === 'site.pages', 'the structure plays the site_node role');

// 2. One record set: three nodes, each with one revision.
$nodes = [
    ['home', 'Home', 'home', 0, '/'],
    ['docs', 'Documentation', 'docs', 1, '/docs'],
    ['guide', 'Guide', 'guide', 0, '/docs/guide'],
];
foreach ($nodes as [$id, $title, $slug, $order, $target]) {
    larena_storage_write_record_version($connection, $id, 1, [
        'slug' => $slug,
        'title' => $title,
        'order_index' => $order,
        'target' => $target,
        'internal_note' => 'not for readers',
    ]);
}

// 3. One tree: guide under docs, docs under home.
$relations = new DatabaseRecordRelations($connection);
$tree = larena_storage_tree_descriptor();
$relations->define($tree, 'site.pages', 'docs', 'home', 'actor:admin');
$relations->define($tree, 'site.pages', 'guide', 'docs', 'actor:admin');

larena_storage_role_assert(
    $relations->explain('site_tree_parent', 'guide')['path'] === 'home/docs/guide',
    'the tree is one materialized path',
);

// 4. Russian titles for the same records and the same revisions.
$localized = new DatabaseLocalizedValues($connection);
foreach ([['home', 'Главная'], ['docs', 'Документация'], ['guide', 'Руководство']] as [$id, $title]) {
    $localized->write('site.pages', $id, 1, 'ru', ['title' => $title], ['title'], 'actor:translator');
}

// 5. Published in both locales, same revisions.
$publication = new DatabasePublicationLifecycle($connection);
foreach (['en', 'ru'] as $locale) {
    foreach (['home', 'docs', 'guide'] as $id) {
        $publication->publish('site.pages', $id, 'site:main', $locale, 1, 'actor:editor');
    }
}

// 6. Two renders from the one record set.
$read = new DatabaseReadContracts($connection, $localized);

$english = $read->publishedProjection('site_node@v1', 'site:main', 'en');
$russian = $read->publishedProjection('site_node@v1', 'site:main', 'ru');

larena_storage_role_assert(count($english->records) === 3, 'three nodes in English');
larena_storage_role_assert(count($russian->records) === 3, 'three nodes in Russian');

$titles = static fn (array $records): array => array_combine(
    array_column($records, 'record_id'),
    array_map(static fn (array $r): string => (string) $r['values']['title'], $records),
);

larena_storage_role_assert($titles($english->records) === [
    'docs' => 'Documentation',
    'guide' => 'Guide',
    'home' => 'Home',
], 'the English render');

larena_storage_role_assert($titles($russian->records) === [
    'docs' => 'Документация',
    'guide' => 'Руководство',
    'home' => 'Главная',
], 'the Russian render, from the same records');

// The record ids are identical in both renders: one record set, two locales.
larena_storage_role_assert(
    array_column($english->records, 'record_id') === array_column($russian->records, 'record_id'),
    'both locales render the same records',
);

// The revisions are identical too: a locale is not a revision.
larena_storage_role_assert(
    array_column($english->records, 'revision') === array_column($russian->records, 'revision'),
    'and the same revisions',
);

// Slugs and structure are shared, because they are not localized fields.
larena_storage_role_assert(
    array_column($english->records, 'values') !== array_column($russian->records, 'values'),
    'the renders differ',
);
foreach ($russian->records as $record) {
    larena_storage_role_assert(
        $record['values']['slug'] === $record['record_id'],
        'a non-localized field is shared by both locales',
    );
    larena_storage_role_assert(!array_key_exists('internal_note', $record['values']), 'and admin fields stay out');
}

// The tree is navigable in both locales from the same edges: the relation table has no
// locale, because a tree is structure and structure is not translated.
$children = $relations->children('site_tree_parent', 'docs');
larena_storage_role_assert(count($children->records) === 1);
larena_storage_role_assert($children->records[0]->fromRecordId === 'guide');

// resolveKey works per locale against the same slugs.
foreach (['en' => 'Guide', 'ru' => 'Руководство'] as $locale => $expected) {
    $resolved = $read->resolveKey('site_node@v1', 'site:main', $locale, 'slug', 'guide');
    larena_storage_role_assert($resolved !== null, 'the guide resolves in ' . $locale);
    larena_storage_role_assert($resolved->values['title'] === $expected);
    larena_storage_role_assert($resolved->recordId === 'guide', 'the same record in both locales');
}

// Coverage says the record set is complete in both declared locales for the localized
// field, which is what an editor needs before publishing a translation.
$coverage = $localized->coverage('site.pages', 'guide', 1, ['ru'], ['title']);
larena_storage_role_assert($coverage->complete(), 'the Russian title is there');

// A fallback still applies where a translation is missing, and it says so.
larena_storage_write_record_version($connection, 'news', 1, [
    'slug' => 'news', 'title' => 'News', 'order_index' => 2, 'target' => '/news',
]);
$publication->publish('site.pages', 'news', 'site:main', 'ru', 1, 'actor:editor');
$fallback = $localized->resolve('site.pages', 'news', 1, LocaleFallbackChain::of('ru', 'en'), ['title']);
larena_storage_role_assert($fallback === [], 'no localized row means the shared value is used');
larena_storage_role_assert(
    $read->resolveKey('site_node@v1', 'site:main', 'ru', 'slug', 'news')->values['title'] === 'News',
    'an untranslated node still renders, with the shared value',
);

echo "Role-bound site tree in two locales passed.\n";
