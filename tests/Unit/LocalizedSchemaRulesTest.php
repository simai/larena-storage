<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/localized-value-schema.php';

use Larena\Storage\Audit\LocalizedValueAuditEventCatalog;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Exceptions\LocalizedValueRejected;
use Larena\Storage\Runtime\DatabaseLocalizedValues;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;
use Larena\Property\Runtime\PropertyTypeRegistry;

/**
 * The schema version of a revision says which fields are localized, which are
 * required and whether partial locales are allowed; the caller's lists no longer
 * decide. The primary locale is the language of the shared values.
 */
$connection = larena_storage_localized_connection();
$define = static function (string $schemaId, bool $partial) use ($connection): void {
    $definition = (new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns()))->normalize([
        'schema_id' => $schemaId,
        'owner_package' => 'larena/storage',
        'fields' => [
            ['key' => 'slug', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => []],
            ['key' => 'title', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => [], 'localized' => true],
            ['key' => 'description', 'type' => 'text', 'type_version' => 1, 'required' => false, 'visibility' => 'public', 'constraints' => [], 'localized' => true],
        ],
    ] + ($partial ? ['partial_locales' => true] : []));
    $json = json_encode($definition, JSON_THROW_ON_ERROR);
    $connection->table('larena_storage_schema_versions')->insert([
        'schema_id' => $schemaId, 'version' => 1, 'definition' => $json, 'definition_hash' => hash('sha256', $json),
        'owner_package' => 'larena/storage', 'created_by' => 'actor:test', 'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    foreach (['doc-1', 'doc-2', 'doc-3'] as $recordId) {
        $connection->table('larena_storage_record_versions')->insert([
            'schema_id' => $schemaId, 'record_id' => $recordId, 'revision' => 1, 'owner_ref' => $recordId,
            'schema_version' => 1, 'values_json' => '{}', 'content_hash' => hash('sha256', '{}'),
            'operation' => 'create', 'created_by' => 'actor:test', 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
};
$define('site.docs', false);
$define('site.notes', true);

// The normalizer keeps the flags only when true: a definition without them is unchanged.
$normalizer = new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns());
$plain = ['schema_id' => 'site.plain', 'owner_package' => 'larena/storage', 'fields' => [
    ['key' => 'title', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => []],
]];
larena_storage_role_assert($normalizer->normalize($plain + ['partial_locales' => false]) === $normalizer->normalize($plain), 'false flags hash as absent');
larena_storage_role_assert(!array_key_exists('localized', $normalizer->normalize($plain)['fields'][0]));

$sink = new class implements StorageSecurityEventSink {
    /** @var list<StorageSecurityEvent> */
    public array $events = [];

    public bool $fail = false;

    public function emit(StorageSecurityEvent $event): void
    {
        if ($this->fail) {
            throw new RuntimeException('audit sink unavailable');
        }
        $this->events[] = $event;
    }
};
$values = new DatabaseLocalizedValues($connection, null, 'en', $sink);
$refused = static function (callable $write, string $reason): void {
    try {
        $write();
    } catch (LocalizedValueRejected $rejection) {
        larena_storage_role_assert($rejection->reasonCode === $reason, $rejection->reasonCode . ' instead of ' . $reason);

        return;
    }
    larena_storage_role_assert(false, 'expected ' . $reason);
};

// The schema, not the caller, says what is localized: naming slug does not make it so.
$refused(static fn () => $values->write('site.docs', 'doc-1', 1, 'de', ['slug' => 'ueber'], ['slug', 'title'], 'actor:t'), 'field_not_localized');

// A secondary locale must carry every required localized field unless the schema allows partial locales.
$refused(static fn () => $values->write('site.docs', 'doc-1', 1, 'de', ['description' => 'Nur Text'], [], 'actor:t', [], true), 'partial_locale_denied');
$values->write('site.docs', 'doc-1', 1, 'de', ['title' => 'Über uns'], [], 'actor:t', correlationId: 'corr-de');
$values->write('site.notes', 'doc-1', 1, 'de', ['description' => 'Nur Text'], [], 'actor:t');

// The primary locale is the language of the shared values; a value for it only overrides.
$values->write('site.docs', 'doc-2', 1, 'en', ['description' => 'Override'], [], 'actor:t');

// Each write is audited once with the field keys, never the values.
larena_storage_role_assert(count($sink->events) === 3, 'one event per write');
$event = $sink->events[0];
larena_storage_role_assert($event->stream === 'locale' && $event->type === LocalizedValueAuditEventCatalog::WRITTEN);
larena_storage_role_assert($event->actor === 'actor:t' && $event->correlationId === 'corr-de');
larena_storage_role_assert($event->payload['locale'] === 'de' && $event->payload['written_field_keys'] === ['title']);
larena_storage_role_assert(!str_contains((string) json_encode($event->payload), 'Über'), 'no value reaches audit');

// A translation whose audit cannot be written is not written.
$sink->fail = true;
try {
    $values->write('site.docs', 'doc-3', 1, 'de', ['title' => 'Kontakt'], [], 'actor:t');
    larena_storage_role_assert(false, 'a failing audit refuses the write');
} catch (RuntimeException) {
}
larena_storage_role_assert($values->read('site.docs', 'doc-3', 1, 'de') === [], 'nothing was written');

// storage.locale.fallback_resolve takes the chain from Lang's port: the caller names
// only the locale it wants.
$sink->fail = false;
$lang = new class implements \Larena\Storage\Contracts\LocaleFallbackResolver {
    public function chainFor(string $requestedLocale): \Larena\Storage\Contracts\LocaleFallbackChain
    {
        return \Larena\Storage\Contracts\LocaleFallbackChain::of($requestedLocale, 'de');
    }
};
$handlers = new \Larena\Storage\Runtime\LocaleOperationHandlers($values, $lang);
$resolved = $handlers->handle(
    \Larena\Storage\Runtime\LocaleOperationHandlers::descriptors()['storage.locale.fallback_resolve'],
    new \Larena\Core\Contracts\OperationContext(actorId: 'actor:t', correlationId: 'corr-resolve', metadata: [
        'schema_id' => 'site.docs', 'record_id' => 'doc-1', 'revision' => 1, 'locale' => 'kk', 'field_keys' => ['title'],
    ]),
);
larena_storage_role_assert(count($resolved['values']) === 1, 'the Kazakh read falls back along the chain');
larena_storage_role_assert($resolved['values'][0]['value'] === 'Über uns' && $resolved['values'][0]['exact'] === false);

echo "Localized schema rules passed.\n";
