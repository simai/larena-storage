<?php

declare(strict_types=1);

namespace Larena\Storage\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Larena\Storage\Console\Commands\PublicationSweepCommand;
use Larena\Storage\Console\Commands\PublishedKeyRebuildCommand;
use Larena\Storage\Console\Commands\StorageBenchCommand;
use Larena\Storage\Console\Commands\StorageViewCheckCommand;
use Larena\Storage\Console\Commands\StorageViewRebuildCommand;
use Larena\Storage\Console\Commands\StorageSitePackExportCommand;
use Larena\Storage\Console\Commands\StorageSitePackSweepCommand;
use Larena\Storage\Console\Commands\StorageSitePackImportCommand;
use Larena\Storage\SitePack\StorageSitePackFileImports;
use Larena\Storage\SitePack\StorageSitePackImportJob;
use Larena\Access\Contracts\AccessSubjectDirectory;
use Larena\Core\Contracts\ScopeMembershipRegistry;
use Larena\Core\SitePack\SitePackLimits;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\SitePack\DatabaseStorageSitePack;
use Larena\Storage\SitePack\StorageSitePackFiles;
use Larena\Storage\SitePack\StorageSitePackJob;
use Larena\Storage\SitePack\StorageSitePackOperationHandlers;
use Larena\Storage\SitePack\UnavailableStorageSitePackFiles;
use Larena\Queue\Contracts\QueueClock;
use Larena\Queue\Runtime\DurableQueueDispatcher;
use Larena\Queue\Runtime\JobTypeRegistry;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\StorageViewRebuildDeferredHandler;
use Larena\Storage\View\StorageViewRebuildJob;
use Larena\Storage\View\StorageViewRebuildQueue;
use Larena\Storage\View\StorageViewCounter;
use Larena\Storage\View\StorageViewSync;
use Larena\Storage\View\Site\PublishedSiteViewIndex;
use Larena\Storage\View\Site\PublishedSiteViewReader;
use Larena\Storage\View\Site\PublishedSiteViewRegistry;
use Larena\Storage\Runtime\LocalizedValueObserverChain;
use Larena\Storage\Runtime\PublicationIndexChain;
use Larena\Storage\Contracts\LocaleFallbackResolver;
use Larena\Storage\Contracts\PublicationObserver;
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
use Larena\Storage\Contracts\LocalizedValueObserver;
use Larena\Storage\Contracts\PublishedKeyPolicy;
use Larena\Storage\Contracts\PublishedListReads;
use Larena\Storage\Contracts\PublishedReadVisibility;
use Larena\Storage\Contracts\ReadContracts;
use Larena\Storage\Contracts\RecordReadVisibility;
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
use Larena\Storage\Runtime\RequestedLocaleOnly;
use Larena\Storage\Runtime\AllRecordsVisible;
use Larena\Storage\Runtime\DatabaseRecordRelations;
use Larena\Storage\Runtime\DatabaseRelationTargets;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\LocaleOperationHandlers;
use Larena\Storage\Runtime\PublicationOperationHandlers;
use Larena\Storage\Runtime\NoPublishedKeys;
use Larena\Storage\Runtime\PublishedKeyIndex;
use Larena\Storage\Runtime\PublishedKeyUniqueness;
use Larena\Storage\Runtime\PublishedRecordsArePublic;
use Larena\Storage\Runtime\ReadContractOperationHandlers;
use Larena\Storage\Runtime\RecordOperationHandlers;
use Larena\Storage\Runtime\DatabaseAdminRecordTreeReader;
use Larena\Storage\Contracts\AdminRecordTreeReader;
use Larena\Core\Runtime\OperationHandlerCatalog;
use Larena\Core\Contracts\OperationHandler;
use Larena\Storage\Runtime\SlugUniquenessGuard;
use Larena\Storage\Runtime\RelationOperationHandlers;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\Runtime\StructureRoleDependencies;
use Larena\Storage\Runtime\StructureRoleOperationHandlers;
use Larena\Storage\Runtime\NullStorageSecurityEventSink;
use Larena\Storage\Runtime\VersionedStorage;
use Larena\Storage\SchemaEvolution\DatabaseStorageSchemaEvolution;
use Larena\Storage\SchemaEvolution\StorageFieldChangeJob;
use Larena\Storage\SchemaEvolution\StorageRecordConverter;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;
use Larena\Secret\Contracts\DataKeyWrapper;
use Larena\Secret\Local\AppKeyDerivedDataKeyWrapper;
use Larena\Storage\Encryption\StorageDataKeys;
use Larena\Storage\Encryption\StorageEncryptionExplain;
use Larena\Storage\Encryption\StorageEncryptionOperationHandlers;
use Larena\Storage\Encryption\StorageFieldReveal;
use Larena\Storage\Encryption\StorageFieldSealer;

final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!$this->app->bound(StorageSecurityEventSink::class)) {
            $this->app->singleton(StorageSecurityEventSink::class, NullStorageSecurityEventSink::class);
        }
        // Field encryption (larena-specs docs/architecture/2026-10-22-storage-field-encryption.md). The KEK is
        // larena/secret's, derived from APP_KEY (previous keys keep unwrapping); a host may bind its own wrapper. Made
        // at the first encrypted write or reveal, never at boot: without APP_KEY or sodium the application boots and
        // every encrypted write is refused.
        $this->app->singletonIf(DataKeyWrapper::class, static function (Application $app): DataKeyWrapper {
            $config = $app->make('config');
            $key = $config->get('app.key');
            $previous = $config->get('app.previous_keys', []);

            return new AppKeyDerivedDataKeyWrapper(is_string($key) ? $key : '', is_array($previous) ? $previous : []);
        });
        $this->app->singleton(StorageDataKeys::class, static fn (Application $app): StorageDataKeys => new StorageDataKeys(
            $app->make(DatabaseManager::class)->connection(),
            static fn (): DataKeyWrapper => $app->make(DataKeyWrapper::class),
        ));
        $this->app->singleton(StorageFieldSealer::class, static fn (Application $app): StorageFieldSealer => new StorageFieldSealer(
            $app->make(StorageDataKeys::class),
        ));
        // The one way to plaintext: storage.workbench.record.decrypt, audited (storage.field.revealed).
        $this->app->singleton(StorageFieldReveal::class, static fn (Application $app): StorageFieldReveal => new StorageFieldReveal(
            $app->make(StorageDataKeys::class),
            $app->make(ActorOperationAuthorizer::class),
            $app->make(StorageSecurityEventSink::class),
            $app->make(StorageWorkbenchContract::class),
        ));
        $this->app->singleton(StorageEncryptionExplain::class, static fn (Application $app): StorageEncryptionExplain => new StorageEncryptionExplain(
            $app->make(StorageWorkbenchContract::class),
            $app->make(StorageDataKeys::class),
            $app->make(ActorOperationAuthorizer::class),
        ));
        $this->app->singleton(StorageEncryptionOperationHandlers::class, static fn (Application $app): StorageEncryptionOperationHandlers => new StorageEncryptionOperationHandlers(
            $app->make(StorageEncryptionExplain::class),
        ));
        $this->app->singleton(DatabaseStructureRoleRegistry::class, static function (Application $app): DatabaseStructureRoleRegistry {
            return new DatabaseStructureRoleRegistry(
                $app->make(DatabaseManager::class)->connection(),
                $app->bound(ScopeRefResolver::class) ? $app->make(ScopeRefResolver::class) : null,
                $app->make(StorageSecurityEventSink::class),
            );
        });
        // Consumers declare the roles they read; the declarations are checked here.
        $this->app->singleton(StructureRoleDependencies::class, static fn (Application $app): StructureRoleDependencies => new StructureRoleDependencies(
            $app->make(StructureRoleRegistry::class),
        ));
        $this->app->alias(DatabaseStructureRoleRegistry::class, StructureRoleRegistry::class);

        $this->app->singleton(StarterStructureRoles::class, static function (Application $app): StarterStructureRoles {
            return new StarterStructureRoles($app->make(StructureRoleRegistry::class));
        });

        $this->app->singleton(StructureRoleOperationHandlers::class, static function (Application $app): StructureRoleOperationHandlers {
            return new StructureRoleOperationHandlers($app->make(StructureRoleRegistry::class));
        });

        $this->app->singleton(DatabaseRecordRelations::class, static function (Application $app): DatabaseRecordRelations {
            $connection = $app->make(DatabaseManager::class)->connection();

            // Relations are checked against what their records are, and audited.
            return new DatabaseRecordRelations(
                $connection,
                new DatabaseRelationTargets($connection),
                $app->make(StorageSecurityEventSink::class),
            );
        });
        $this->app->alias(DatabaseRecordRelations::class, RecordRelations::class);

        $this->app->singleton(RelationOperationHandlers::class, static function (Application $app): RelationOperationHandlers {
            return new RelationOperationHandlers($app->make(RecordRelations::class), $app->make(RecordReadVisibility::class));
        });

        $this->app->singleton(DatabaseLocalizedValues::class, static function (Application $app): DatabaseLocalizedValues {
            $primary = $app->make('config')->get('app.locale');

            return new DatabaseLocalizedValues(
                $app->make(DatabaseManager::class)->connection(),
                $app->make(PropertyTypeRegistry::class),
                // The shared values are written in the application's locale.
                is_string($primary) && $primary !== '' ? $primary : null,
                $app->make(StorageSecurityEventSink::class),
                // A translation of a published revision moves its published key and its
                // site витрина rows. The indexes read through this class, so they are built
                // at the first write.
                static fn (): LocalizedValueObserver => new LocalizedValueObserverChain([
                    $app->make(PublishedKeyIndex::class),
                    $app->make(PublishedSiteViewIndex::class),
                ]),
                // A translation of an encrypted field is sealed for its locale.
                $app->make(StorageFieldSealer::class),
            );
        });
        $this->app->alias(DatabaseLocalizedValues::class, LocalizedValues::class);

        $this->app->singleton(LocaleOperationHandlers::class, static function (Application $app): LocaleOperationHandlers {
            return new LocaleOperationHandlers($app->make(LocalizedValues::class), $app->make(LocaleFallbackResolver::class));
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
                // A derived index the application binds (Search) hears of every change.
                $app->bound(PublicationObserver::class) ? $app->make(PublicationObserver::class) : null,
                // Every transition is audited, whatever path called it.
                $app->make(StorageSecurityEventSink::class),
                // A key the application routes by stays unique among published records.
                new PublishedKeyUniqueness($app->make(DatabaseReadContracts::class), $app->make(PublishedKeyPolicy::class)),
                // The indexed published keys and the site витрина move with the head, in its transaction.
                new PublicationIndexChain([
                    $app->make(PublishedKeyIndex::class),
                    $app->make(PublishedSiteViewIndex::class),
                ]),
            );
        });
        // The site витрина: the published public fields of each storage, one row per record and slot.
        $this->app->singleton(PublishedSiteViewIndex::class, static fn (Application $app): PublishedSiteViewIndex => new PublishedSiteViewIndex(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabaseReadContracts::class),
        ));
        $this->app->singleton(PublishedKeyIndex::class, static fn (Application $app): PublishedKeyIndex => new PublishedKeyIndex(
            $app->make(DatabaseManager::class)->connection(),
            $app->make(DatabaseReadContracts::class),
            $app->make(PublishedKeyPolicy::class),
        ));
        $this->app->alias(DatabasePublicationLifecycle::class, PublicationLifecycle::class);

        $this->app->singleton(PublicationOperationHandlers::class, static function (Application $app): PublicationOperationHandlers {
            return new PublicationOperationHandlers($app->make(PublicationLifecycle::class));
        });

        $this->app->singleton(DatabaseReadContracts::class, static function (Application $app): DatabaseReadContracts {
            $connection = $app->make(DatabaseManager::class)->connection();

            return new DatabaseReadContracts(
                $connection,
                $app->make(LocalizedValues::class),
                $app->make(LocaleFallbackResolver::class),
                $app->make(StorageMetrics::class),
                new StorageRecordConverter($connection, $app->make(PropertyTypeRegistry::class)),
                // A ready site витрина slot answers the public reads, checked against the publication states.
                $app->make(PublishedSiteViewReader::class),
                DatabaseReadContracts::KEY_SCAN_LIMIT,
                // A public list normalizes its filter values by the fields' types and seals its cursor with the app key.
                $app->make(PropertyTypeRegistry::class),
                is_string($app->make('config')->get('app.key')) ? $app->make('config')->get('app.key') : '',
                // A host may bind its own counter (a threshold, a time budget); the default counts as decided.
                $app->bound(StorageViewCounter::class) ? $app->make(StorageViewCounter::class) : null,
            );
        });
        // The reader holds the connection only; it reads at the first public read. A rebuild it asks
        // for (a mismatch it found, public fields changed under a ready slot) goes through the queue.
        $this->app->singleton(PublishedSiteViewReader::class, static fn (Application $app): PublishedSiteViewReader => new PublishedSiteViewReader(
            $app->make(DatabaseManager::class)->connection(),
            static function (string $schemaId) use ($app): void {
                if ($app->bound(JobTypeRegistry::class) && $app->bound(DurableQueueDispatcher::class) && $app->bound(QueueClock::class)) {
                    $app->make(StorageViewRebuildQueue::class)->enqueueSite($schemaId);
                }
            },
        ));
        $this->app->alias(DatabaseReadContracts::class, ReadContracts::class);
        $this->app->alias(DatabaseReadContracts::class, PublishedListReads::class);
        // Lang owns the fallback order; the application binds its policy. Without it,
        // a read uses the requested locale alone.
        if (!$this->app->bound(LocaleFallbackResolver::class)) {
            $this->app->singleton(LocaleFallbackResolver::class, RequestedLocaleOnly::class);
        }

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

        $this->app->bind(AdminRecordTreeReader::class, static function (Application $app): AdminRecordTreeReader {
            $connection = $app->make(DatabaseManager::class)->connection();

            return new DatabaseAdminRecordTreeReader(
                $connection,
                $app->make(ActorOperationAuthorizer::class),
                new StorageRecordConverter($connection, $app->make(PropertyTypeRegistry::class)),
            );
        });
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
                    'storage.handler.sitepack' => StorageSitePackOperationHandlers::class,
                    'storage.handler.encryption' => StorageEncryptionOperationHandlers::class,
                ] as $ref => $class) {
                    if (!$catalog->has($ref)) {
                        $catalog->register($ref, static fn (): OperationHandler => $app->make($class));
                    }
                }

                return $catalog;
            },
        );

        // A composition with record-level read rules binds its own visibility first.
        $this->app->singletonIf(PublishedReadVisibility::class, PublishedRecordsArePublic::class);
        $this->app->singletonIf(PublishedKeyPolicy::class, NoPublishedKeys::class);
        $this->app->singletonIf(RecordReadVisibility::class, AllRecordsVisible::class);
        $this->app->singleton(ReadContractOperationHandlers::class, static function (Application $app): ReadContractOperationHandlers {
            return new ReadContractOperationHandlers($app->make(ReadContracts::class), $app->make(PublishedReadVisibility::class));
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
        // Hot-path metrics for larena/monitoring; a no-op when monitoring is absent.
        $this->app->singleton(StorageMetrics::class, static fn (Application $app): StorageMetrics => StorageMetrics::fromContainer($app));
        $this->registerMetricCatalog();
        // Each storage's витрина (typed read table), kept in the write transaction.
        $this->app->singleton(StorageViewSync::class, static function (Application $app): StorageViewSync {
            $connection = $app->make(DatabaseManager::class)->connection();
            $views = StorageViewSync::for($connection);
            // A rebuild reads older record versions through the converter, with the application's Property types.
            $views->useConverter(new StorageRecordConverter($connection, $app->make(PropertyTypeRegistry::class)));
            // Structure changes silence the site витрина and queue its rebuild; the index and the
            // queue are made only when a change needs them.
            $views->useSiteViews(
                new PublishedSiteViewRegistry($connection),
                static fn (): PublishedSiteViewIndex => $app->make(PublishedSiteViewIndex::class),
                static function (string $schemaId) use ($app): void {
                    if ($app->bound(JobTypeRegistry::class) && $app->bound(DurableQueueDispatcher::class) && $app->bound(QueueClock::class)) {
                        $app->make(StorageViewRebuildQueue::class)->enqueueSite($schemaId);
                    }
                },
            );

            return $views;
        });
        // The витрина rebuild as a durable queue job (storage.view.rebuild).
        $this->app->bind(StorageViewRebuildJob::class, static fn (Application $app): StorageViewRebuildJob => new StorageViewRebuildJob(
            $app->make(StorageViewSync::class),
            $app->make(StorageMetrics::class),
            // A `view: site` job builds the site витрина; its index is made only then.
            static fn (): PublishedSiteViewIndex => $app->make(PublishedSiteViewIndex::class),
        ));
        $this->app->bind(StorageViewRebuildQueue::class, static fn (Application $app): StorageViewRebuildQueue => new StorageViewRebuildQueue(
            $app->make(StorageViewSync::class),
            $app->make(JobTypeRegistry::class),
            $app->make(DurableQueueDispatcher::class),
            $app->make(QueueClock::class),
        ));
        // A field change that checks every record, as a durable queue job (storage.schema.migrate).
        $this->app->bind(StorageFieldChangeJob::class, static fn (Application $app): StorageFieldChangeJob => new StorageFieldChangeJob(
            $app->make(DatabaseStorageWorkbench::class),
        ));
        $this->app->afterResolving(JobTypeRegistry::class, static function (JobTypeRegistry $registry, Application $app): void {
            self::registerQueueJobs($registry, $app);
        });
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
                $app->make(DatabaseRecordRelations::class),
                $app->make(StorageViewSync::class),
                $app->make(StorageMetrics::class),
                // Encrypted fields are sealed on every write.
                $app->make(StorageFieldSealer::class),
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
                $app->make(StorageViewSync::class),
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
                $app->make(StorageSchemaEvolutionOwnerPolicyRegistry::class),
                is_string($app->make('config')->get('app.key'))
                    ? $app->make('config')->get('app.key')
                    : '',
                $app->make(StorageSecurityEventSink::class),
                $app->make(StorageViewSync::class),
                // A host may bind its own counter (a threshold, a time budget); the default counts as decided.
                $app->bound(StorageViewCounter::class) ? $app->make(StorageViewCounter::class) : null,
                $app->make(StorageMetrics::class),
                // A change of field options queues the витрина backfill; the queue is resolved only then.
                static function (string $schemaId) use ($app): void {
                    if ($app->bound(JobTypeRegistry::class) && $app->bound(DurableQueueDispatcher::class) && $app->bound(QueueClock::class)) {
                        $app->make(StorageViewRebuildQueue::class)->enqueue($schemaId);
                    }
                },
                // A large field change runs as storage.schema.migrate; without a queue it runs in the request.
                static function (string $changeRef) use ($app): bool {
                    if (!$app->bound(JobTypeRegistry::class) || !$app->bound(DurableQueueDispatcher::class)
                        || !$app->make(JobTypeRegistry::class)->has(StorageFieldChangeJob::JOB_TYPE)) {
                        return false;
                    }
                    $app->make(DurableQueueDispatcher::class)->dispatch(StorageFieldChangeJob::request($changeRef));

                    return true;
                },
                encryption: $app->make(StorageFieldSealer::class),
            );
        });
        $this->app->alias(DatabaseStorageWorkbench::class, StorageWorkbenchContract::class);

        // SitePack export of a storage (storage.sitepack.export). Files are read through a port the application
        // binds over Filesystem; without one, a storage with file values cannot be exported.
        $this->app->bindIf(StorageSitePackFiles::class, UnavailableStorageSitePackFiles::class);
        // An import writes files through the import side of that port; without one, a pack with files cannot be imported.
        $this->app->bindIf(StorageSitePackFileImports::class, UnavailableStorageSitePackFiles::class);
        $this->app->singleton(DatabaseStorageSitePack::class, static function (Application $app): DatabaseStorageSitePack {
            /** @var DatabaseManager $database */
            $database = $app->make(DatabaseManager::class);
            $config = $app->make('config');
            $directory = $config->get('larena-storage.sitepack.directory');

            return new DatabaseStorageSitePack(
                database: $database->connection(),
                workbench: $app->make(StorageWorkbenchContract::class),
                storage: $app->make(VersionedStorageContract::class),
                propertyTypes: $app->make(PropertyTypeRegistry::class),
                authorizer: $app->make(ActorOperationAuthorizer::class),
                scopes: $app->make(QueryScopeProvider::class),
                // Private: 0700 directories, 0600 files, outside any public disk.
                directory: is_string($directory) && $directory !== '' ? $directory : self::sitePackDirectory($app),
                files: $app->make(StorageSitePackFiles::class),
                limits: $app->bound(SitePackLimits::class) ? $app->make(SitePackLimits::class) : null,
                events: $app->make(StorageSecurityEventSink::class),
                // Export, plan and import run as their queue jobs when there is a queue; otherwise the caller runs them.
                jobs: static function (string $runRef, int $generation, string $jobType = StorageSitePackJob::JOB_TYPE) use ($app): ?string {
                    if (!$app->bound(JobTypeRegistry::class) || !$app->bound(DurableQueueDispatcher::class)
                        || !$app->make(JobTypeRegistry::class)->has($jobType)) {
                        return null;
                    }
                    $request = $jobType === StorageSitePackJob::JOB_TYPE
                        ? StorageSitePackJob::request($runRef, $generation)
                        : StorageSitePackImportJob::request($jobType, $runRef, $generation);

                    return $app->make(DurableQueueDispatcher::class)->dispatch($request)->jobId;
                },
                // The site the person works in: an export carries its publication states, an import goes into it.
                siteOf: static function (string $actor) use ($app): string {
                    if ($app->bound(ScopeMembershipRegistry::class)) {
                        try {
                            $scope = $app->make(ScopeMembershipRegistry::class)->defaultScopeOf($actor);
                            if ($scope !== null) {
                                return $scope->ref->toString();
                            }
                        } catch (\Throwable) {
                        }
                    }

                    return 'site:' . ScopeBaselineInstaller::DEFAULT_SITE_IDENTIFIER;
                },
                fileImports: $app->make(StorageSitePackFileImports::class),
                localized: $app->make(LocalizedValues::class),
                publication: $app->make(PublicationLifecycle::class),
                roles: $app->make(StructureRoleRegistry::class),
                // A user@1 value keeps its user when Access knows it (larena_admin_identities); without Access every
                // user counts as missing.
                userExists: static function (string $userRef) use ($app): bool {
                    if (!$app->bound(AccessSubjectDirectory::class)) {
                        return false;
                    }
                    try {
                        return $app->make(AccessSubjectDirectory::class)->exists($userRef);
                    } catch (\Throwable) {
                        return false;
                    }
                },
                // The locales a site declares: the storage's own setting, else the admin's editor locales, else the
                // application locale.
                siteLocales: static function (string $siteRef) use ($app): array {
                    $config = $app->make('config');
                    foreach (['larena-storage.sitepack.locales', 'larena-admin.structure_editors.locales'] as $key) {
                        $locales = $config->get($key);
                        if (is_array($locales) && $locales !== []) {
                            return array_values(array_filter($locales, 'is_string'));
                        }
                    }
                    $locale = $config->get('app.locale');

                    return is_string($locale) && $locale !== '' ? [$locale] : [];
                },
                // An imported storage's витрины are rebuilt by the queue (storage.view.rebuild, admin and site).
                viewRebuilds: static function (string $schemaId) use ($app): void {
                    if ($app->bound(JobTypeRegistry::class) && $app->bound(DurableQueueDispatcher::class) && $app->bound(QueueClock::class)) {
                        $queue = $app->make(StorageViewRebuildQueue::class);
                        $queue->enqueue($schemaId);
                        $queue->enqueueSite($schemaId);
                    }
                },
            );
        });
        $this->app->alias(DatabaseStorageSitePack::class, StorageSitePack::class);
        $this->app->bind(StorageSitePackJob::class, static fn (Application $app): StorageSitePackJob => new StorageSitePackJob(
            $app->make(DatabaseStorageSitePack::class),
        ));
        $this->app->bind(StorageSitePackImportJob::class, static fn (Application $app): StorageSitePackImportJob => new StorageSitePackImportJob(
            $app->make(DatabaseStorageSitePack::class),
            StorageSitePackImportJob::IMPORT_JOB_TYPE,
        ));
        $this->app->singleton(StorageSitePackOperationHandlers::class, static fn (Application $app): StorageSitePackOperationHandlers => new StorageSitePackOperationHandlers(
            $app->make(StorageSitePack::class),
        ));

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
        if ($this->app->bound(JobTypeRegistry::class)) {
            self::registerQueueJobs($this->app->make(JobTypeRegistry::class), $this->app);
        }

        // Scheduled publications are published by the sweep, never by a read. The
        // Laravel scheduler runs it every minute; one cron line starts the scheduler.
        if ($this->app->runningInConsole()) {
            // The benchmark measures the engine on a disposable database only.
            // The витрина rebuild builds the typed read tables of existing storages
            // (inline or as queue jobs); the check compares them with the versions.
            $this->commands([
                PublicationSweepCommand::class,
                PublishedKeyRebuildCommand::class,
                StorageBenchCommand::class,
                StorageViewRebuildCommand::class,
                StorageViewCheckCommand::class,
                StorageSitePackExportCommand::class,
                StorageSitePackSweepCommand::class,
                StorageSitePackImportCommand::class,
            ]);
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('storage:publication:sweep')
                    ->everyMinute()
                    ->withoutOverlapping()
                    ->runInBackground();
            });
        }
    }

    /** The default home of SitePack runs: the application's private storage. */
    private static function sitePackDirectory(Application $app): string
    {
        return rtrim($app->storagePath(), '/') . '/app/private/larena-storage-sitepack';
    }

    /** Declares Storage's metrics in larena/monitoring's catalog when monitoring is installed. */
    private function registerMetricCatalog(): void
    {
        if (!class_exists(StorageMetrics::CATALOG_CLASS)) {
            return;
        }
        $this->callAfterResolving(StorageMetrics::CATALOG_CLASS, static function (object $catalog): void {
            $register = [$catalog, 'register'];
            if (is_callable($register)) {
                $register(StorageMetrics::PACKAGE, StorageMetrics::catalogPath());
            }
        });
    }

    private static function registerQueueJobs(JobTypeRegistry $registry, Application $app): void
    {
        if (!$registry->has(StorageViewRebuildJob::JOB_TYPE)) {
            $registry->register(StorageViewRebuildJob::descriptor(), new StorageViewRebuildDeferredHandler(
                static fn (): StorageViewRebuildJob => $app->make(StorageViewRebuildJob::class),
            ));
        }
        // Deferred as well: the workbench (and its connection) is made only when a worker runs the job.
        if (!$registry->has(StorageFieldChangeJob::JOB_TYPE)) {
            $registry->register(StorageFieldChangeJob::descriptor(), new StorageViewRebuildDeferredHandler(
                static fn (): StorageFieldChangeJob => $app->make(StorageFieldChangeJob::class),
            ));
        }
        if (!$registry->has(StorageSitePackJob::JOB_TYPE)) {
            $registry->register(StorageSitePackJob::descriptor(), new StorageViewRebuildDeferredHandler(
                static fn (): StorageSitePackJob => $app->make(StorageSitePackJob::class),
            ));
        }
        // The import's plan and import jobs; deferred like the others, so nothing touches the database at boot.
        foreach ([StorageSitePackImportJob::PLAN_JOB_TYPE, StorageSitePackImportJob::IMPORT_JOB_TYPE] as $jobType) {
            if (!$registry->has($jobType)) {
                $registry->register(StorageSitePackImportJob::descriptor($jobType), new StorageViewRebuildDeferredHandler(
                    static fn (): StorageSitePackImportJob => new StorageSitePackImportJob($app->make(DatabaseStorageSitePack::class), $jobType),
                ));
            }
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
            ['storage.record.purge', 'record_purge', 'delete', 'critical'],
            ['storage.workbench.structure.create', 'workbench_structure_create', 'create', 'critical'],
            ['storage.workbench.structure.read', 'workbench_structure_read', 'read', 'high'],
            ['storage.workbench.structure.list', 'workbench_structure_list', 'read', 'high'],
            ['storage.workbench.structure.update', 'workbench_structure_update', 'update', 'critical'],
            ['storage.workbench.structure.migrate', 'workbench_structure_migrate', 'update', 'critical'],
            ['storage.workbench.structure.archive', 'workbench_structure_archive', 'delete', 'critical'],
            ['storage.workbench.structure.restore', 'workbench_structure_restore', 'restore', 'critical'],
            ['storage.workbench.structure.purge', 'workbench_structure_purge', 'delete', 'critical'],
            ['storage.workbench.record.create', 'workbench_record_create', 'create', 'high'],
            ['storage.workbench.record.read', 'workbench_record_read', 'read', 'high'],
            ['storage.workbench.record.list', 'workbench_record_list', 'read', 'high'],
            ['storage.workbench.record.update', 'workbench_record_update', 'update', 'high'],
            ['storage.workbench.record.archive', 'workbench_record_archive', 'delete', 'critical'],
            ['storage.workbench.record.restore', 'workbench_record_restore', 'restore', 'critical'],
            ['storage.workbench.record.bulk_archive', 'workbench_record_bulk_archive', 'delete', 'critical'],
            ['storage.workbench.record.history', 'workbench_record_history', 'read', 'high'],
            ['storage.workbench.record.purge', 'workbench_record_purge', 'delete', 'critical'],
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
            // A whole storage leaves the site in one archive: high risk, always audited.
            ['storage.sitepack.export', 'sitepack_export', 'export', 'high'],
            // A whole storage enters the site from an archive: critical, always audited.
            ['storage.sitepack.import', 'sitepack_import', 'import', 'critical'],
            // Field encryption: plaintext of an encrypted value (critical, every reveal audited), making or switching
            // encrypted fields (critical), reading what a structure's encryption is (high).
            ['storage.workbench.record.decrypt', 'workbench_record_decrypt', 'decrypt', 'critical'],
            ['storage.encryption.manage', 'encryption_manage', 'manage', 'critical'],
            ['storage.encryption.explain', 'encryption_explain', 'read', 'high'],
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
                            : (preg_match('/^storage\.(role|relation|locale|publication|read|sitepack|encryption)\./', $code, $area) === 1
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
