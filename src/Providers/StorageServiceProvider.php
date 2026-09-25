<?php

declare(strict_types=1);

namespace Larena\Storage\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaEvolution as StorageSchemaEvolutionContract;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Contracts\StorageWorkbench as StorageWorkbenchContract;
use Larena\Storage\Contracts\VersionedStorage as VersionedStorageContract;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\ReadContracts;
use Larena\Storage\Contracts\RecordRelations;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\BlockDocuments\Access\AccessBlockDocumentAuthorization;
use Larena\Storage\BlockDocuments\BlockDocumentAuthorization;
use Larena\Storage\BlockDocuments\BlockDocumentFileInspector;
use Larena\Storage\BlockDocuments\BlockDocumentService;
use Larena\Storage\BlockDocuments\StorageBackedBlockDocumentService;
use Larena\Storage\BlockDocuments\UnavailableBlockDocumentFileInspector;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\Starter\ScopeBaselineInstaller;
use Larena\Storage\FirstRun\SiteFirstRunContributor;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Registry\StorageOperationProvider;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\DatabaseRecordRelations;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\LocaleOperationHandlers;
use Larena\Storage\Runtime\PublicationOperationHandlers;
use Larena\Storage\Runtime\ReadContractOperationHandlers;
use Larena\Storage\Runtime\RecordOperationHandlers;
use Larena\Storage\Runtime\DatabaseAdminRecordTreeReader;
use Larena\Storage\Contracts\AdminRecordTreeReader;
use Larena\Core\Runtime\OperationHandlerCatalog;
use Larena\Core\Contracts\OperationHandler;
use Larena\Storage\Runtime\SlugUniquenessGuard;
use Larena\Storage\Runtime\RelationOperationHandlers;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\Runtime\StructureRoleOperationHandlers;
use Larena\Storage\Runtime\NullStorageSecurityEventSink;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;

final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->app->bound(StorageSecurityEventSink::class)) {
            $this->app->singleton(StorageSecurityEventSink::class, NullStorageSecurityEventSink::class);
        }
        $this->app->singleton(DatabaseStructureRoleRegistry::class, static function (Application $app): DatabaseStructureRoleRegistry {
            return new DatabaseStructureRoleRegistry(
                $app->make(DatabaseManager::class)->connection(),
                $app->bound(ScopeRefResolver::class) ? $app->make(ScopeRefResolver::class) : null,
            );
        });
        $this->app->alias(DatabaseStructureRoleRegistry::class, StructureRoleRegistry::class);

        $this->app->singleton(StarterStructureRoles::class, static function (Application $app): StarterStructureRoles {
            return new StarterStructureRoles($app->make(StructureRoleRegistry::class));
        });

        $this->app->singleton(StructureRoleOperationHandlers::class, static function (Application $app): StructureRoleOperationHandlers {
            return new StructureRoleOperationHandlers($app->make(StructureRoleRegistry::class));
        });

        $this->app->singleton(DatabaseRecordRelations::class, static function (Application $app): DatabaseRecordRelations {
            return new DatabaseRecordRelations($app->make(DatabaseManager::class)->connection());
        });
        $this->app->alias(DatabaseRecordRelations::class, RecordRelations::class);

        $this->app->singleton(RelationOperationHandlers::class, static function (Application $app): RelationOperationHandlers {
            return new RelationOperationHandlers($app->make(RecordRelations::class));
        });

        $this->app->singleton(DatabaseLocalizedValues::class, static function (Application $app): DatabaseLocalizedValues {
            return new DatabaseLocalizedValues($app->make(DatabaseManager::class)->connection());
        });
        $this->app->alias(DatabaseLocalizedValues::class, LocalizedValues::class);

        $this->app->singleton(LocaleOperationHandlers::class, static function (Application $app): LocaleOperationHandlers {
            return new LocaleOperationHandlers($app->make(LocalizedValues::class));
        });

        $this->app->singleton(DatabasePublicationLifecycle::class, static function (Application $app): DatabasePublicationLifecycle {
            $connection = $app->make(DatabaseManager::class)->connection();

            // The composed application can check that a revision really belongs to the
            // record, so it does: a head pointing at a revision nobody can read would
            // be worse than no head at all.
            return new DatabasePublicationLifecycle(
                $connection,
                static fn (string $schemaId, string $recordId, int $revision): bool => $connection
                    ->table('larena_storage_record_versions')
                    ->where('schema_id', $schemaId)
                    ->where('record_id', $recordId)
                    ->where('revision', $revision)
                    ->exists(),
            );
        });
        $this->app->alias(DatabasePublicationLifecycle::class, PublicationLifecycle::class);

        $this->app->singleton(PublicationOperationHandlers::class, static function (Application $app): PublicationOperationHandlers {
            return new PublicationOperationHandlers($app->make(PublicationLifecycle::class));
        });

        $this->app->singleton(DatabaseReadContracts::class, static function (Application $app): DatabaseReadContracts {
            return new DatabaseReadContracts(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(LocalizedValues::class),
            );
        });
        $this->app->alias(DatabaseReadContracts::class, ReadContracts::class);

        // Block documents moved here from larena/content; they always lived in
        // Storage's tables. The two ports have defaults so Storage boots alone:
        // authorization goes through Access, and without a file owner composed
        // every file is unavailable, which fails an image block closed.
        $this->app->bindIf(BlockDocumentFileInspector::class, UnavailableBlockDocumentFileInspector::class);
        $this->app->bindIf(BlockDocumentAuthorization::class, static fn (Application $app): BlockDocumentAuthorization => new AccessBlockDocumentAuthorization(
            $app->make(ActorOperationAuthorizer::class),
            $app->make(QueryScopeProvider::class),
        ));
        // The starter site is Storage's now: the third first-run step, after the
        // administrator and the site settings.
        $this->app->singleton(StarterSite::class, static fn (Application $app): StarterSite => new StarterSite(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(VersionedStorageContract::class),
            $app->make(StructureRoleRegistry::class),
            $app->make(StarterStructureRoles::class),
            $app->make(ScopeBaselineInstaller::class),
        ));
        $this->app->singleton(SiteFirstRunContributor::class, static fn (Application $app): SiteFirstRunContributor => new SiteFirstRunContributor(
            $app->make(StarterSite::class),
        ));
        $this->app->tag(SiteFirstRunContributor::class, FirstRunContributor::class);

        $this->app->bindIf(BlockDocumentService::class, static fn (Application $app): BlockDocumentService => new StorageBackedBlockDocumentService(
            $app->make(VersionedStorageContract::class),
            $app->make(BlockDocumentAuthorization::class),
            $app->make(BlockDocumentFileInspector::class),
        ));

        $this->app->singleton(SlugUniquenessGuard::class, static function (Application $app): SlugUniquenessGuard {
            return new SlugUniquenessGuard($app->make(DatabaseReadContracts::class));
        });

        $this->app->bind(AdminRecordTreeReader::class, static fn (Application $app): AdminRecordTreeReader => new DatabaseAdminRecordTreeReader(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(ActorOperationAuthorizer::class),
        ));
        $this->app->singleton(RecordOperationHandlers::class, static fn (Application $app): RecordOperationHandlers => new RecordOperationHandlers(
            $app->make(VersionedStorageContract::class),
        ));

        // Storage serves its own handler references when an operation runs
        // inside the application.
        $this->app->extend(
            OperationHandlerCatalog::class,
            static function (OperationHandlerCatalog $catalog, Application $app): OperationHandlerCatalog {
                foreach ([
                    'storage.handler.structure_role' => StructureRoleOperationHandlers::class,
                    'storage.handler.relation' => RelationOperationHandlers::class,
                    'storage.handler.locale' => LocaleOperationHandlers::class,
                    'storage.handler.publication' => PublicationOperationHandlers::class,
                    'storage.handler.read_contract' => ReadContractOperationHandlers::class,
                    'storage.handler.record' => RecordOperationHandlers::class,
                ] as $ref => $class) {
                    if (!$catalog->has($ref)) {
                        $catalog->register($ref, static fn (): OperationHandler => $app->make($class));
                    }
                }

                return $catalog;
            },
        );

        $this->app->singleton(ReadContractOperationHandlers::class, static function (Application $app): ReadContractOperationHandlers {
            return new ReadContractOperationHandlers($app->make(ReadContracts::class));
        });

        // The core registry is composed from a hard-coded provider list inside
        // larena/core, so a package cannot contribute by binding a tag. Extending
        // the resolved instance is the way in that does not require changing core,
        // and it keeps the single-registry guarantee: these operations land in the
        // same catalogue REST parity and the MCP projection read.
        $this->app->extend(
            DeclaredOperationRegistry::class,
            static function (DeclaredOperationRegistry $registry): DeclaredOperationRegistry {
                foreach ((new StorageOperationProvider())->operations() as $operation) {
                    if ($registry->has($operation['declaration']->name)) {
                        continue;
                    }

                    $registry->register($operation['declaration'], $operation['descriptor'], $operation['handler_ref']);
                }

                return $registry;
            },
        );

        $this->app->singleton(
            StorageSchemaEvolutionOwnerPolicyRegistry::class,
            static function (): StorageSchemaEvolutionOwnerPolicyRegistry {
                $registry = new StorageSchemaEvolutionOwnerPolicyRegistry();
                $registry->protect(
                    'larena/storage',
                    static function (StorageSchemaEvolutionOwnerContext $context, ?object $capability): void {
                        if (!$capability instanceof DatabaseStorageWorkbench
                            || !str_starts_with($context->source->schemaId, 'workbench.')
                            || !in_array($context->operation, ['plan', 'apply'], true)) {
                            throw new \InvalidArgumentException('storage_workbench_schema_evolution_capability_invalid');
                        }
                    },
                    'workbench.',
                );

                return $registry;
            },
        );
        $this->app->singleton(VersionedStorage::class, static function (Application $app): VersionedStorage {
            /** @var DatabaseManager $database */
            $database = $app->make(DatabaseManager::class);

            return new VersionedStorage(
                $database->connection(),
                $app->make(PropertyTypeRegistry::class),
                $app->make(ActorOperationAuthorizer::class),
                $app->make(StorageSecurityEventSink::class),
                $app->bound(QueryScopeProvider::class) ? $app->make(QueryScopeProvider::class) : null,
                is_string($app->make('config')->get('app.key'))
                    ? $app->make('config')->get('app.key')
                    : null,
            );
        });
        $this->app->alias(VersionedStorage::class, VersionedStorageContract::class);

        $this->app->singleton(DatabaseStorageSchemaEvolution::class, static function (Application $app): DatabaseStorageSchemaEvolution {
            /** @var DatabaseManager $database */
            $database = $app->make(DatabaseManager::class);

            return new DatabaseStorageSchemaEvolution(
                $database->connection(),
                $app->make(PropertyTypeRegistry::class),
                $app->make(ActorOperationAuthorizer::class),
                $app->make(StorageSecurityEventSink::class),
                $app->make(StorageSchemaEvolutionOwnerPolicyRegistry::class),
            );
        });
        $this->app->alias(DatabaseStorageSchemaEvolution::class, StorageSchemaEvolutionContract::class);

        $this->app->singleton(DatabaseStorageWorkbench::class, static function (Application $app): DatabaseStorageWorkbench {
            /** @var DatabaseManager $database */
            $database = $app->make(DatabaseManager::class);

            return new DatabaseStorageWorkbench(
                $database->connection(),
                $app->make(PropertyTypeRegistry::class),
                $app->make(ActorOperationAuthorizer::class),
                $app->make(QueryScopeProvider::class),
                $app->make(VersionedStorageContract::class),
                $app->make(StorageSchemaEvolutionContract::class),
                $app->make(StorageSchemaEvolutionOwnerPolicyRegistry::class),
                is_string($app->make('config')->get('app.key'))
                    ? $app->make('config')->get('app.key')
                    : '',
            );
        });
        $this->app->alias(DatabaseStorageWorkbench::class, StorageWorkbenchContract::class);

        $this->app->afterResolving(
            AccessOperationRegistry::class,
            static fn (AccessOperationRegistry $registry): bool => self::registerAccessOperations($registry),
        );
    }

    public function boot(): void
    {
        $this->app->make(StorageSchemaEvolutionOwnerPolicyRegistry::class)->seal();
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        // Platform schema that is not part of the guarded installer bootstrap
        // lives in its own registered path, exactly as larena/core does.
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/platform');

        if ($this->app->bound(AccessOperationRegistry::class)) {
            self::registerAccessOperations($this->app->make(AccessOperationRegistry::class));
        }
    }

    private static function registerAccessOperations(AccessOperationRegistry $registry): bool
    {
        $registered = false;
        foreach ([
            ['storage.schema.create', 'schema_create', 'create', 'critical'],
            ['storage.schema.version', 'schema_version', 'version', 'critical'],
            ['storage.schema_migration.diff', 'schema_migration_diff', 'diff', 'high'],
            ['storage.schema_migration.plan', 'schema_migration_plan', 'plan', 'critical'],
            ['storage.schema_migration.dispatch', 'schema_migration_dispatch', 'dispatch', 'critical'],
            ['storage.schema_migration.explain', 'schema_migration_explain', 'read', 'high'],
            ['storage.record.create', 'record_create', 'create', 'high'],
            ['storage.record.read', 'record_read', 'read', 'high'],
            ['storage.record.list', 'record_list', 'read', 'high'],
            ['storage.record.update', 'record_update', 'update', 'high'],
            ['storage.record.delete', 'record_delete', 'delete', 'critical'],
            ['storage.record.restore', 'record_restore', 'restore', 'critical'],
            ['storage.workbench.structure.create', 'workbench_structure_create', 'create', 'critical'],
            ['storage.workbench.structure.read', 'workbench_structure_read', 'read', 'high'],
            ['storage.workbench.structure.list', 'workbench_structure_list', 'read', 'high'],
            ['storage.workbench.structure.update', 'workbench_structure_update', 'update', 'critical'],
            ['storage.workbench.record.create', 'workbench_record_create', 'create', 'high'],
            ['storage.workbench.record.read', 'workbench_record_read', 'read', 'high'],
            ['storage.workbench.record.list', 'workbench_record_list', 'read', 'high'],
            ['storage.workbench.record.update', 'workbench_record_update', 'update', 'high'],
            ['storage.workbench.record.archive', 'workbench_record_archive', 'delete', 'critical'],
            ['storage.workbench.record.restore', 'workbench_record_restore', 'restore', 'critical'],
            ['storage.workbench.record.bulk_archive', 'workbench_record_bulk_archive', 'delete', 'critical'],
            ['storage.workbench.record.history', 'workbench_record_history', 'read', 'high'],
            // Block document codes. They keep the content.item spelling because
            // system role presets and every existing role grant name them;
            // renaming them would change an accepted access contract.
            ['content.item.list', 'block_document_list', 'list', 'high'],
            ['content.item.read', 'block_document_read', 'read', 'high'],
            ['content.item.create', 'block_document_create', 'create', 'high'],
            ['content.item.update', 'block_document_update', 'update', 'high'],
            // The codes the registry operations name (access.yaml). They were
            // declared but not registered, so every check on them denied.
            ['storage.role.manage', 'role_manage', 'manage', 'critical'],
            ['storage.role.read', 'role_read', 'read', 'high'],
            ['storage.relation.manage', 'relation_manage', 'manage', 'high'],
            ['storage.relation.read', 'relation_read', 'read', 'high'],
            ['storage.locale.read', 'locale_read', 'read', 'high'],
            ['storage.locale.write', 'locale_write', 'update', 'high'],
            ['storage.publication.publish', 'publication_publish', 'publish', 'high'],
            ['storage.publication.unpublish', 'publication_unpublish', 'unpublish', 'high'],
            ['storage.publication.schedule', 'publication_schedule', 'schedule', 'high'],
            ['storage.publication.archive', 'publication_archive', 'archive', 'critical'],
            ['storage.publication.read', 'publication_read', 'read', 'high'],
            ['storage.read.public', 'read_public', 'read', 'normal'],
        ] as [$code, $label, $grant, $risk]) {
            $registered = $registry->register(new AccessOperationDescriptor(
                code: $code,
                ownerPackage: 'larena/storage',
                labelKey: 'larena-storage::operations.' . $label,
                target: str_starts_with($code, 'content.item.')
                    ? 'content.item:all'
                    : (str_starts_with($code, 'storage.schema.') || str_starts_with($code, 'storage.schema_migration.')
                    ? 'storage.schema:all'
                    : (str_starts_with($code, 'storage.workbench.structure.')
                        ? 'storage.workbench.structure:all'
                        : (str_starts_with($code, 'storage.workbench.record.')
                            ? 'storage.workbench.record:all'
                            : (preg_match('/^storage\.(role|relation|locale|publication|read)\./', $code, $area) === 1
                                ? 'storage.' . $area[1] . ':all'
                                : 'storage.record:all')))),
                requiredGrant: $grant,
                risk: $risk,
                auditDenials: true,
            )) || $registered;
        }

        return $registered;
    }
}
