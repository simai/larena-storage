<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Runtime;

use Larena\Storage\Admin\Contracts\StorageWorkbenchGateway;

final readonly class UnavailableStorageWorkbenchGateway implements StorageWorkbenchGateway
{
    public function snapshot(string $actor, ?string $requestedScope, array $query = []): array
    {
        return [
            'status' => 'unavailable',
            'reason_code' => 'storage_workbench_gateway_unavailable',
            'scope_ref' => null,
            'structures' => [],
            'selected_structure' => null,
            'records' => ['items' => [], 'continuation' => null, 'matched_count' => 0],
            'files' => ['nodes' => []],
            'documents' => [],
            'pages' => [],
            'publication' => null,
            'permissions' => [],
            'limits' => [],
        ];
    }

    public function dispatch(
        string $actor,
        ?string $requestedScope,
        string $action,
        array $payload,
    ): array {
        return [
            'status' => 'unavailable',
            'reason_code' => 'storage_workbench_gateway_unavailable',
            'scope_ref' => null,
            'notice' => 'storage_workbench_gateway_unavailable',
            'redirect' => [],
        ];
    }
}
