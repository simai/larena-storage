<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Larena\Storage\Contracts\StorageWorkbenchFieldChange;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * One row of `larena_storage_field_changes`: a field change of a workbench
 * structure that is checked and converted record by record before the structure
 * switches to its target.
 */
final readonly class StorageFieldChangeRow
{
    /**
     * @param array{schema_id: string, owner_package: string, fields: list<array<string, mixed>>} $targetDefinition
     * @param array{structure_id: string, label: string, fields: list<array<string, mixed>>} $descriptor
     * @param list<string> $fields The keys whose values the change converts or checks.
     */
    public function __construct(
        public string $changeRef,
        public string $schemaId,
        public string $scopeRef,
        public string $structureId,
        public int $sourceSchemaVersion,
        public int $expectedStructureVersion,
        public array $targetDefinition,
        public string $targetHash,
        public array $descriptor,
        public array $fields,
        public string $state,
        public ?string $cursor,
        public ?string $runRef,
        public int $checked,
        public int $total,
        public ?string $reason,
        public string $createdBy,
        public ?string $correlationId,
        public string $startedAt,
        public string $updatedAt,
        public ?string $finishedAt,
    ) {
        if (preg_match('/\Astorage-field-change-[0-9a-f]{32}\z/D', $changeRef) !== 1
            || !in_array($state, StorageWorkbenchFieldChange::STATES, true)) {
            throw new StorageRejected('storage_field_change_invalid');
        }
    }

    public function running(): bool
    {
        return $this->state === StorageWorkbenchFieldChange::STATE_RUNNING;
    }

    public function withRun(?string $runRef): self
    {
        return $this->copy(['run_ref' => $runRef]);
    }

    public function withProgress(?string $cursor, int $checked, string $now): self
    {
        return $this->copy(['cursor' => $cursor, 'checked' => $checked, 'updated_at' => $now]);
    }

    public function ended(string $state, ?string $reason, string $now): self
    {
        return $this->copy(['state' => $state, 'reason' => $reason, 'updated_at' => $now, 'finished_at' => $now, 'run_ref' => null]);
    }

    public function contract(): StorageWorkbenchFieldChange
    {
        return new StorageWorkbenchFieldChange(
            $this->changeRef,
            $this->state,
            $this->checked,
            $this->total,
            $this->fields,
            $this->reason,
            $this->startedAt,
            $this->finishedAt,
        );
    }

    /** @param array{state?: string, cursor?: ?string, run_ref?: ?string, checked?: int, reason?: ?string, updated_at?: string, finished_at?: ?string} $changes */
    private function copy(array $changes): self
    {
        return new self(
            $this->changeRef,
            $this->schemaId,
            $this->scopeRef,
            $this->structureId,
            $this->sourceSchemaVersion,
            $this->expectedStructureVersion,
            $this->targetDefinition,
            $this->targetHash,
            $this->descriptor,
            $this->fields,
            $changes['state'] ?? $this->state,
            array_key_exists('cursor', $changes) ? $changes['cursor'] : $this->cursor,
            array_key_exists('run_ref', $changes) ? $changes['run_ref'] : $this->runRef,
            $changes['checked'] ?? $this->checked,
            $this->total,
            array_key_exists('reason', $changes) ? $changes['reason'] : $this->reason,
            $this->createdBy,
            $this->correlationId,
            $this->startedAt,
            $changes['updated_at'] ?? $this->updatedAt,
            array_key_exists('finished_at', $changes) ? $changes['finished_at'] : $this->finishedAt,
        );
    }
}
