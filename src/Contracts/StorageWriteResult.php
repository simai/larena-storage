<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageWriteResult
{
    public function __construct(public StorageRecordVersion $version)
    {
    }

    public function ref(): StorageRecordVersionRef
    {
        return $this->version->ref;
    }

    public function receipt(): StorageMutationReceipt
    {
        return new StorageMutationReceipt(
            $this->version->createdBy,
            $this->version->createdAt,
            $this->version->operation,
            'storage.record:' . $this->version->ref->recordId,
            $this->version->ref->revision,
            'succeeded',
            $this->version->correlationId,
        );
    }
}
