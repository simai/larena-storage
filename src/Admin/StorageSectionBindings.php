<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Admin\Contracts\StorageMenuStructures;
use Larena\Admin\Navigation\AdminNavigationDescriptor;
use Larena\Admin\Navigation\AdminNavigationGroupDescriptor;
use Larena\Admin\Navigation\AdminNavigationRegistry;
use Larena\Admin\Runtime\UnavailableStorageMenuStructures;
use Larena\Admin\Search\AdminSearchRegistry;
use Larena\Admin\Search\CmsRecordsSearchSource;
use Larena\Admin\Search\UsersSearchSource;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Storage\Admin\Contracts\StorageRecordSearch;
use Larena\Storage\Admin\Contracts\StorageSectionScope;
use Larena\Storage\Admin\Contracts\StorageStructureSearch;
use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;
use Larena\Storage\Admin\Runtime\UnavailableStorageRecordSearch;
use Larena\Storage\Admin\Runtime\UnavailableStorageStructureSearch;
use Larena\Storage\Admin\Runtime\UnavailableStorageWorkbenchGateway;
use Larena\Storage\Admin\Search\StorageRecordsSearchSource;
use Larena\Storage\Admin\Search\StoragesSearchSource;
use Larena\Storage\Admin\View\StorageStateViewComposer;
use Larena\Storage\Contracts\StorageWorkbench;

/**
 * Storage's own section of the admin (/admin/storage: storages, their records and settings, CSV import and export,
 * SitePack, encryption, field changes, background jobs) and the storage workbench stand, contributed into larena/admin
 * the way any package contributes a section (owner decision 2026-10-29: the Storage section's UI is Storage's).
 *
 * larena/admin is optional for Storage: StorageServiceProvider calls register() and boot() only when
 * adminInstalled(), so a site without the admin never loads anything of it. Everything under Larena\Storage\Admin
 * is reached only from here, and only this folder (with routes/admin and resources/views/admin) names larena/admin.
 * Every binding is lazy: no database-bound service is built at registration.
 */
final class StorageSectionBindings
{
    /** «Опубликовать» of the section, or null where the composition has no publication or registry. */
    public static function publicationActions(\Illuminate\Contracts\Container\Container $app): ?StorageSectionPublicationActions
    {
        try {
            return $app->make(StorageSectionPublicationActions::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /** larena/admin is installed: the classes the section is built on can be loaded. */
    public static function adminInstalled(): bool
    {
        return class_exists(AdminNavigationRegistry::class);
    }

    public static function register(Application $app): void
    {
        // The section runs on Storage's workbench in the scope the product composition assigns; while any of those
        // services is missing it stays unavailable.
        $app->bindIf(StorageWorkbenchGateway::class, static fn (Application $app): StorageWorkbenchGateway => self::section($app)
            ?? new UnavailableStorageWorkbenchGateway());
        // The admin search finds exactly the storages the section lists ...
        $app->bindIf(StorageStructureSearch::class, static fn (Application $app): StorageStructureSearch => self::section($app)
            ?? new UnavailableStorageStructureSearch());
        // ... and the records the section opens.
        $app->bindIf(StorageRecordSearch::class, static fn (Application $app): StorageRecordSearch => self::section($app)
            ?? new UnavailableStorageRecordSearch());
        // ... and the storages the admin menu shows as rows of «Данные». The admin binds «none» by default, so this
        // binding replaces it whichever provider registers first.
        $app->bind(StorageMenuStructures::class, static fn (Application $app): StorageMenuStructures => self::section($app)
            ?? new UnavailableStorageMenuStructures());
        // The Storage section's reference choices, record labels and its record in the Framework property view.
        // record@1 labels are cached for one request only.
        $app->scopedIf(StorageRecordReferences::class);
        $app->scopedIf(\Larena\Admin\Contracts\RecordFilterOptionsGateway::class, static fn (Application $app): \Larena\Admin\Contracts\RecordFilterOptionsGateway
            => $app->make(StorageRecordFilterOptions::class));
        $app->scopedIf(StoragePropertyViewPort::class, static fn (Application $app): StoragePropertyViewPort
            => new StoragePropertyViewPort(
                $app->make(\Larena\Storage\Admin\Contracts\StorageWorkbenchGateway::class),
                $app->make(\Larena\Storage\Contracts\StorageWorkbench::class),
                $app->make(\Larena\Admin\Record\AdminRecordLayoutStore::class),
                $app->make(\Larena\Property\Runtime\PropertyDisplayProjector::class),
                $app->make(StorageReferenceLabelResolver::class),
                $app->make(\Larena\Admin\Contracts\RecordFilterOptionsGateway::class),
                $app->make(StoragePropertyViewFileStore::class),
                self::publicationActions($app),
            ));
        // «Показать» of an encrypted field: Storage's own reveal (it checks storage.workbench.record.decrypt and audits
        // each reveal), within the person's scope.
        $app->scopedIf(StorageEncryptedFieldReveal::class, static fn (Application $app): StorageEncryptedFieldReveal
            => new StorageEncryptedFieldReveal(
                $app->make(\Larena\Storage\Admin\Contracts\StorageSectionScope::class),
                $app->make(\Larena\Storage\Contracts\StorageWorkbench::class),
                $app->bound(\Larena\Storage\Encryption\StorageFieldReveal::class)
                    ? static fn (string $scope, string $structure, string $record, array $fields, string $actor): array
                        => $app->make(\Larena\Storage\Encryption\StorageFieldReveal::class)->revealRecord($scope, $structure, $record, $fields, $actor)
                    : null,
                $app->bound(\Larena\Access\Contracts\ActorOperationAuthorizer::class) ? $app->make(\Larena\Access\Contracts\ActorOperationAuthorizer::class) : null,
            ));
        // «Опубликовать» in the section, for a storage bound to a publishable role.
        $app->scopedIf(StorageSectionPublication::class, static fn (Application $app): StorageSectionPublication => new StorageSectionPublication(
            $app->make(\Illuminate\Database\DatabaseManager::class)->connection(),
            $app->make(\Larena\Storage\Contracts\StructureRoleRegistry::class),
            $app->make(\Larena\Storage\Contracts\PublicationLifecycle::class),
            $app->make(ConfigRepository::class),
            $app->make(\Illuminate\Contracts\Translation\Translator::class),
        ));
        $app->scopedIf(StorageSectionPublicationActions::class);
        // «Шифрование» on a storage's settings page: the key's state from Storage's health and explain, never key material.
        $app->scopedIf(StorageEncryptionKeyStatus::class, static fn (Application $app): StorageEncryptionKeyStatus
            => new StorageEncryptionKeyStatus(
                $app->bound(\Larena\Storage\Encryption\StorageDataKeys::class)
                    ? static fn (): array => $app->make(\Larena\Storage\Encryption\StorageDataKeys::class)->health()
                    : null,
                $app->bound(\Larena\Storage\Encryption\StorageEncryptionExplain::class)
                    ? static fn (string $scope, string $structure, string $actor): array
                        => $app->make(\Larena\Storage\Encryption\StorageEncryptionExplain::class)->explain($scope, $structure, $actor)
                    : null,
            ));
        // The section's import and export jobs on the durable queue: private files, the person's ledger and
        // progress, and the queue's dispatcher and control when larena/queue is installed.
        $app->singletonIf(StorageJobFiles::class, static fn (Application $app): StorageJobFiles
            => new StorageJobFiles(self::jobsPath($app)));
        $app->scopedIf(StorageCsvExportRows::class, static function (Application $app): StorageCsvExportRows {
            try {
                $labels = $app->make(StorageReferenceLabelResolver::class);
            } catch (\Throwable) {
                $labels = null;
            }

            return new StorageCsvExportRows($labels);
        });
        $app->scopedIf(StorageBackgroundJobs::class, static function (Application $app): StorageBackgroundJobs {
            $queue = class_exists(\Larena\Queue\Runtime\DurableQueueDispatcher::class) && $app->bound(\Larena\Queue\Runtime\JobTypeRegistry::class);

            return new StorageBackgroundJobs(
                $app->make(StorageJobLedger::class),
                $app->make(StorageJobFiles::class),
                $queue ? $app->make(\Larena\Queue\Runtime\DurableQueueDispatcher::class) : null,
                $queue ? $app->make(\Larena\Queue\Runtime\QueueControlService::class) : null,
                $queue ? $app->make(\Larena\Queue\Runtime\JobTypeRegistry::class) : null,
            );
        });
        // A storage's SitePack export reads its files from Filesystem, only those of the site being exported (core's
        // scope resource bindings). It replaces Storage's «no files» default when it is resolved, never at boot.
        if (interface_exists(\Larena\Storage\SitePack\StorageSitePackFiles::class)) {
            $app->extend(\Larena\Storage\SitePack\StorageSitePackFiles::class, static fn (\Larena\Storage\SitePack\StorageSitePackFiles $files, \Illuminate\Contracts\Container\Container $app): \Larena\Storage\SitePack\StorageSitePackFiles
                => $files instanceof \Larena\Storage\SitePack\UnavailableStorageSitePackFiles
                    && $app->bound(\Larena\Filesystem\Contracts\PortableLogicalFileStore::class) && $app->bound(\Larena\Core\Contracts\ScopeResourceBindings::class)
                    ? new FilesystemStorageSitePackFiles(
                        $app->make(\Larena\Filesystem\Contracts\PortableLogicalFileStore::class),
                        $app->make(\Larena\Core\Contracts\ScopeResourceBindings::class))
                    : $files);
        }
        // ... and an import writes its files there, each bound to the site it goes into. Storage's «no files» default
        // is replaced only when Filesystem and core's bindings are composed, and only when it is resolved.
        if (interface_exists(\Larena\Storage\SitePack\StorageSitePackFileImports::class)) {
            $app->extend(\Larena\Storage\SitePack\StorageSitePackFileImports::class, static fn (\Larena\Storage\SitePack\StorageSitePackFileImports $files, \Illuminate\Contracts\Container\Container $app): \Larena\Storage\SitePack\StorageSitePackFileImports
                => $files instanceof \Larena\Storage\SitePack\UnavailableStorageSitePackFiles
                    && $app->bound(\Larena\Filesystem\Contracts\PortableLogicalFileStore::class) && $app->bound(\Larena\Core\Contracts\ScopeResourceBindings::class)
                    ? new FilesystemStorageSitePackFileImports(
                        $app->make(\Larena\Filesystem\Contracts\PortableLogicalFileStore::class),
                        $app->make(\Larena\Core\Contracts\ScopeResourceBindings::class),
                        $app->make(\Illuminate\Database\ConnectionResolverInterface::class))
                    : $files);
            // «Загрузить хранилище»: the person's imports over Storage's SitePack, with what the page keeps of each pack.
            $app->bindIf(StorageSitePackImports::class, static fn (Application $app): StorageSitePackImports
                => new StorageSitePackImports(
                    $app->make(\Larena\Storage\Contracts\StorageSitePack::class),
                    new StorageSitePackImportNotes(self::jobsPath($app).'/sitepack-imports'),
                    $app->bound(\Larena\Core\SitePack\SitePackLimits::class) ? $app->make(\Larena\Core\SitePack\SitePackLimits::class) : null,
                ));
        }
    }

    /**
     * The section's menu group and item, its queue jobs, its search sources and its pages' unavailable state, once
     * every provider is registered. Its routes and views are loaded by StorageServiceProvider.
     */
    public static function boot(Application $app): void
    {
        $config = $app->make(ConfigRepository::class);
        if ((bool) $config->get('larena-admin.storage_section.enabled', false) && $app->bound(AdminNavigationRegistry::class)) {
            $registry = $app->make(AdminNavigationRegistry::class);
            $registry->registerGroup(new AdminNavigationGroupDescriptor(
                'larena.navigation.storage',
                'larena/storage',
                'Storage',
                15,
                icon: 'database',
                labelKey: 'larena-admin::admin.navigation.groups.storage',
            ));
            $registry->register($app->make(AdminNavigationDescriptor::class, [
                'id' => 'admin.storage', 'ownerPackage' => 'larena/storage', 'label' => 'Storages',
                'routeName' => 'larena.admin.storage.index', 'routeUri' => '/admin/storage',
                'category' => 'content', 'state' => 'available_guarded', 'accessScope' => 'storage.workbench.structure.list',
                'auditEvent' => 'admin.storage.viewed', 'statusCap' => 'storage_section_guarded',
                'order' => 10, 'group' => 'content', 'knownLimitations' => [],
                'surface' => 'product', 'labelKey' => 'larena-storage::admin.section.navigation',
                'activeRoutePattern' => 'larena.admin.storage.*',
                'icon' => 'table_rows', 'parentId' => 'larena.navigation.storage',
            ]));
        }
        // storage.import.plan, storage.import.apply and storage.export.csv, run by `larena:queue-work` from the schedule.
        // Each handler is made only when its job runs: no database-bound service is built here.
        if ((bool) $config->get('larena-admin.storage_section.enabled', false)
            && class_exists(\Larena\Queue\Runtime\JobTypeRegistry::class) && $app->bound(\Larena\Queue\Runtime\JobTypeRegistry::class)) {
            StorageBackgroundJobs::register(
                $app->make(\Larena\Queue\Runtime\JobTypeRegistry::class),
                new StorageDeferredJobHandler(static fn (): \Larena\Queue\Contracts\QueueJobHandler => $app->make(StorageImportJobHandler::class)),
                new StorageDeferredJobHandler(static fn (): \Larena\Queue\Contracts\QueueJobHandler => $app->make(StorageExportJobHandler::class)),
                new StorageDeferredJobHandler(static fn (): \Larena\Queue\Contracts\QueueJobHandler => $app->make(StorageImportPlanJobHandler::class)),
            );
        }
        // The admin search finds the section's records (before the CMS records) and its storages (before the users),
        // in the order the admin showed them when the section was its own.
        if ($app->bound(AdminSearchRegistry::class)) {
            $search = $app->make(AdminSearchRegistry::class);
            $search->add(StorageRecordsSearchSource::class, before: CmsRecordsSearchSource::class);
            $search->add(StoragesSearchSource::class, before: UsersSearchSource::class);
        }
        $app->make(ViewFactory::class)->composer('larena-storage::admin._state', StorageStateViewComposer::class);
    }

    /** The section's gateway, when Storage, Access, Filesystem and the product's scope are all bound. */
    private static function section(Application $app): ?StorageSectionGateway
    {
        foreach ([StorageWorkbench::class, ActorOperationAuthorizer::class, StorageSectionScope::class, PersistentLogicalFileInspector::class] as $service) {
            if (!$app->bound($service)) {
                return null;
            }
        }

        return $app->make(StorageSectionGateway::class);
    }

    /** Where the section's job files are kept: a private directory of the application's storage, never a public disk. */
    public static function jobsPath(Application $app): string
    {
        $configured = $app->make(ConfigRepository::class)->get('larena-admin.storage_section.jobs_path');
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return $app->storagePath('app/private/larena-admin/storage-jobs');
    }
}
