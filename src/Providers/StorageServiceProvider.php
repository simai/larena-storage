<?php

declare(strict_types=1);

namespace Larena\Storage\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Runtime\AccessOperationRegistry;
use Larena\Access\ValueObjects\AccessOperationDescriptor;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\VersionedStorage as VersionedStorageContract;
use Larena\Storage\Runtime\VersionedStorage;

final class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VersionedStorage::class, static function (Application $app): VersionedStorage {
            /** @var DatabaseManager $database */
            $database = $app->make(DatabaseManager::class);

            return new VersionedStorage(
                $database->connection(),
                $app->make(PropertyTypeRegistry::class),
                $app->make(ActorOperationAuthorizer::class),
                $app->make(AuditEventPipeline::class),
            );
        });
        $this->app->alias(VersionedStorage::class, VersionedStorageContract::class);

        $this->app->afterResolving(
            AccessOperationRegistry::class,
            static fn (AccessOperationRegistry $registry): bool => self::registerAccessOperations($registry),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

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
            ['storage.record.create', 'record_create', 'create', 'high'],
            ['storage.record.read', 'record_read', 'read', 'high'],
            ['storage.record.update', 'record_update', 'update', 'high'],
        ] as [$code, $label, $grant, $risk]) {
            $registered = $registry->register(new AccessOperationDescriptor(
                code: $code,
                ownerPackage: 'larena/storage',
                labelKey: 'larena-storage::operations.' . $label,
                target: str_starts_with($code, 'storage.schema.') ? 'storage.schema:all' : 'storage.record:all',
                requiredGrant: $grant,
                risk: $risk,
                auditDenials: true,
            )) || $registered;
        }

        return $registered;
    }
}
