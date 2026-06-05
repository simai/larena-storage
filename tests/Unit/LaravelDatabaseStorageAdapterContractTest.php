<?php

declare(strict_types=1);

use Larena\Storage\Contracts\StoragePersistenceProfile;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\MutationType;
use Larena\Storage\Enums\StorageDecisionStatus;
use Larena\Storage\Runtime\ArrayStorageMutation;
use Larena\Storage\Runtime\ArrayStorageQuery;
use Larena\Storage\Runtime\ArrayStorageSchema;
use Larena\Storage\Runtime\InMemoryStorageRuntime;
use Larena\Storage\Runtime\LaravelDatabaseStorageAdapter;

require_once __DIR__ . '/../../vendor/autoload.php';

$adapter = new LaravelDatabaseStorageAdapter(new InMemoryStorageRuntime());
$baseline = LaravelDatabaseStorageAdapter::baselineProfile();
if (!$adapter->supports($baseline) || !$baseline->isBaseline() || $baseline->driver() !== 'laravel_database') {
    fwrite(STDERR, "Laravel database baseline profile must be supported.\n");
    exit(1);
}

$unsupported = new readonly class implements StoragePersistenceProfile {
    public function id(): string
    {
        return 'external_s3';
    }

    public function driver(): string
    {
        return 's3';
    }

    public function isBaseline(): bool
    {
        return false;
    }

    public function options(): array
    {
        return [];
    }
};
if ($adapter->supports($unsupported)) {
    fwrite(STDERR, "Advanced persistence profiles must not be supported by baseline adapter.\n");
    exit(1);
}

$adapter->registerSchema(new ArrayStorageSchema('articles', '1.0.0', 'access.storage.articles', $baseline->id(), [
    ['name' => 'title', 'type' => 'string', 'required' => true, 'visibility' => FieldVisibility::Public->value],
]));
$decision = $adapter->mutate(new ArrayStorageMutation('articles', 'a-1', MutationType::Create, 'scope:articles', [
    'title' => 'Hello',
]));
if ($decision !== StorageDecisionStatus::Allowed) {
    fwrite(STDERR, "Baseline adapter must delegate allowed storage mutation.\n");
    exit(1);
}

$records = $adapter->records(new ArrayStorageQuery('articles', 'scope:articles'));
if (count($records) !== 1 || $records[0]->id() !== 'a-1') {
    fwrite(STDERR, "Baseline adapter must expose persisted records through the storage contract.\n");
    exit(1);
}

echo "LaravelDatabaseStorageAdapterContractTest passed.\n";
