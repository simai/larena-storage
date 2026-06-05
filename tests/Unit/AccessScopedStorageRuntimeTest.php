<?php

declare(strict_types=1);

use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Access\ValueObjects\AccessDecision;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\MutationType;
use Larena\Storage\Enums\StorageDecisionStatus;
use Larena\Storage\Runtime\AccessScopedStorageRuntime;
use Larena\Storage\Runtime\ArrayStorageMutation;
use Larena\Storage\Runtime\ArrayStorageQuery;
use Larena\Storage\Runtime\ArrayStorageSchema;
use Larena\Storage\Runtime\InMemoryStorageRuntime;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../../access/src/Enums/AccessDecisionStatus.php';
require_once __DIR__ . '/../../../access/src/ValueObjects/AccessDecision.php';
require_once __DIR__ . '/../../../access/src/Contracts/QueryScopeProvider.php';

final class TestStorageQueryScopeProvider implements QueryScopeProvider
{
    public function __construct(private readonly bool $allow, private readonly array $filters = [])
    {
    }

    public function supports(string $resourceType, string $operation): bool
    {
        return str_starts_with($resourceType, 'storage.record:') && in_array($operation, ['list', 'create', 'update', 'delete'], true);
    }

    public function scope(array $query, string $actor, string $operation, array $context = []): array
    {
        return [
            ...$query,
            'filters' => [
                ...($query['filters'] ?? []),
                ...$this->filters,
            ],
        ];
    }

    public function explain(string $resourceType, string $actor, string $operation, array $context = []): AccessDecision
    {
        return $this->allow
            ? AccessDecision::allow($operation, $actor, $resourceType, 'test_allowed')
            : AccessDecision::deny($operation, $actor, $resourceType, 'test_denied');
    }
}

$runtime = new InMemoryStorageRuntime();
$runtime->registerSchema(new ArrayStorageSchema(
    'articles',
    '1.0.0',
    'access.storage.articles',
    'laravel_database_default',
    [
        ['name' => 'title', 'type' => 'string', 'required' => true, 'visibility' => FieldVisibility::Public->value],
        ['name' => 'tenant', 'type' => 'string', 'required' => true, 'visibility' => FieldVisibility::Public->value],
    ],
));
$runtime->mutate(new ArrayStorageMutation('articles', 'a-1', MutationType::Create, 'scope:articles', [
    'title' => 'A',
    'tenant' => 'alpha',
]));
$runtime->mutate(new ArrayStorageMutation('articles', 'b-1', MutationType::Create, 'scope:articles', [
    'title' => 'B',
    'tenant' => 'beta',
]));

$missingProviderRuntime = new AccessScopedStorageRuntime($runtime, null);
$missingDecision = $missingProviderRuntime->decideQuery(new ArrayStorageQuery('articles', 'scope:articles'), 'actor-1');
if ($missingDecision !== StorageDecisionStatus::MissingAccessScope || $missingProviderRuntime->records(new ArrayStorageQuery('articles', 'scope:articles'), 'actor-1') !== []) {
    fwrite(STDERR, "Protected storage query without QueryScopeProvider must fail closed.\n");
    exit(1);
}

$scopedRuntime = new AccessScopedStorageRuntime($runtime, new TestStorageQueryScopeProvider(true, ['tenant' => 'alpha']));
$records = $scopedRuntime->records(new ArrayStorageQuery('articles', 'scope:articles'), 'actor-1');
if (count($records) !== 1 || $records[0]->id() !== 'a-1') {
    fwrite(STDERR, "QueryScopeProvider scope must be applied before records are returned.\n");
    exit(1);
}

$deniedRuntime = new AccessScopedStorageRuntime($runtime, new TestStorageQueryScopeProvider(false));
$deniedMutation = $deniedRuntime->mutate(new ArrayStorageMutation('articles', 'denied', MutationType::Create, 'scope:articles', [
    'title' => 'Denied',
    'tenant' => 'alpha',
]), 'actor-1');
if ($deniedMutation !== StorageDecisionStatus::Denied) {
    fwrite(STDERR, "Denied access decision must block storage mutation.\n");
    exit(1);
}

$afterDenied = $runtime->records(new ArrayStorageQuery('articles', 'scope:articles', ['title' => 'Denied']));
if ($afterDenied !== []) {
    fwrite(STDERR, "Denied mutation must not persist a record.\n");
    exit(1);
}

echo "AccessScopedStorageRuntimeTest passed.\n";
