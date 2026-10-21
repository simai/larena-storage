<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Contracts\PublishedListQuery;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Enums\PublicationTransitionValue;
use Larena\Storage\Exceptions\PublicationRejected;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\StorageMetrics;
use Larena\Storage\View\SqliteStorageViewDdl;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/published-site-view-fixture.php';

// A published page that gets a newer revision scheduled stays on the site until the
// schedule goes out (owner decision 2026-10-22): the projection, the record, the key
// lookup, the public list and the site витрина row all serve the old revision through
// the schedule; the sweep promotes the new one and every derived index moves with it.
// Rescheduling replaces the pending schedule; publish, unpublish and archive clear it;
// a schedule refused at sweep time stays pending with the old page live; a record that
// is not published is still scheduled, not live; a schedule written before the state
// row kept its revision is still swept.

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$connection = $capsule->getConnection();
publishedSiteViewInstall($connection);
$ddl = new SqliteStorageViewDdl($connection);
$actor = 'actor:admin:alpha';
$scope = 'scope:tenant-alpha';
$runtime = publishedSiteViewRuntime($connection, $ddl, $actor, $scope);
$workbench = $runtime['workbench'];
$publication = $runtime['publication'];
$read = $runtime['read'];
$siteRead = $runtime['siteRead'];
$site = $runtime['site'];
$lists = new DatabaseReadContracts(
    $connection,
    $runtime['localized'],
    new PublishedSiteViewChain(),
    new StorageMetrics(),
    null,
    $runtime['reader'],
    DatabaseReadContracts::KEY_SCAN_LIMIT,
    PropertyTypeRegistry::builtIns(),
    'pending-revision-test-cursor-key-of-at-least-32-bytes',
);

$structure = $workbench->createStructure($scope, ['structure_id' => 'workbench.pages', 'label' => 'Pages', 'fields' => [
    publishedSiteViewField('title', 10, 'string', 'public', ['max_length' => 200], true),
    publishedSiteViewField('slug', 20, 'string', 'public', ['max_length' => 120]) + ['indexed' => true],
    publishedSiteViewField('secret', 30, 'string', 'admin', ['max_length' => 200]),
]], $actor);
$schemaId = $structure->schema->schemaId;
$create = static fn (string $title, string $slug) => $workbench->createRecord($scope, 'workbench.pages', ['title' => $title, 'slug' => $slug, 'secret' => 'SENTINEL'], $actor);
$update = static fn ($record, string $title, string $slug) => $workbench->updateRecord($scope, 'workbench.pages', $record->recordId, $record->revision, ['title' => $title, 'slug' => $slug, 'secret' => 'SENTINEL'], $actor);
$head = static fn ($record) => $publication->head($schemaId, $record->recordId, 'site:main', 'en');
$entry = static fn ($record): ?array => publishedSiteViewEntries($site, $schemaId, 'site:main', 'en')[$record->recordId] ?? null;
$projected = static function (DatabaseReadContracts $reader) use ($schemaId): array {
    $revisions = [];
    foreach ($reader->publishedProjection($schemaId, 'site:main', 'en')->records as $record) {
        $revisions[(string) $record['record_id']] = (int) $record['revision'];
    }

    return $revisions;
};
$listed = static function () use ($lists, $schemaId): array {
    $titles = [];
    foreach ($lists->publishedList(new PublishedListQuery($schemaId, 'site:main', 'en'))->records as $record) {
        $titles[(string) $record['record_id']] = (string) $record['values']['title'];
    }

    return $titles;
};
$refused = static function (callable $call, string $reason, string $message): void {
    try {
        $call();
    } catch (PublicationRejected $rejection) {
        storageViewExpect($rejection->reasonCode === $reason, $message . ': ' . $rejection->reasonCode);

        return;
    }
    storageViewExpect(false, $message . ': nothing was refused');
};
/** Every published read of the page answers this revision, title and slug; the old slug is gone when it changed. */
$serves = static function ($page, int $revision, string $title, string $slug, string $message, ?string $goneSlug = null) use ($read, $siteRead, $schemaId, $entry, $projected, $listed, $site): void {
    foreach (['exact' => $read, 'site' => $siteRead] as $path => $reader) {
        $record = $reader->publishedRecord($schemaId, 'site:main', 'en', $page->recordId);
        storageViewExpect($record !== null && $record['revision'] === $revision && $record['values']['title'] === $title, $message . ': the ' . $path . ' record is ' . json_encode($record));
        storageViewExpect(($projected($reader)[$page->recordId] ?? null) === $revision, $message . ': the ' . $path . ' projection does not hold revision ' . $revision);
        $key = $reader->resolveKey($schemaId, 'site:main', 'en', 'slug', $slug);
        storageViewExpect($key?->recordId === $page->recordId && $key->revision === $revision, $message . ': the ' . $path . ' key ' . $slug . ' does not resolve to revision ' . $revision);
        if ($goneSlug !== null) {
            storageViewExpect($reader->resolveKey($schemaId, 'site:main', 'en', 'slug', $goneSlug) === null, $message . ': the ' . $path . ' key ' . $goneSlug . ' still resolves');
        }
    }
    $row = $entry($page);
    storageViewExpect($row !== null && $row['revision'] === $revision && $row['values']['title'] === $title, $message . ': the site row is ' . json_encode($row));
    storageViewExpect(($listed()[$page->recordId] ?? null) === $title, $message . ': the public list does not show ' . $title);
    publishedSiteViewParity($site, $schemaId, $message);
};

// --- A published page, its newer revision scheduled: the old one stays live everywhere. ---
$about = $create('About', 'about');
$other = $create('Other', 'other');
$publication->publish($schemaId, $about->recordId, 'site:main', 'en', $about->revision, $actor);
$publication->publish($schemaId, $other->recordId, 'site:main', 'en', $other->revision, $actor);
$serves($about, 1, 'About', 'about', 'published');
$about2 = $update($about, 'About, new', 'about-us');
$state = $publication->schedule($schemaId, $about->recordId, 'site:main', 'en', $about2->revision, '2026-11-01 09:00:00', $actor);
storageViewExpect($state->state === PublicationStateValue::Published && $state->publishedRevision === 1 && $state->isPublished(), 'the schedule took the page down: ' . json_encode($state->toArray()));
storageViewExpect($state->scheduledRevision === 2 && $state->scheduledAt === '2026-11-01 09:00:00' && $state->hasPendingRevision(), 'the pending schedule is not on the state');
storageViewExpect($head($about)?->toArray() === $state->toArray(), 'head() does not read back the pending schedule');
storageViewExpect($state->toArray()['scheduled_revision'] === 2 && $state->toArray()['has_pending_revision'] === true, 'toArray() hides the pending schedule');
$serves($about, 1, 'About', 'about', 'scheduled from published', 'about-us');
storageViewExpect(count($publication->dueSchedules('2026-10-31 00:00:00')) === 0 && $publication->sweep('system:scheduler', '2026-10-31 00:00:00')['published_count'] === 0, 'a schedule fired early');
$serves($about, 1, 'About', 'about', 'a sweep before the time');
$due = $publication->dueSchedules('2026-11-01 09:00:00');
storageViewExpect(count($due) === 1 && $due[0]->recordId === $about->recordId && $due[0]->scheduledRevision === 2, 'the pending schedule is not due on time');

// --- The sweep promotes it: the head, the key, the site row, the list and the log move together. ---
$swept = $publication->sweep('system:scheduler', '2026-11-01 09:00:00');
storageViewExpect($swept['published_count'] === 1 && $swept['published'][0]['revision'] === 2, 'the sweep did not promote: ' . json_encode($swept));
$state = $head($about);
storageViewExpect($state !== null && $state->state === PublicationStateValue::Published && $state->publishedRevision === 2 && $state->previousPublishedRevision === 1, 'the promoted head is wrong');
storageViewExpect($state->scheduledAt === null && $state->scheduledRevision === null && !$state->hasPendingRevision(), 'the promoted schedule was not cleared');
$serves($about2, 2, 'About, new', 'about-us', 'swept', 'about');
$history = $publication->history($schemaId, $about->recordId, 'site:main', 'en');
storageViewExpect(array_map(static fn ($t): string => $t->transition->value . ':' . $t->fromState->value . '>' . $t->toState->value, $history) === [
    'publish:draft>published', 'schedule:published>published', 'sweep_publish:published>published',
], 'the log does not tell the story: ' . json_encode(array_map(static fn ($t): array => $t->toArray(), $history)));
storageViewExpect($publication->sweep('system:scheduler', '2026-12-01 00:00:00')['published_count'] === 0, 'a second sweep promoted again');

// --- Rescheduling replaces the pending schedule. ---
$about3 = $update($about2, 'About, third', 'about-3');
$about4 = $update($about3, 'About, fourth', 'about-4');
$publication->schedule($schemaId, $about->recordId, 'site:main', 'en', $about3->revision, '2026-11-02 09:00:00', $actor);
$state = $publication->schedule($schemaId, $about->recordId, 'site:main', 'en', $about4->revision, '2026-11-03 09:00:00', $actor);
storageViewExpect($state->publishedRevision === 2 && $state->scheduledRevision === 4 && $state->scheduledAt === '2026-11-03 09:00:00', 'the reschedule did not replace the schedule');
storageViewExpect($publication->sweep('system:scheduler', '2026-11-02 12:00:00')['published_count'] === 0, 'the replaced schedule still fired');
$serves($about2, 2, 'About, new', 'about-us', 'rescheduled');
storageViewExpect($publication->sweep('system:scheduler', '2026-11-03 09:00:00')['published'][0]['revision'] === 4, 'the replacing schedule did not fire');
$serves($about4, 4, 'About, fourth', 'about-4', 'rescheduled and swept', 'about-us');

// --- A publish clears the pending schedule. ---
$about5 = $update($about4, 'About, fifth', 'about-5');
$publication->schedule($schemaId, $about->recordId, 'site:main', 'en', $about5->revision, '2026-11-04 09:00:00', $actor);
$state = $publication->publish($schemaId, $about->recordId, 'site:main', 'en', $about4->revision, $actor);
storageViewExpect($state->publishedRevision === 4 && $state->scheduledAt === null && $state->scheduledRevision === null, 'a publish kept the schedule');
storageViewExpect($publication->sweep('system:scheduler', '2026-11-05 00:00:00')['published_count'] === 0, 'a schedule a publish cleared still fired');
$serves($about4, 4, 'About, fourth', 'about-4', 'published over a schedule');

// --- An unpublish takes the page down and clears the schedule. ---
$publication->schedule($schemaId, $about->recordId, 'site:main', 'en', $about5->revision, '2026-11-04 09:00:00', $actor);
$state = $publication->unpublish($schemaId, $about->recordId, 'site:main', 'en', $actor);
storageViewExpect($state->state === PublicationStateValue::Draft && $state->publishedRevision === null && $state->scheduledAt === null && $state->scheduledRevision === null, 'an unpublish kept the schedule: ' . json_encode($state->toArray()));
storageViewExpect($publication->sweep('system:scheduler', '2026-11-05 00:00:00')['published_count'] === 0, 'an unpublished page came back with its schedule');
storageViewExpect($read->publishedRecord($schemaId, 'site:main', 'en', $about->recordId) === null && $entry($about) === null
    && $read->resolveKey($schemaId, 'site:main', 'en', 'slug', 'about-4') === null && !isset($listed()[$about->recordId]), 'the unpublished page is still served');
publishedSiteViewParity($site, $schemaId, 'unpublished with a schedule');

// --- Not published: a schedule is still scheduled and not live (Draft → Scheduled unchanged). ---
$state = $publication->schedule($schemaId, $about->recordId, 'site:main', 'en', $about5->revision, '2026-11-06 09:00:00', $actor);
storageViewExpect($state->state === PublicationStateValue::Scheduled && !$state->isPublished() && !$state->hasPendingRevision() && $state->scheduledRevision === 5, 'a draft schedule is not scheduled');
storageViewExpect($read->publishedRecord($schemaId, 'site:main', 'en', $about->recordId) === null && $entry($about) === null && $read->resolveKey($schemaId, 'site:main', 'en', 'slug', 'about-5') === null, 'a scheduled draft is live');
publishedSiteViewParity($site, $schemaId, 'scheduled draft');
storageViewExpect($publication->sweep('system:scheduler', '2026-11-06 09:00:00')['published_count'] === 1, 'the scheduled draft was not swept');
$serves($about5, 5, 'About, fifth', 'about-5', 'scheduled draft swept');

// --- An archive takes the page down and clears the schedule. ---
$about6 = $update($about5, 'About, sixth', 'about-6');
$publication->schedule($schemaId, $about->recordId, 'site:main', 'en', $about6->revision, '2026-11-07 09:00:00', $actor);
$state = $publication->archive($schemaId, $about->recordId, 'site:main', 'en', $actor);
storageViewExpect($state->state === PublicationStateValue::Archived && $state->scheduledAt === null && $state->scheduledRevision === null, 'an archive kept the schedule');
storageViewExpect($publication->sweep('system:scheduler', '2026-11-08 00:00:00')['published_count'] === 0 && $entry($about) === null, 'an archived page came back with its schedule');
publishedSiteViewParity($site, $schemaId, 'archived with a schedule');

// --- A schedule refused at sweep time stays pending, and the old page stays live. ---
$taken = $update($other, 'Other, taking about', 'about-7');
$publication->publish($schemaId, $about->recordId, 'site:main', 'en', $about6->revision, $actor);
$about7 = $update($about6, 'About, seventh', 'about-7');
$publication->schedule($schemaId, $about->recordId, 'site:main', 'en', $about7->revision, '2026-11-09 09:00:00', $actor);
$publication->publish($schemaId, $other->recordId, 'site:main', 'en', $taken->revision, $actor);
$result = $publication->sweep('system:scheduler', '2026-11-09 09:00:00');
storageViewExpect($result['published_count'] === 0 && ($result['refused'][0]['reason_code'] ?? null) === 'key_conflict', 'the conflicting schedule was not refused: ' . json_encode($result));
$state = $head($about);
storageViewExpect($state->state === PublicationStateValue::Published && $state->publishedRevision === 6 && $state->scheduledRevision === 7 && $state->hasPendingRevision(), 'a refused schedule changed the page');
$serves($about6, 6, 'About, sixth', 'about-6', 'refused at sweep time');
// The key is freed: the next sweep promotes it.
$publication->unpublish($schemaId, $other->recordId, 'site:main', 'en', $actor);
storageViewExpect($publication->sweep('system:scheduler', '2026-11-09 09:01:00')['published_count'] === 1, 'the freed schedule was not promoted');
$serves($about7, 7, 'About, seventh', 'about-7', 'promoted after the conflict', 'about-6');

// --- The sweep promotes only a schedule that is still pending. ---
$refused(static fn () => (new ReflectionMethod(DatabasePublicationLifecycle::class, 'transition'))->invoke(
    $publication,
    PublicationTransitionValue::SweepPublish,
    $schemaId,
    $about->recordId,
    'site:main',
    'en',
    $about7->revision,
    null,
    'system:scheduler',
    null,
), 'schedule_not_pending', 'a sweep without a pending schedule was allowed');
$about8 = $update($about7, 'About, eighth', 'about-8');
$publication->schedule($schemaId, $about->recordId, 'site:main', 'en', $about8->revision, '2026-11-10 09:00:00', $actor);
$refused(static fn () => (new ReflectionMethod(DatabasePublicationLifecycle::class, 'transition'))->invoke(
    $publication,
    PublicationTransitionValue::SweepPublish,
    $schemaId,
    $about->recordId,
    'site:main',
    'en',
    $about7->revision,
    null,
    'system:scheduler',
    null,
), 'schedule_not_pending', 'a sweep of a replaced schedule was allowed');
$serves($about7, 7, 'About, seventh', 'about-7', 'refused stale sweeps');

// --- A schedule written before the state row kept its revision: read from the log, swept. ---
$connection->table(DatabasePublicationLifecycle::STATES_TABLE)->where('record_id', $about->recordId)->update(['scheduled_revision' => null]);
$state = $head($about);
storageViewExpect($state->scheduledRevision === 8 && $state->hasPendingRevision(), 'an older schedule lost its revision');
storageViewExpect(($publication->sweep('system:scheduler', '2026-11-10 09:00:00')['published'][0]['revision'] ?? null) === 8, 'an older schedule was not swept');
$serves($about8, 8, 'About, eighth', 'about-8', 'an older schedule swept');

// --- A lifecycle on tables without the column (a site not yet migrated) keeps the old page live too. ---
$legacy = new Capsule();
$legacy->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'legacy');
$legacyConnection = $legacy->getConnection('legacy');
$legacyConnection->getSchemaBuilder()->create(DatabasePublicationLifecycle::STATES_TABLE, static function ($table): void {
    $table->string('publication_id', 190)->primary();
    foreach (['schema_id', 'record_id', 'scope_ref', 'locale', 'state', 'updated_by'] as $column) {
        $table->string($column);
    }
    foreach (['published_revision', 'previous_published_revision'] as $column) {
        $table->unsignedBigInteger($column)->nullable();
    }
    foreach (['scheduled_at', 'published_at', 'archived_at'] as $column) {
        $table->timestamp($column)->nullable();
    }
    $table->string('correlation_id')->nullable();
    $table->timestamps();
});
$legacyConnection->getSchemaBuilder()->create(DatabasePublicationLifecycle::LOG_TABLE, static function ($table): void {
    $table->bigIncrements('id');
    foreach (['publication_id', 'schema_id', 'record_id', 'scope_ref', 'locale', 'transition', 'from_state', 'to_state', 'actor_id'] as $column) {
        $table->string($column);
    }
    $table->unsignedBigInteger('revision')->nullable();
    $table->timestamp('scheduled_at')->nullable();
    $table->string('correlation_id')->nullable();
    $table->timestamp('created_at');
});
$old = new DatabasePublicationLifecycle($legacyConnection);
$old->publish('site.pages', 'page', 'site:main', 'en', 1, $actor);
$state = $old->schedule('site.pages', 'page', 'site:main', 'en', 2, '2026-11-01 00:00:00', $actor);
storageViewExpect($state->isPublished() && $state->publishedRevision === 1 && $old->head('site.pages', 'page', 'site:main', 'en')?->scheduledRevision === 2, 'without the column the pending revision is lost');
storageViewExpect($old->sweep('system:scheduler', '2026-11-01 00:00:00')['published'][0]['revision'] === 2 && $old->head('site.pages', 'page', 'site:main', 'en')?->publishedRevision === 2, 'without the column the schedule is not promoted');

storageViewExpect(!str_contains(publishedSiteViewDump($connection, $schemaId), 'SENTINEL'), 'an admin field reached the site витрина');

echo "Publication pending revision passed: the old page stays live until its scheduled revision is promoted.\n";
