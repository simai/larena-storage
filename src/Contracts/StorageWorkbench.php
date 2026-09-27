<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface StorageWorkbench
{
    /** @return array<string, list<string>> Executable operators by Property field type. */
    public function filterOperators(): array;

    /** @param array<string, mixed> $descriptor */
    public function createStructure(
        string $scopeRef,
        array $descriptor,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure;

    /** @param array<string, mixed> $descriptor */
    public function updateStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        array $descriptor,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure;

    /**
     * Rewrites every record to a changed structure: removed fields lose their
     * values, retyped fields are converted by fixed rules, fields may become
     * required. All or nothing; refused while any record cannot be converted.
     *
     * @param array<string, mixed> $descriptor
     */
    public function migrateStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        array $descriptor,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure;

    public function readStructure(string $scopeRef, string $structureId, string $actor): StorageWorkbenchStructure;

    /** @return list<StorageWorkbenchStructure> Active structures; archived ones only when asked for. */
    public function listStructures(string $scopeRef, string $actor, bool $includeArchived = false): array;

    /** An archived structure keeps its records and accepts no record writes until it is restored. */
    public function archiveStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure;

    public function restoreStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchStructure;

    /**
     * Removes an archived structure permanently with all of its records. Refused
     * while a record of another structure references one of its records or a
     * structure role is bound to it. The structure id cannot be used again.
     */
    public function purgeStructure(
        string $scopeRef,
        string $structureId,
        int $expectedVersion,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchPurgeReceipt;

    /** @param array<string, mixed> $values */
    public function createRecord(
        string $scopeRef,
        string $structureId,
        array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord;

    /** @param array<string, mixed> $values */
    public function updateRecord(
        string $scopeRef,
        string $structureId,
        string $recordId,
        int $expectedRevision,
        array $values,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord;

    public function archiveRecord(
        string $scopeRef,
        string $structureId,
        string $recordId,
        int $expectedRevision,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord;

    public function restoreRecord(
        string $scopeRef,
        string $structureId,
        string $recordId,
        int $expectedRevision,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchRecord;

    /**
     * @param array<array-key, mixed> $expectedRevisions record id => expected revision
     * @return list<StorageWorkbenchRecord>
     */
    public function bulkArchive(
        string $scopeRef,
        string $structureId,
        array $expectedRevisions,
        string $actor,
        ?string $correlationId = null,
    ): array;

    /**
     * Removes archived records permanently, one or many in one transaction.
     *
     * @param array<array-key, mixed> $expectedRevisions record id => expected revision
     */
    public function purgeRecords(
        string $scopeRef,
        string $structureId,
        array $expectedRevisions,
        string $actor,
        ?string $correlationId = null,
    ): StorageWorkbenchPurgeReceipt;

    public function readRecord(
        string $scopeRef,
        string $structureId,
        string $recordId,
        string $actor,
    ): StorageWorkbenchRecord;

    public function listRecords(StorageWorkbenchRecordQuery $query, string $actor): StorageWorkbenchRecordPage;

    /** @return list<StorageWorkbenchRecord> */
    public function recordHistory(
        string $scopeRef,
        string $structureId,
        string $recordId,
        string $actor,
        int $limit = 50,
    ): array;
}
