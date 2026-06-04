<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Enums\StorageDecisionStatus;

interface StorageRuntime
{
    public function decideQuery(StorageQuery $query): StorageDecisionStatus;

    public function decideMutation(StorageMutation $mutation, StorageValidationReport $validation): StorageDecisionStatus;
}
