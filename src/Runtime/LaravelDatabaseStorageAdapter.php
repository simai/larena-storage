<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StorageMutation;
use Larena\Storage\Contracts\StoragePersistenceAdapter;
use Larena\Storage\Contracts\StoragePersistenceProfile;
use Larena\Storage\Contracts\StorageQuery;
use Larena\Storage\Contracts\StorageRecord;
use Larena\Storage\Contracts\StorageSchema;
use Larena\Storage\Contracts\StorageValidationReport;
use Larena\Storage\Enums\StorageDecisionStatus;

final readonly class LaravelDatabaseStorageAdapter implements StoragePersistenceAdapter
{
    public const PROFILE_ID = 'laravel_database_default';

    public function __construct(
        private InMemoryStorageRuntime $runtime,
        private StorageTransactionBoundary $transactionBoundary = new StorageTransactionBoundary(),
    ) {
    }

    public static function baselineProfile(): StoragePersistenceProfile
    {
        return new readonly class implements StoragePersistenceProfile {
            public function id(): string
            {
                return LaravelDatabaseStorageAdapter::PROFILE_ID;
            }

            public function driver(): string
            {
                return 'laravel_database';
            }

            public function isBaseline(): bool
            {
                return true;
            }

            public function options(): array
            {
                return [
                    'requires_external_infrastructure' => false,
                    'schema_migrations_managed_here' => false,
                ];
            }
        };
    }

    public function supports(StoragePersistenceProfile $profile): bool
    {
        return $profile->id() === self::PROFILE_ID
            && $profile->driver() === 'laravel_database'
            && $profile->isBaseline();
    }

    public function registerSchema(StorageSchema $schema): StorageValidationReport
    {
        return $this->runtime->registerSchema($schema);
    }

    /**
     * @return list<StorageRecord>
     */
    public function records(StorageQuery $query): array
    {
        return $this->runtime->records($query);
    }

    public function validateMutation(StorageMutation $mutation): StorageValidationReport
    {
        return $this->runtime->validateMutation($mutation);
    }

    public function decideMutation(StorageMutation $mutation, StorageValidationReport $validation): StorageDecisionStatus
    {
        return $this->runtime->decideMutation($mutation, $validation);
    }

    public function mutate(StorageMutation $mutation): StorageDecisionStatus
    {
        return $this->transactionBoundary->run(fn (): StorageDecisionStatus => $this->runtime->mutate($mutation));
    }
}
