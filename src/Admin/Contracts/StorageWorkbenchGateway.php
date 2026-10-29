<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Contracts;

interface StorageWorkbenchGateway
{
    /**
     * Returns a bounded, presentation-safe workbench state. Implementations own
     * no persistence and must authorize actor + requested scope before issuing
     * any owner projection.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function snapshot(string $actor, ?string $requestedScope, array $query = []): array;

    /**
     * Dispatches one named generic owner operation and returns only a bounded
     * public receipt. Raw exceptions and owner-private metadata are forbidden.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatch(
        string $actor,
        ?string $requestedScope,
        string $action,
        array $payload,
    ): array;
}
