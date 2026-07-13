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
}
