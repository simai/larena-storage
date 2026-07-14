<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Larena\Storage\Contracts\StorageSchemaEvolutionOwnerContext;
use Larena\Storage\SchemaEvolution\StorageSchemaEvolutionOwnerPolicyRegistry;

require_once __DIR__ . '/../../vendor/autoload.php';

final class StorageOwnerPolicyOrderState
{
    public int $registrations = 0;

    /** @var WeakMap<StorageSchemaEvolutionOwnerPolicyRegistry, bool> */
    private WeakMap $installedRegistries;

    public function __construct()
    {
        $this->installedRegistries = new WeakMap();
    }

    public function isInstalled(StorageSchemaEvolutionOwnerPolicyRegistry $registry): bool
    {
        return isset($this->installedRegistries[$registry]);
    }

    public function markInstalled(StorageSchemaEvolutionOwnerPolicyRegistry $registry): void
    {
        $this->installedRegistries[$registry] = true;
        $this->registrations++;
    }
}

function storageOwnerPolicyOrderExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function storageOwnerPolicyOrderInstallStorage(Container $container): void
{
    $container->singleton(
        StorageSchemaEvolutionOwnerPolicyRegistry::class,
        static fn (): StorageSchemaEvolutionOwnerPolicyRegistry => new StorageSchemaEvolutionOwnerPolicyRegistry(),
    );
}

function storageOwnerPolicyOrderInstallConsumer(Container $container, StorageOwnerPolicyOrderState $state): void
{
    $install = static function (StorageSchemaEvolutionOwnerPolicyRegistry $registry) use ($state): void {
        if ($state->isInstalled($registry)) {
            return;
        }
        $registry->protect(
            'larena/provider-order-test',
            static function (StorageSchemaEvolutionOwnerContext $context, ?object $capability): void {},
            'provider.order.',
        );
        $state->markInstalled($registry);
    };

    $container->afterResolving(StorageSchemaEvolutionOwnerPolicyRegistry::class, $install);
    if ($container->resolved(StorageSchemaEvolutionOwnerPolicyRegistry::class)) {
        $install($container->make(StorageSchemaEvolutionOwnerPolicyRegistry::class));
    }
}

foreach ([
    'storage_first',
    'consumer_first',
    'storage_resolved_before_consumer_register',
    'consumer_resolves_transient_before_storage_binding',
] as $order) {
    $container = new Container();
    $state = new StorageOwnerPolicyOrderState();
    if ($order === 'storage_first') {
        storageOwnerPolicyOrderInstallStorage($container);
        storageOwnerPolicyOrderInstallConsumer($container, $state);
    } elseif ($order === 'storage_resolved_before_consumer_register') {
        storageOwnerPolicyOrderInstallStorage($container);
        $container->make(StorageSchemaEvolutionOwnerPolicyRegistry::class);
        storageOwnerPolicyOrderInstallConsumer($container, $state);
    } elseif ($order === 'consumer_resolves_transient_before_storage_binding') {
        storageOwnerPolicyOrderInstallConsumer($container, $state);
        $transientRegistry = $container->make(StorageSchemaEvolutionOwnerPolicyRegistry::class);
        storageOwnerPolicyOrderExpect($state->isInstalled($transientRegistry), $order . ' transient registry was not protected');
        storageOwnerPolicyOrderInstallStorage($container);
    } else {
        storageOwnerPolicyOrderInstallConsumer($container, $state);
        storageOwnerPolicyOrderInstallStorage($container);
    }

    $registry = $container->make(StorageSchemaEvolutionOwnerPolicyRegistry::class);
    $registry->seal();
    storageOwnerPolicyOrderExpect($registry->isSealed(), $order . ' registry was not sealed at boot boundary');
    storageOwnerPolicyOrderExpect($state->isInstalled($registry), $order . ' consumer protection was not installed on the sealed registry');
    $expectedRegistrations = $order === 'consumer_resolves_transient_before_storage_binding' ? 2 : 1;
    storageOwnerPolicyOrderExpect($state->registrations === $expectedRegistrations, $order . ' protection registration count mismatch');
    storageOwnerPolicyOrderExpect(
        $container->make(StorageSchemaEvolutionOwnerPolicyRegistry::class) === $registry,
        $order . ' registry is not container-local singleton',
    );
}

echo "StorageSchemaEvolutionOwnerPolicyProviderOrderTest passed.\n";
