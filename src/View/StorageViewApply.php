<?php

declare(strict_types=1);

namespace Larena\Storage\View;

/** Writes the records a schema apply rewrites, with the target layout, in its transaction. */
final readonly class StorageViewApply
{
    public function __construct(
        private StorageViewProjector $projector,
        private StorageViewWriter $writer,
        public string $table,
        public StorageViewLayout $layout,
        private bool $hasMulti,
    ) {
    }

    /** @param array<string, mixed> $values */
    public function write(
        string $recordId,
        int $revision,
        string $contentHash,
        array $values,
        string $createdAt,
        string $updatedAt,
    ): void {
        $this->writer->upsert($this->table, [$this->projector->project(
            $this->layout,
            $recordId,
            $revision,
            $this->layout->schemaVersion,
            $contentHash,
            $values,
            $createdAt,
            $updatedAt,
        )], $this->hasMulti);
    }
}
