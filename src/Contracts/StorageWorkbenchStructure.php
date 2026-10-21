<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

final readonly class StorageWorkbenchStructure
{
    /**
     * @param list<array<string, mixed>> $fields
     * @param list<string> $viewPendingFields
     */
    public function __construct(
        public string $structureId,
        public string $scopeRef,
        public int $version,
        public StorageSchemaVersionRef $schema,
        public string $label,
        public array $fields,
        public string $descriptorHash,
        public string $updatedAt,
        public string $operation = 'create',
        public string $actor = '',
        public ?string $correlationId = null,
        public ?string $archivedAt = null,
        public ?string $archivedBy = null,
        /** How many saved versions of each record are kept; null keeps them all. */
        public ?int $historyKeep = null,
        // The state of the structure's витрина (its typed list table): ready, building, pending or
        // failed; null without one (lists then read the versions).
        public ?string $viewState = null,
        // True while a ready витрина still fills rows written before a change (a new column, the
        // search text): lists answer meanwhile, from the old column where there is one.
        public bool $viewBackfillPending = false,
        // The fields whose витрина column is still being filled, by a backfill or a field change.
        public array $viewPendingFields = [],
        // The field change running in the background, or the last one that failed or was cancelled
        // after the structure's current version; null when there is none.
        public ?StorageWorkbenchFieldChange $fieldChange = null,
    ) {
    }

    /** The same structure with the state of its витрина and of its field change. @param list<string> $viewPendingFields */
    public function withState(?string $viewState, bool $viewBackfillPending, array $viewPendingFields, ?StorageWorkbenchFieldChange $fieldChange): self
    {
        return new self(
            $this->structureId,
            $this->scopeRef,
            $this->version,
            $this->schema,
            $this->label,
            $this->fields,
            $this->descriptorHash,
            $this->updatedAt,
            $this->operation,
            $this->actor,
            $this->correlationId,
            $this->archivedAt,
            $this->archivedBy,
            $this->historyKeep,
            $viewState,
            $viewBackfillPending,
            $viewPendingFields,
            $fieldChange,
        );
    }

    /** True while a field change runs in the background: the structure accepts no other change. */
    public function changing(): bool
    {
        return $this->fieldChange !== null && $this->fieldChange->running();
    }

    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function receipt(): StorageMutationReceipt
    {
        return new StorageMutationReceipt(
            $this->actor,
            $this->updatedAt,
            $this->operation,
            'storage.structure:' . $this->structureId,
            $this->version,
            'succeeded',
            $this->correlationId,
        );
    }
}
