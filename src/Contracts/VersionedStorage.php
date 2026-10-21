<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Illuminate\Database\ConnectionInterface;

interface VersionedStorage
{
    /** The most saved versions of a record a «keep» setting may ask for. */
    public const HISTORY_KEEP_LIMIT = 1000;

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
     * @param array<string, mixed> $values
     */
    public function transition(
        string $ownerRef,
        StorageRecordVersionRef $expected,
        StorageSchemaVersionRef $schema,
        array $values,
        string $operation,
        string $actor,
        ?string $correlationId = null,
    ): StorageWriteResult;

    /**
     * Writes one record a SitePack import brings (larena-specs docs/architecture/2026-10-22-storage-sitepack.md) under
     * an explicit record id: its versions, oldest first, become revisions 1..n under $schema, the last one current.
     * Every version is normalized as any write; the first is a `create`, the others `update`, and the last a `delete`
     * when $archived says the record arrives archived. No витрина row is written (the import rebuilds the витрина when
     * it ends) and no per-record audit event is emitted (the import's own event covers the run). Needs
     * `storage.record.create`. Refused with storage_record_import_invalid (no versions, a bad id),
     * storage_record_schema_not_current, or StorageConflict storage_record_import_id_taken.
     *
     * @param list<array<string, mixed>> $versions the values of each version, oldest first
     */
    public function importRecord(
        string $ownerRef,
        StorageSchemaVersionRef $schema,
        string $recordId,
        array $versions,
        bool $archived,
        string $actor,
        ?string $correlationId = null,
    ): StorageWriteResult;

    /**
     * Removes records permanently: every version, the head, localized values,
     * publication rows and relation edges. Each ref must name the current
     * revision of one record of one schema. Refused while a record outside the
     * set references a record inside it. One audit event per record.
     *
     * @param array<array-key, mixed> $current a list of StorageRecordVersionRef, validated
     * @return int record versions removed
     */
    public function purge(array $current, string $actor, ?string $correlationId = null): int;

    /**
     * Removes the oldest saved versions of these records of one schema so that each keeps
     * its newest $keep. The current revision, and any revision a publication state or its
     * log names, are never removed; their localized values go with the removed versions.
     * One audit event when anything was removed.
     *
     * @param list<string> $recordIds
     * @return int record versions removed
     */
    public function pruneHistory(string $schemaId, array $recordIds, int $keep, string $actor, ?string $correlationId = null): int;

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
     * Lists public projections of current record heads after Access has resolved
     * the caller's exact query scope. Missing or invalid scope fails closed.
     */
    public function listCurrentRecords(
        StorageRecordListQuery $query,
        string $actor,
    ): StorageRecordListPage;

    /**
     * A locking projection is effective only inside an ambient database transaction.
     */
    public function projectPublicVersion(
        StorageRecordVersionRef $ref,
        bool $forUpdate = false,
    ): StoragePublicProjection;

    public function connection(): ConnectionInterface;
}
