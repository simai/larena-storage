<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use InvalidArgumentException;

final readonly class StorageSecurityEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $stream,
        public string $type,
        public string $actor,
        public string $subject,
        public string $correlationId,
        public array $payload,
    ) {
        if (!in_array($stream, ['version', 'schema_migration'], true)) {
            throw new InvalidArgumentException('storage_security_event_stream_invalid');
        }
    }
}
