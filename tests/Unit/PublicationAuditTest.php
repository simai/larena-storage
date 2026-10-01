<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/publication-schema.php';

use Larena\Storage\Audit\PublicationAuditEventCatalog;
use Larena\Storage\Audit\PublicationAuditEventDescriptor;
use Larena\Storage\Contracts\StorageSecurityEvent;
use Larena\Storage\Contracts\StorageSecurityEventSink;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;

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

$connection = larena_storage_publication_connection();
$publication = new DatabasePublicationLifecycle($connection, null, null, $sink);

// A direct call is audited with the caller's actor, scope, locale and correlation.
$publication->publish('site.pages', 'home', 'site:main', 'en', 2, 'actor:editor', 'corr-direct');
larena_storage_role_assert(count($sink->events) === 1, 'one event per transition');
$event = $sink->events[0];
larena_storage_role_assert($event->stream === 'publication');
larena_storage_role_assert($event->type === PublicationAuditEventCatalog::PUBLISHED);
larena_storage_role_assert($event->actor === 'actor:editor');
larena_storage_role_assert($event->correlationId === 'corr-direct', 'the caller correlation is kept');
larena_storage_role_assert($event->subject === 'storage-record:home');
foreach (['schema_id' => 'site.pages', 'record_id' => 'home', 'scope_ref' => 'site:main', 'locale' => 'en', 'from_state' => 'draft', 'to_state' => 'published', 'revision' => 2] as $key => $expected) {
    larena_storage_role_assert(($event->payload[$key] ?? null) === $expected, $key . ' is in the audit payload');
}
larena_storage_role_assert(is_int($event->payload['log_id']) && $event->payload['log_id'] > 0, 'the event points at its log row');

// Unpublish reports the withdrawn revision in the audit payload too.
$publication->unpublish('site.pages', 'home', 'site:main', 'en', 'actor:publisher');
$withdrawn = $sink->events[1];
larena_storage_role_assert($withdrawn->type === PublicationAuditEventCatalog::UNPUBLISHED);
larena_storage_role_assert($withdrawn->payload['previous_published_revision'] === 2, 'the withdrawn revision is audited');
larena_storage_role_assert(str_starts_with($withdrawn->correlationId, 'storage-publication:'), 'a call without a correlation gets one');
$history = $publication->history('site.pages', 'home', 'site:main', 'en');
$logged = array_map(static fn ($transition): ?string => $transition->correlationId, $history);
larena_storage_role_assert(in_array($withdrawn->correlationId, $logged, true), 'the log row carries the same correlation');

// One sweep shares one correlation across every transition it makes.
$publication->schedule('site.pages', 'due-1', 'site:main', 'en', 1, '2020-01-01 00:00:00', 'actor:editor');
$publication->schedule('site.pages', 'due-2', 'site:main', 'ru', 1, '2020-01-01 00:00:00', 'actor:editor');
$before = count($sink->events);
$swept = $publication->sweep('system:scheduler', '2026-10-01 12:00:00');
larena_storage_role_assert($swept['published_count'] === 2);
$sweepEvents = array_slice($sink->events, $before);
larena_storage_role_assert(count($sweepEvents) === 2, 'the sweep audits every publication it makes');
larena_storage_role_assert(array_unique(array_map(static fn ($e): string => $e->type, $sweepEvents)) === [PublicationAuditEventCatalog::SWEPT]);
larena_storage_role_assert($sweepEvents[0]->actor === 'system:scheduler');
larena_storage_role_assert($sweepEvents[0]->correlationId === $sweepEvents[1]->correlationId, 'one sweep, one correlation');

// No field value reaches the audit payload.
foreach ($sink->events as $audited) {
    foreach ((new PublicationAuditEventDescriptor($audited->type))->forbiddenPayloadFields() as $forbidden) {
        larena_storage_role_assert(!array_key_exists($forbidden, $audited->payload), $forbidden . ' never reaches audit');
    }
}

// A transition whose audit cannot be written does not happen.
$sink->fail = true;
$logRows = $connection->table(DatabasePublicationLifecycle::LOG_TABLE)->count();
try {
    $publication->publish('site.pages', 'about', 'site:main', 'en', 1, 'actor:editor');
    larena_storage_role_assert(false, 'a failing audit refuses the transition');
} catch (RuntimeException) {
}
larena_storage_role_assert($publication->head('site.pages', 'about', 'site:main', 'en') === null, 'no state was written');
larena_storage_role_assert($connection->table(DatabasePublicationLifecycle::LOG_TABLE)->count() === $logRows, 'no log row was written');

echo "Publication audit passed.\n";
