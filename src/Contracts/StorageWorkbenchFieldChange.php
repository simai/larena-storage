<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

/**
 * A field change of a structure that needs every record checked or converted
 * (a retype, a field made required, changed constraints), run in the background
 * as the `storage.schema.migrate` queue job. While it runs the structure keeps
 * its fields, lists answer from the витрина's old columns and records can be
 * written; it switches the structure only once every record converts.
 */
final readonly class StorageWorkbenchFieldChange
{
    public const STATE_RUNNING = 'running';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';
    public const STATES = [self::STATE_RUNNING, self::STATE_DONE, self::STATE_FAILED, self::STATE_CANCELLED];

    /** @param list<string> $fields The fields whose values it converts or checks. */
    public function __construct(
        public string $changeRef,
        public string $state,
        // Records checked so far, and how many the structure had when the change started.
        public int $checked,
        public int $total,
        public array $fields,
        // Why it failed (a reason code), or null.
        public ?string $reason,
        public string $startedAt,
        public ?string $finishedAt = null,
    ) {
    }

    public function running(): bool
    {
        return $this->state === self::STATE_RUNNING;
    }
}
