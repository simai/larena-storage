<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

interface StorageWorkbench
{
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

    public function readStructure(string $scopeRef, string $structureId, string $actor): StorageWorkbenchStructure;

    /** @return list<StorageWorkbenchStructure> */
    public function listStructures(string $scopeRef, string $actor): array;

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
