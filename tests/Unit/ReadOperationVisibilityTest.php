<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/read-contract-schema.php';

use Larena\Core\Contracts\OperationContext;
use Larena\Storage\Contracts\PublishedReadVisibility;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\ReadContractOperationHandlers;

$connection = larena_storage_read_contract_connection();
larena_storage_define_site_node_schema($connection);

$publication = new DatabasePublicationLifecycle($connection);
$read = new DatabaseReadContracts($connection, new DatabaseLocalizedValues($connection));

foreach (['home', 'members'] as $id) {
    larena_storage_write_record_version($connection, $id, 1, [
        'slug' => $id,
        'title' => ucfirst($id),
        'order_index' => 0,
        'target' => '/' . $id,
    ]);
    $publication->publish('site.pages', $id, 'site:main', 'en', 1, 'actor:editor');
}

// A composition that hides "members" from everyone but one actor.
$visibility = new class implements PublishedReadVisibility {
    /** @var list<array{string, string, string}> */
    public array $asked = [];

    public function filterFor(string $actor, string $roleRefOrSchemaId, string $scopeRef): ?Closure
    {
        $this->asked[] = [$actor, $roleRefOrSchemaId, $scopeRef];

        return $actor === 'actor:member' ? null : static fn (string $recordId): bool => $recordId !== 'members';
    }
};
$handlers = new ReadContractOperationHandlers($read, $visibility);
$descriptors = ReadContractOperationHandlers::descriptors();
$call = static function (string $operation, string $actor, array $input) use ($handlers, $descriptors): array {
    return $handlers->handle($descriptors[$operation], new OperationContext(
        actorId: $actor,
        correlationId: 'corr-visibility',
        metadata: ['target' => 'site.pages', 'scope_ref' => 'site:main', 'locale' => 'en'] + $input,
    ));
};

// The projection leaves the hidden record out for a visitor and keeps it for the member.
$visitor = $call('storage.read.published_projection', 'actor:visitor', []);
larena_storage_role_assert(array_column($visitor['records'], 'record_id') === ['home'], 'a visitor reads only home');
larena_storage_role_assert($visitor['filtered_count'] === 1, 'the projection counts the hidden record');
$member = $call('storage.read.published_projection', 'actor:member', []);
larena_storage_role_assert(array_column($member['records'], 'record_id') === ['home', 'members'], 'the member reads both');

// Resolving the hidden record's key is the same miss as an absent one.
$hidden = $call('storage.read.resolve_key', 'actor:visitor', ['key_field' => 'slug', 'key_value' => 'members']);
larena_storage_role_assert($hidden === ['resolved' => null], 'a hidden record does not resolve');
$shown = $call('storage.read.resolve_key', 'actor:member', ['key_field' => 'slug', 'key_value' => 'members']);
larena_storage_role_assert(($shown['resolved']['record_id'] ?? null) === 'members', 'the member resolves it');

// Diagnostics count what the caller may not read and never name it.
$explained = $call('storage.read.projection_explain', 'actor:visitor', []);
larena_storage_role_assert($explained['published_record_count'] === 1);
larena_storage_role_assert($explained['filtered_record_count'] === 1);
larena_storage_role_assert(!str_contains((string) json_encode($explained), 'members'), 'explain names no hidden record');

// Every operation asked with the caller's own actor.
larena_storage_role_assert(in_array(['actor:visitor', 'site.pages', 'site:main'], $visibility->asked, true));

// The default composition reads every published record.
$open = new ReadContractOperationHandlers($read);
$all = $open->handle($descriptors['storage.read.published_projection'], new OperationContext(
    actorId: 'actor:visitor',
    correlationId: 'corr-default',
    metadata: ['target' => 'site.pages', 'scope_ref' => 'site:main', 'locale' => 'en'],
));
larena_storage_role_assert(count($all['records']) === 2, 'without a rule every published record is public');

echo "Read operation visibility passed.\n";
