<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Illuminate\Database\ConnectionInterface;

interface VersionedStorage
{
    /**
     * @param array<string, mixed> $definition
     */
    public function registerSchemaVersion(
        array $definition,
        ?int $expectedHeadVersion,
        string $actor,
        ?string $correlationId = null,
    ): StorageSchemaVersion;

    /**
     * @param array<string, mixed> $values
     */
    public function create(
        string $ownerRef,
        StorageSchemaVersionRef $schema,
        array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWriteResult;

    /**
     * @param array<string, mixed> $values
     */
    public function compareAndSwap(
        string $ownerRef,
        StorageRecordVersionRef $expected,
        StorageSchemaVersionRef $schema,
        array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWriteResult;

    public function schemaVersion(StorageSchemaVersionRef $ref): StorageSchemaVersion;

    public function readAdminVersion(StorageRecordVersionRef $ref, string $actor): StorageRecordVersion;

    public function readAdminCurrentVersion(
        string $schemaId,
        string $ownerRef,
        string $actor,
    ): ?StorageRecordVersion;

    public function projectPublicVersion(StorageRecordVersionRef $ref): StoragePublicProjection;

    public function connection(): ConnectionInterface;
}
