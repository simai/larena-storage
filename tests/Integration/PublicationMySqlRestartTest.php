<?php

declare(strict_types=1);

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Contracts\AuditSink;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Compatibility\Audit\AuditStorageSecurityEventSink;
use Larena\Storage\Contracts\StorageRecordVersionRef;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\VersionedStorage;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/mysql-disposable-database.php';

/**
 * publication_lifecycle criterion 7 on MySQL: published heads, publication history
 * and record revisions read back the same through a new connection, and every
 * transition reached the audit pipeline with actor, scope, locale and correlation.
 */
$optIn = getenv('LARENA_STORAGE_PUBLICATION_MYSQL_TEST');
if (!is_string($optIn) || !filter_var($optIn, FILTER_VALIDATE_BOOL)) {
    echo "PublicationMySqlRestartTest skipped (explicit opt-in required).\n";
    exit(0);
}

$recorded = new class implements AuditSink {
    /** @var list<AuditEvent> */
    public array $events = [];

    public function accepts(AuditEventDescriptor $descriptor): bool
    {
        return true;
    }

    public function write(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
};
$authorizer = new class implements ActorOperationAuthorizer {
    public function assertAllowed(string $actor, string $operation): void
    {
    }
};
$runtime = static function ($connection) use ($recorded, $authorizer): array {
    $audit = new AuditEventPipeline(new DefaultAuditRedactor(), [$recorded]);
    $storage = new VersionedStorage($connection, PropertyTypeRegistry::builtIns(), $authorizer, $audit);
    $publication = new DatabasePublicationLifecycle(
        $connection,
        static fn (string $schemaId, string $recordId, int $revision): bool => $connection->table('larena_storage_record_versions')
            ->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->exists(),
        null,
        AuditStorageSecurityEventSink::fromObject($audit),
    );

    return [$storage, $publication];
};
$snapshot = static fn (DatabasePublicationLifecycle $publication, string $schemaId, string $recordId, string $locale): string => json_encode([
    'head' => $publication->head($schemaId, $recordId, 'site:main', $locale),
    'history' => $publication->history($schemaId, $recordId, 'site:main', $locale),
], JSON_THROW_ON_ERROR);

$database = larena_storage_mysql_disposable_database('larena_storage_publication_test_');
try {
    $connection = larena_storage_mysql_connect($database['config']);
    (require __DIR__ . '/../../database/migrations/2026_07_13_000001_create_larena_storage_version_tables.php')->up();
    (require __DIR__ . '/../../database/migrations/2026_07_14_000001_validate_larena_storage_version_table_shapes.php')->up();
    (require __DIR__ . '/../../database/migrations/platform/2026_09_29_000001_create_larena_storage_publication_tables.php')->up();

    [$storage, $publication] = $runtime($connection);
    $schema = $storage->registerSchemaVersion([
        'schema_id' => 'site.pages',
        'owner_package' => 'larena/storage-acceptance',
        'fields' => [['key' => 'title', 'type' => 'string', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => []]],
    ], null, 'user:admin_identity:1', 'mysql-schema');
    $v1 = $storage->create('site:main', $schema->ref, ['title' => 'Первая'], 'user:admin_identity:1', 'mysql-r1')->version;
    $v2 = $storage->compareAndSwap('site:main', $v1->ref, $schema->ref, ['title' => 'Вторая'], 'user:admin_identity:1', 'mysql-r2')->version;
    $recordId = $v1->ref->recordId;

    // en: published 1, then 2, withdrawn, republished 2. ru: scheduled and swept.
    $publication->publish('site.pages', $recordId, 'site:main', 'en', 1, 'actor:editor', 'mysql-pub-1');
    $publication->publish('site.pages', $recordId, 'site:main', 'en', 2, 'actor:editor', 'mysql-pub-2');
    $publication->unpublish('site.pages', $recordId, 'site:main', 'en', 'actor:editor', 'mysql-unpub');
    $publication->publish('site.pages', $recordId, 'site:main', 'en', 2, 'actor:editor', 'mysql-repub');
    $publication->schedule('site.pages', $recordId, 'site:main', 'ru', 1, '2020-01-01 00:00:00', 'actor:editor', 'mysql-schedule');
    $publication->sweep('system:scheduler', '2026-10-01 12:00:00');

    $before = ['en' => $snapshot($publication, 'site.pages', $recordId, 'en'), 'ru' => $snapshot($publication, 'site.pages', $recordId, 'ru')];
    $en = $publication->head('site.pages', $recordId, 'site:main', 'en');
    larena_storage_mysql_expect($en !== null && $en->isPublished() && $en->publishedRevision === 2, 'storage_mysql_en_head_wrong');
    larena_storage_mysql_expect(count($publication->history('site.pages', $recordId, 'site:main', 'en')) === 4, 'storage_mysql_en_history_wrong');
    $ru = $publication->head('site.pages', $recordId, 'site:main', 'ru');
    larena_storage_mysql_expect($ru !== null && $ru->isPublished() && $ru->publishedRevision === 1, 'storage_mysql_ru_sweep_wrong');

    // Restart: drop the connection and read through a new one and new services.
    $connection->disconnect();
    $reconnected = larena_storage_mysql_connect($database['config']);
    [$storageAfter, $publicationAfter] = $runtime($reconnected);
    foreach (['en', 'ru'] as $locale) {
        larena_storage_mysql_expect(
            $snapshot($publicationAfter, 'site.pages', $recordId, $locale) === $before[$locale],
            'storage_mysql_' . $locale . '_readback_differs',
        );
    }
    foreach ([[1, 'Первая'], [2, 'Вторая']] as [$revision, $title]) {
        $read = $storageAfter->readAdminVersion(new StorageRecordVersionRef('site.pages', $recordId, $revision), 'user:admin_identity:1');
        larena_storage_mysql_expect($read->values === ['title' => $title], 'storage_mysql_revision_' . $revision . '_differs');
    }

    // Every transition reached the audit pipeline, the sweep included.
    $publicationEvents = array_values(array_filter($recorded->events, static fn (AuditEvent $event): bool => $event->category === 'storage_publication'));
    larena_storage_mysql_expect(count($publicationEvents) === 6, 'storage_mysql_publication_audit_count_' . count($publicationEvents));
    $first = $publicationEvents[0];
    larena_storage_mysql_expect($first->type === 'storage.publication.published' && $first->actor === 'actor:editor' && $first->correlationId === 'mysql-pub-1', 'storage_mysql_audit_attribution_wrong');
    larena_storage_mysql_expect($first->payload['scope_ref'] === 'site:main' && $first->payload['locale'] === 'en', 'storage_mysql_audit_scope_wrong');
    $swept = $publicationEvents[5];
    larena_storage_mysql_expect($swept->type === 'storage.publication.swept' && $swept->actor === 'system:scheduler' && str_starts_with($swept->correlationId, 'storage-publication:'), 'storage_mysql_sweep_audit_wrong');
} finally {
    ($database['drop'])();
}

echo "PublicationMySqlRestartTest passed (MySQL, restart readback and audit).\n";
