<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Enums\StorageDecisionStatus;

interface StoragePersistenceAdapter
{
    public function supports(StoragePersistenceProfile $profile): bool;

    public function registerSchema(StorageSchema $schema): StorageValidationReport;

    /**
     * @return list<StorageRecord>
     */
    public function records(StorageQuery $query): array;

    public function validateMutation(StorageMutation $mutation): StorageValidationReport;

    public function decideMutation(StorageMutation $mutation, StorageValidationReport $validation): StorageDecisionStatus;

    public function mutate(StorageMutation $mutation): StorageDecisionStatus;
}
