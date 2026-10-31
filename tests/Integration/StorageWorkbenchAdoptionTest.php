<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Contracts\RelationDescriptor;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Contracts\StorageSchemaVersionRef;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Enums\RelationDeletePolicy;
use Larena\Storage\Enums\RelationKind;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseRecordRelations;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\View\SqliteStorageViewDdl;
use Larena\Storage\View\StorageViewNaming;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-view-fixture.php';

// An existing storage schema adopted by the workbench in place (larena-specs
// docs/architecture/2026-10-29-sites-work-plan.md, step 1.2), on the site pages as the first run writes them:
// - the plan writes nothing; the adoption keeps the schema id, the record ids, owner refs, revisions, relations,
//   translations and publication states as they were, and is idempotent;
// - the records list from a витрина, read as active in the workbench scope, and the admin's direct writes still work;
// - a new record gets the slug owner ref; a role's required fields are locked; a published record is not archived.

$path = tempnam(sys_get_temp_dir(), 'larena-storage-adoption-');
if (!is_string($path)) {
    throw new RuntimeException('storage_adoption_tempfile_failed');
}
$container = new Container();
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal();
$connection = $capsule->getConnection();
$container->instance('db', $capsule->getDatabaseManager());
$container->instance('db.schema', $connection->getSchemaBuilder());
Facade::clearResolvedInstances();

/** @return array<string, list<array<string, mixed>>> Every row the adoption must leave alone. */
function storageAdoptionSnapshot(Connection $connection): array
{
    $snapshot = [];
    foreach ([
        'larena_storage_records' => 'record_id',
        'larena_storage_record_versions' => 'record_id',
        'larena_storage_record_relations' => 'relation_id',
        'larena_storage_localized_values' => 'record_id',
        'larena_storage_publication_states' => 'publication_id',
        'larena_storage_publication_log' => 'id',
        'larena_storage_structure_role_bindings' => 'binding_id',
    ] as $table => $order) {
        $snapshot[$table] = array_map(static fn (object $row): array => (array) $row,
            $connection->table($table)->orderBy($order)->get()->all());
    }

    return $snapshot;
}

try {
    $actor = 'actor:admin:alpha';
    $scope = 'scope:main';
    storageViewInstall($connection);
    $root = __DIR__ . '/../../database/migrations';
    foreach ([
        '/platform/2026_09_26_000001_create_larena_storage_structure_role_tables.php',
        '/platform/2026_09_27_000001_create_larena_storage_record_relations_table.php',
        '/platform/2026_09_28_000001_create_larena_storage_localized_values_table.php',
        '/platform/2026_09_29_000001_create_larena_storage_publication_tables.php',
        '/platform/2026_10_22_000002_add_larena_storage_publication_scheduled_revision.php',
        '/2026_10_23_000001_add_larena_storage_workbench_structure_import_run.php',
        '/2026_10_29_000001_add_larena_storage_workbench_structure_adoption.php',
    ] as $file) {
        (require $root . $file)->up();
    }
    $runtime = storageViewRuntime($connection, new SqliteStorageViewDdl($connection), $actor, $scope);
    $workbench = $runtime['workbench'];
    $storage = $runtime['storage'];
    $views = $runtime['views'];

    // The site pages as the first run writes them: StarterSite's schema and role binding, then two pages the admin
    // writes directly (owner ref site.pages:<slug>), a tree, a translation and a publication.
    $starter = (new ReflectionClass(StarterSite::class))->newInstanceWithoutConstructor();
    $definition = (new ReflectionMethod(StarterSite::class, 'schemaDefinition'))->invoke($starter);
    $roleFields = (new ReflectionMethod(StarterSite::class, 'roleFields'))->invoke($starter);
    $roles = new DatabaseStructureRoleRegistry($connection);
    (new StarterStructureRoles($roles))->install($actor);
    $schema = $storage->registerSchemaVersion($definition, null, $actor);
    $roles->bindStructure(StarterSite::ROLE_REF, StarterSite::SCHEMA_ID, 'site:main', $roleFields, $actor, ['site_tree_parent'], 'publishable');
    $page = static fn (string $slug, string $title, int $order) => $storage->create(StarterSite::SCHEMA_ID . ':' . $slug, $schema->ref,
        ['slug' => $slug, 'title' => $title, 'order_index' => $order, 'target' => '/' . $slug], $actor)->version;
    $home = $page('home', 'Home', 0);
    $docs = $page('docs', 'Documentation', 1);
    $tree = new RelationDescriptor(relationKey: 'site_tree_parent', kind: RelationKind::TreeParent, deletePolicy: RelationDeletePolicy::Cascade, targetRoleCode: 'site_node');
    (new DatabaseRecordRelations($connection))->define($tree, StarterSite::SCHEMA_ID, $docs->ref->recordId, $home->ref->recordId, $actor);
    (new DatabaseLocalizedValues($connection))->write(StarterSite::SCHEMA_ID, $home->ref->recordId, 1, 'ru', ['title' => 'Главная'], ['title', 'description'], $actor);
    $publication = new DatabasePublicationLifecycle($connection);
    foreach (['en', 'ru'] as $locale) {
        $publication->publish(StarterSite::SCHEMA_ID, $home->ref->recordId, 'site:main', $locale, 1, $actor);
    }
    $before = storageAdoptionSnapshot($connection);
    $schemaVersions = $connection->table('larena_storage_schema_versions')->where('schema_id', StarterSite::SCHEMA_ID)->count();

    // Refused: an owner ref prefix the pages do not have, a workbench storage, an unknown schema.
    storageViewRejects(fn () => $workbench->planAdoption($scope, 'workbench.site_pages', StarterSite::SCHEMA_ID, 'Site pages', 'pages:', $actor),
        'storage_workbench_adopt_owner_ref_mismatch');
    storageViewRejects(fn () => $workbench->planAdoption($scope, 'workbench.site_pages', 'workbench.anything', 'Site pages', 'site.pages:', $actor),
        'storage_workbench_adopt_schema_invalid');
    storageViewRejects(fn () => $workbench->planAdoption($scope, 'workbench.site_pages', 'site.unknown', 'Site pages', 'site.unknown:', $actor),
        'storage_workbench_adopt_schema_unknown');
    storageViewRejects(fn () => $workbench->planAdoption($scope, 'workbench.site_pages', StarterSite::SCHEMA_ID, 'Site pages', 'site.pages:', $actor, 'order_index'),
        'storage_workbench_adopt_owner_field_invalid');
    storageViewRejects(fn () => $workbench->planAdoption($scope, 'workbench.site_pages', StarterSite::SCHEMA_ID, 'Site pages', 'site.pages', $actor),
        'storage_workbench_adopt_owner_prefix_invalid');

    // 1. The plan: counts, the role and its locked fields, and no write.
    $plan = $workbench->planAdoption($scope, 'workbench.site_pages', StarterSite::SCHEMA_ID, 'Site pages', 'site.pages:', $actor, 'slug');
    storageViewExpect($plan['state'] === 'ready' && $plan['records'] === 2 && $plan['records_without_reserved_fields'] === 2
        && $plan['schema_version'] === 1 && $plan['target_schema_version'] === 2, 'the plan does not count the pages');
    storageViewExpect($plan['role_bindings'] === ['site_node@v1 site:main'] && $plan['locked_fields'] === ['order_index', 'slug', 'target', 'title'],
        'the plan does not name the role and its fields');
    storageViewExpect($plan['writes'] === ['schema_versions' => 1, 'structures' => 1, 'record_versions' => 0], 'the plan does not say what it writes');
    storageViewExpect($connection->table('larena_storage_workbench_structures')->count() === 0
        && $connection->table('larena_storage_schema_versions')->where('schema_id', StarterSite::SCHEMA_ID)->count() === $schemaVersions,
        'the plan wrote');

    // 2. The adoption: the same schema id, one more schema version, nothing keyed by a record touched.
    $adopted = $workbench->adoptSchema($scope, 'workbench.site_pages', StarterSite::SCHEMA_ID, 'Site pages', 'site.pages:', $actor, 'slug');
    $structure = $adopted['structure'];
    storageViewExpect($adopted['state'] === DatabaseStorageWorkbench::ADOPTED && $structure->schema->schemaId === StarterSite::SCHEMA_ID
        && $structure->schema->version === 2 && $structure->adopted() && $structure->recordOwnerPrefix === 'site.pages:', 'the schema was not adopted in place');
    storageViewExpect(storageAdoptionSnapshot($connection) == $before, 'the adoption changed records, relations, translations, publications or bindings');
    $head = $connection->table('larena_storage_workbench_structures')->first();
    storageViewExpect($head !== null && $head->storage_schema_id === StarterSite::SCHEMA_ID && $head->adopted_schema_id === StarterSite::SCHEMA_ID,
        'the structure row does not name the adopted schema');
    $reserved = array_values(array_filter($storage->schemaVersion(new StorageSchemaVersionRef(StarterSite::SCHEMA_ID, 2))->fields,
        static fn (array $field): bool => str_starts_with((string) $field['key'], 'larena_')));
    storageViewExpect(count($reserved) === 2 && $reserved[0]['required'] === false && $reserved[1]['required'] === false,
        'the reserved fields were not added, optional');

    // 3. Idempotent: the same call again writes nothing; so says the plan.
    $again = $workbench->adoptSchema($scope, 'workbench.site_pages', StarterSite::SCHEMA_ID, 'Site pages', 'site.pages:', $actor, 'slug');
    storageViewExpect($again['state'] === DatabaseStorageWorkbench::ALREADY_ADOPTED && $again['structure']->version === 1
        && $connection->table('larena_storage_schema_versions')->where('schema_id', StarterSite::SCHEMA_ID)->count() === $schemaVersions + 1,
        'a second adoption wrote');
    storageViewExpect($workbench->planAdoption($scope, 'workbench.site_pages', StarterSite::SCHEMA_ID, 'Site pages', 'site.pages:', $actor)['state'] === 'already_adopted',
        'the plan of an adopted schema is not already_adopted');
    storageViewRejects(fn () => $workbench->planAdoption($scope, 'workbench.other', StarterSite::SCHEMA_ID, 'Other', 'site.pages:', $actor),
        'storage_workbench_adopt_schema_taken');

    // 4. The pages read and list as ordinary records: active, in the workbench scope, from a ready витрина.
    $read = $workbench->readRecord($scope, 'workbench.site_pages', $home->ref->recordId, $actor);
    storageViewExpect($read->state === 'active' && $read->scopeRef === $scope && $read->revision === 1 && $read->values['slug'] === 'home',
        'an adopted page does not read as an active record');
    $list = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.site_pages', limit: 10), $actor);
    storageViewExpect(count($list->items) === 2, 'the adopted pages do not list');
    storageViewExpect($connection->getSchemaBuilder()->hasTable(StorageViewNaming::table(StarterSite::SCHEMA_ID)), 'the витрина table is missing');
    storageViewParity($connection, $views, StarterSite::SCHEMA_ID, 'the adopted витрина');

    // 5. The admin's direct write (owner ref site.pages:<slug>, no reserved values) still works and still reads.
    $docsWritten = $storage->compareAndSwap(StarterSite::SCHEMA_ID . ':docs', $docs->ref, new StorageSchemaVersionRef(StarterSite::SCHEMA_ID, 2),
        ['slug' => 'docs', 'title' => 'Docs', 'order_index' => 1, 'target' => '/docs'], $actor)->version;
    storageViewExpect($workbench->readRecord($scope, 'workbench.site_pages', $docs->ref->recordId, $actor)->values['title'] === 'Docs'
        && $docsWritten->ref->revision === 2, 'a direct write to the adopted schema does not read back');

    // 6. A workbench edit writes the reserved values with the next revision; the published revision stays.
    $edited = $workbench->updateRecord($scope, 'workbench.site_pages', $home->ref->recordId, 1,
        ['slug' => 'home', 'title' => 'Home page', 'order_index' => 0, 'target' => '/'], $actor);
    $homeVersion = $storage->readAdminVersion(new StorageRecordVersionRef(StarterSite::SCHEMA_ID, $home->ref->recordId, 2), $actor);
    storageViewExpect($edited->revision === 2 && ($homeVersion->values['larena_scope_ref'] ?? null) === $scope
        && ($homeVersion->values['larena_record_state'] ?? null) === 'active', 'the edit did not write the reserved values');
    storageViewExpect($publication->head(StarterSite::SCHEMA_ID, $home->ref->recordId, 'site:main', 'en')?->publishedRevision === 1,
        'the edit moved the published revision');

    // 7. A new page through the workbench: the slug owner ref; a slug that repeats (under another parent) gets a generated one.
    $about = $workbench->createRecord($scope, 'workbench.site_pages', ['slug' => 'about', 'title' => 'About', 'order_index' => 2, 'target' => '/about'], $actor);
    storageViewExpect($connection->table('larena_storage_records')->where('record_id', $about->recordId)->value('owner_ref') === 'site.pages:about'
        && $about->state === 'active', 'a new page did not get the slug owner ref');
    $again = $workbench->createRecord($scope, 'workbench.site_pages', ['slug' => 'about', 'title' => 'About 2', 'order_index' => 3, 'target' => '/about-2'], $actor);
    storageViewExpect(preg_match('/\Asite\.pages:[0-9a-f]{32}\z/', (string) $connection->table('larena_storage_records')->where('record_id', $again->recordId)->value('owner_ref')) === 1,
        'a page whose slug repeats did not get a generated owner ref');
    $odd = $workbench->createRecord($scope, 'workbench.site_pages', ['slug' => 'Odd Slug', 'title' => 'Odd', 'order_index' => 4, 'target' => '/odd'], $actor);
    storageViewExpect(preg_match('/\Asite\.pages:[0-9a-f]{32}\z/', (string) $connection->table('larena_storage_records')->where('record_id', $odd->recordId)->value('owner_ref')) === 1,
        'a slug that cannot end an owner ref did not get the random one');

    // 8. The role's required fields are locked: not removed, not retyped. Other fields still change.
    $fields = $workbench->readStructure($scope, 'workbench.site_pages', $actor)->fields;
    $without = static fn (string $key): array => array_values(array_filter($fields, static fn (array $field): bool => $field['key'] !== $key));
    $descriptor = static fn (array $fields): array => ['structure_id' => 'workbench.site_pages', 'label' => 'Site pages', 'fields' => $fields];
    storageViewRejects(fn () => $workbench->migrateStructure($scope, 'workbench.site_pages', 1, $descriptor($without('target')), $actor),
        'storage_role_field_locked:target');
    $retyped = array_map(static fn (array $field): array => $field['key'] === 'order_index'
        ? ['type' => 'string', 'type_version' => 2, 'constraints' => ['max_length' => 20]] + $field : $field, $fields);
    storageViewRejects(fn () => $workbench->migrateStructure($scope, 'workbench.site_pages', 1, $descriptor($retyped), $actor),
        'storage_role_field_locked:order_index');
    storageViewRejects(fn () => $workbench->checkFieldChange($scope, 'workbench.site_pages', $descriptor($without('slug')), $actor),
        'storage_role_field_locked:slug');
    $added = $workbench->updateStructure($scope, 'workbench.site_pages', 1, $descriptor([...$fields, [
        'key' => 'subtitle', 'label' => 'Subtitle', 'position' => 1000, 'type' => 'string', 'type_version' => 2,
        'required' => false, 'visibility' => 'public', 'constraints' => ['max_length' => 191], 'localized' => true,
    ]]), $actor);
    storageViewExpect($added->version === 2 && $added->schema->schemaId === StarterSite::SCHEMA_ID && $added->adopted(),
        'an optional field was not added to the adopted structure');
    $removed = $workbench->migrateStructure($scope, 'workbench.site_pages', 2, $descriptor($without('description')), $actor);
    storageViewExpect($removed->version === 3 && !in_array('description', array_column($removed->fields, 'key'), true),
        'a field the role does not require was not removed');

    // 9. A published page is not archived until it is unpublished in every locale.
    $current = $workbench->readRecord($scope, 'workbench.site_pages', $home->ref->recordId, $actor);
    storageViewRejects(fn () => $workbench->archiveRecord($scope, 'workbench.site_pages', $home->ref->recordId, $current->revision, $actor),
        'storage_workbench_record_published');
    storageViewRejects(fn () => $workbench->bulkArchive($scope, 'workbench.site_pages', [$home->ref->recordId => $current->revision], $actor),
        'storage_workbench_record_published');
    $publication->unpublish(StarterSite::SCHEMA_ID, $home->ref->recordId, 'site:main', 'en', $actor);
    storageViewRejects(fn () => $workbench->archiveRecord($scope, 'workbench.site_pages', $home->ref->recordId, $current->revision, $actor),
        'storage_workbench_record_published');
    $publication->unpublish(StarterSite::SCHEMA_ID, $home->ref->recordId, 'site:main', 'ru', $actor);
    $archived = $workbench->archiveRecord($scope, 'workbench.site_pages', $home->ref->recordId, $current->revision, $actor);
    storageViewExpect($archived->state === 'archived', 'an unpublished page was not archived');
    $docsCurrent = $workbench->readRecord($scope, 'workbench.site_pages', $docs->ref->recordId, $actor);
    storageViewExpect($workbench->archiveRecord($scope, 'workbench.site_pages', $docs->ref->recordId, $docsCurrent->revision, $actor)->state === 'archived',
        'a draft page was not archived');
    $active = $workbench->listRecords(new StorageWorkbenchRecordQuery($scope, 'workbench.site_pages', limit: 10), $actor);
    storageViewExpect(count($active->items) === 3, 'archived pages still list as active');
    storageViewExpect($connection->table('larena_storage_record_relations')->count() === count($before['larena_storage_record_relations']),
        'the tree changed');
} finally {
    Facade::clearResolvedInstances();
    foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
        @unlink($file);
    }
}

echo "StorageWorkbenchAdoptionTest passed.\n";
