<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Larena\Storage\Admin\Http\Controllers\StorageWorkbenchController;
use Larena\Storage\Admin\Runtime\UnavailableStorageWorkbenchGateway;
use Illuminate\Http\Request;

function storageWorkbenchGatewayExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$gateway = new UnavailableStorageWorkbenchGateway();
$snapshot = $gateway->snapshot('actor:admin:alpha', 'scope:tenant-alpha');
storageWorkbenchGatewayExpect(($snapshot['status'] ?? null) === 'unavailable', 'unavailable snapshot status mismatch');
storageWorkbenchGatewayExpect(($snapshot['structures'] ?? null) === [], 'unavailable snapshot exposed structures');
$receipt = $gateway->dispatch('actor:admin:alpha', 'scope:tenant-alpha', 'record.create', []);
storageWorkbenchGatewayExpect(($receipt['status'] ?? null) === 'unavailable', 'unavailable receipt status mismatch');

$reflection = new ReflectionClass(StorageWorkbenchController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$query = $reflection->getMethod('query');
$emptySearch = $query->invoke($controller, Request::create('/admin/storage-workbench', 'GET', [
    'search' => '', 'sort_direction' => 'asc',
]));
storageWorkbenchGatewayExpect(!isset($emptySearch['sort_direction']), 'direction without sort field escaped');
$sortedSearch = $query->invoke($controller, Request::create('/admin/storage-workbench', 'GET', [
    'sort_field' => 'title', 'sort_direction' => 'desc',
]));
storageWorkbenchGatewayExpect(($sortedSearch['sort_direction'] ?? null) === 'desc', 'valid sort direction was removed');
$safeState = $reflection->getMethod('safeState');
$base = [
    'status' => 'ok',
    'reason_code' => 'ok',
    'scope_ref' => 'scope:tenant-alpha',
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
$safe = $safeState->invoke($controller, $base);
storageWorkbenchGatewayExpect(($safe['status'] ?? null) === 'ok', 'safe projection rejected');

$typedProjection = ['leaf' => 'safe'];
for ($depth = 0; $depth < 18; $depth++) {
    $typedProjection = ['node' => $typedProjection];
}
$nested = $base;
$nested['pages'][] = ['preview' => ['regions' => [$typedProjection]]];
$nestedResult = $safeState->invoke($controller, $nested);
storageWorkbenchGatewayExpect(($nestedResult['status'] ?? null) === 'ok', 'bounded typed projection rejected');

$tooDeepProjection = ['leaf' => 'safe'];
for ($depth = 0; $depth < 30; $depth++) {
    $tooDeepProjection = ['node' => $tooDeepProjection];
}
$tooDeep = $base;
$tooDeep['pages'][] = ['preview' => ['regions' => [$tooDeepProjection]]];
$tooDeepResult = $safeState->invoke($controller, $tooDeep);
storageWorkbenchGatewayExpect(($tooDeepResult['status'] ?? null) === 'unavailable', 'deep projection escaped');

$fanout = $base;
$fanout['records']['items'] = array_fill(0, 2500, ['left' => 1, 'right' => 2]);
$fanoutResult = $safeState->invoke($controller, $fanout);
storageWorkbenchGatewayExpect(($fanoutResult['status'] ?? null) === 'unavailable', 'aggregate projection fanout escaped');

$private = $base;
$private['records']['items'][] = ['storage_key' => 'PRIVATE_STORAGE_KEY'];
$privateResult = $safeState->invoke($controller, $private);
storageWorkbenchGatewayExpect(($privateResult['status'] ?? null) === 'unavailable', 'private storage key escaped');
storageWorkbenchGatewayExpect(!str_contains(json_encode($privateResult, JSON_THROW_ON_ERROR), 'PRIVATE_STORAGE_KEY'), 'private value leaked through rejection');

$previous = $base;
$previous['records']['items'][] = ['previous' => ['message' => 'PRIVATE_EXCEPTION_MESSAGE']];
$previousResult = $safeState->invoke($controller, $previous);
storageWorkbenchGatewayExpect(($previousResult['status'] ?? null) === 'unavailable', 'previous exception chain escaped');
storageWorkbenchGatewayExpect(!str_contains(json_encode($previousResult, JSON_THROW_ON_ERROR), 'PRIVATE_EXCEPTION_MESSAGE'), 'previous message leaked');

$oversized = $base;
$oversized['records']['items'][] = ['value' => str_repeat('x', 2097153)];
$oversizedResult = $safeState->invoke($controller, $oversized);
storageWorkbenchGatewayExpect(($oversizedResult['status'] ?? null) === 'unavailable', 'oversized projection escaped');

echo "StorageWorkbenchGatewayTest passed.\n";
