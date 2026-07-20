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

    /**
     * A locking read is effective only inside an ambient database transaction.
     */
    public function schemaVersion(
        StorageSchemaVersionRef $ref,
        bool $forUpdate = false,
    ): StorageSchemaVersion;

    /**
     * A locking read is effective only inside an ambient database transaction.
     */
    public function readAdminVersion(
        StorageRecordVersionRef $ref,
        string $actor,
        bool $forUpdate = false,
    ): StorageRecordVersion;

    /**
     * A locking read is effective only inside an ambient database transaction.
     */
    public function readAdminCurrentVersion(
        string $schemaId,
        string $ownerRef,
        string $actor,
        bool $forUpdate = false,
    ): ?StorageRecordVersion;

    /**
     * A locking projection is effective only inside an ambient database transaction.
     */
    public function projectPublicVersion(
        StorageRecordVersionRef $ref,
        bool $forUpdate = false,
    ): StoragePublicProjection;

    public function connection(): ConnectionInterface;
}
