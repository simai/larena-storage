<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Larena\Core\Contracts\SiteCapabilityLicence;
use Larena\Core\Contracts\SiteScenarioDemoContent;
use Larena\Core\Providers\Registrars\ScenarioRegistrar;
use Larena\Core\Scenario\DatabaseSiteScenarioState;
use Larena\Core\Scenario\NullSiteScenarioAudit;
use Larena\Core\Scenario\SiteScenarioApplier;
use Larena\Core\Scenario\SiteScenarioCatalog;
use Larena\Core\Scenario\UnavailableSiteScenarioSettings;
use Larena\Core\SitePack\SitePackArchiveReader;
use Larena\Storage\Contracts\StorageSitePackImportOptions;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\FirstRun\SitePagesStorage;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\SitePack\StorageScenarioDemoContent;
use Larena\Storage\SitePack\StorageSitePackFormat;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../Support/storage-scenario-demo-fixture.php';

// A scenario's demo pages through the SitePack import as a merge that only adds (StorageSitePackImportOptions
// `addMissing`, StorageScenarioDemoContent):
// - each of the five demo packs core ships validates (a storage SitePack of the site pages) and imports into a fresh
//   site: the untouched starter Home page replaced by the scenario's, the section with its pages under it, drafts,
//   the English translations kept on a site with English, left out (a warning) on a site without it;
// - into a changed site the person's pages stay as they are (a page at the same address is skipped, a child of it
//   added under the person's page), nothing published or removed, audited with what was skipped;
// - a second scenario adds its pages without touching the first one's (their Home pages share the address);
// - applying the same pack again adds nothing;
// - the strict restore is unchanged: without `addMissing` a used site is refused; `addMissing` needs site pages and
//   does not go with `copy`;
// - wired: core's SiteScenarioApplier with the storage binding imports a shipped scenario's pages once.

$admin = SCENARIO_DEMO_ADMIN;
$installations = [];

/** @return array<string, array{slug: mixed, title: mixed, parent: ?string, revision: int}> the site pages by record id */
function scenarioDemoPages(Connection $connection): array
{
    $pages = [];
    foreach ($connection->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->orderBy('record_id')->get() as $head) {
        $values = json_decode((string) $connection->table('larena_storage_record_versions')->where('schema_id', StarterSite::SCHEMA_ID)
            ->where('record_id', $head->record_id)->where('revision', $head->current_revision)->value('values_json'), true);
        $pages[(string) $head->record_id] = [
            'slug' => $values['slug'] ?? null,
            'title' => $values['title'] ?? null,
            'parent' => ($parent = $connection->table('larena_storage_record_relations')->where('relation_key', 'site_tree_parent')
                ->where('from_record_id', $head->record_id)->value('to_record_id')) === null ? null : (string) $parent,
            'revision' => (int) $head->current_revision,
        ];
    }

    return $pages;
}

/** @param array<string, array{slug: mixed}> $pages @return array<string, string> slug => record id */
function scenarioDemoBySlug(array $pages): array
{
    $bySlug = [];
    foreach ($pages as $id => $page) {
        $bySlug[(string) $page['slug']] = $id;
    }
    ksort($bySlug);

    return $bySlug;
}

try {
    // ===== Each shipped pack: a storage SitePack of the site pages; into a fresh site. =====
    foreach (scenarioDemoContent() as $scenarioId => $content) {
        $pack = scenarioDemoPackPath($scenarioId);
        storageViewExpect(is_file($pack), 'core ships no demo pack for ' . $scenarioId);
        $reader = SitePackArchiveReader::open($pack);
        $annotations = $reader->envelope->annotations;
        $reader->close();
        storageViewExpect(($annotations['schema'] ?? null) === StorageSitePackFormat::SCHEMA_NAME && ($annotations['adapter'] ?? null) === StorageSitePackFormat::ADAPTER,
            'the demo pack of ' . $scenarioId . ' is not a storage SitePack: ' . json_encode($annotations));
        $line = sitePackLines($pack, StorageSitePackFormat::STRUCTURES)[0];
        storageViewExpect(($line['adoption']['schema_id'] ?? null) === StarterSite::SCHEMA_ID, 'the demo pack of ' . $scenarioId . ' is not of the site pages');

        $site = $installations[] = scenarioDemoInstallation();
        $starterHome = (string) $site['connection']->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->value('record_id');
        $demo = new StorageScenarioDemoContent($site['connection'], $site['sitePack']);
        storageViewExpect($demo->available(), 'the demo content is not available');
        $scenario = new Larena\Core\Scenario\SiteScenario($scenarioId, 'x::t', 'x::d', ['larena.capability.content.base'], [], $pack);
        storageViewExpect($demo->import($scenario, $pack, $admin) === SiteScenarioDemoContent::IMPORTED, 'the demo pack of ' . $scenarioId . ' did not import');
        $pages = scenarioDemoPages($site['connection']);
        $bySlug = scenarioDemoBySlug($pages);
        $expected = ['home', $content['section']['slug'], ...array_map(static fn (array $page): string => $page['slug'], $content['pages'])];
        sort($expected);
        storageViewExpect(array_keys($bySlug) === $expected && !isset($pages[$starterHome]), 'the pages of ' . $scenarioId . ' are off: ' . json_encode($pages));
        storageViewExpect($pages[$bySlug['home']]['title'] === $content['home']['title'][0], 'the Home page of ' . $scenarioId . ' is not the scenario\'s');
        foreach ($content['pages'] as $page) {
            storageViewExpect($pages[$bySlug[$page['slug']]]['parent'] === $bySlug[$content['section']['slug']], $page['slug'] . ' is not in its section');
        }
        storageViewExpect($site['connection']->table('larena_storage_publication_states')->where('schema_id', StarterSite::SCHEMA_ID)->count() === 0,
            'the demo pages of ' . $scenarioId . ' were published');
        $english = $site['connection']->table('larena_storage_localized_values')->where('schema_id', StarterSite::SCHEMA_ID)
            ->where('record_id', $bySlug['home'])->where('locale', 'en')->where('field_key', 'title')->value('value_json');
        storageViewExpect(json_decode((string) $english, true) === $content['home']['title'][1], 'the English title of ' . $scenarioId . ' is missing');
        $imported = $site['runtime']['events']->of('storage.sitepack.imported');
        storageViewExpect(count($imported) === 1 && ($imported[0]->payload['add_missing'] ?? null) === true && ($imported[0]->payload['records_replaced'] ?? null) === 1,
            'the import of ' . $scenarioId . ' is not audited as demo content: ' . json_encode($imported[0]->payload ?? null));
    }

    // ===== A site without English: the translations are left out, the pages come. =====
    $russian = $installations[] = scenarioDemoInstallation(['ru']);
    $run = sitePackImportPlan($russian['sitePack'], SCENARIO_DEMO_SCOPE, scenarioDemoPackPath('blog'), $admin, new StorageSitePackImportOptions(addMissing: true));
    storageViewExpect($run->plan?->ok() === true && $run->plan->warningCodes() === ['locale_left_out'], 'English on a Russian site is off: ' . json_encode($run->plan?->toArray()));
    sitePackImportApply($russian['sitePack'], $run, $admin);
    storageViewExpect($russian['connection']->table('larena_storage_localized_values')->where('locale', 'en')->count() === 0
        && count(scenarioDemoPages($russian['connection'])) === 4, 'the Russian site got English or lost pages');

    // ===== A changed site: the person's Home and their own «blog» page stay; the posts go under the person's page. =====
    $changed = $installations[] = scenarioDemoInstallation();
    $connection = $changed['connection'];
    $workbench = $changed['runtime']['workbench'];
    $homeId = (string) $connection->table('larena_storage_records')->where('schema_id', StarterSite::SCHEMA_ID)->value('record_id');
    scenarioDemoUpdatePage($changed, $workbench->readRecord(SCENARIO_DEMO_SCOPE, SitePagesStorage::STRUCTURE_ID, $homeId, $admin), ['title' => 'Мой сайт']);
    $mine = scenarioDemoCreatePage($changed, ['slug' => 'blog', 'title' => 'Мой блог', 'order_index' => 1, 'target' => '/blog']);
    $about = scenarioDemoCreatePage($changed, ['slug' => 'about', 'title' => 'О нас', 'order_index' => 2, 'target' => '/about']);
    $changed['runtime']['publication']->publish(StarterSite::SCHEMA_ID, $about->recordId, SCENARIO_DEMO_SITE, 'ru', $about->revision, $admin);
    $before = scenarioDemoPages($connection);
    $publishedBefore = $connection->table('larena_storage_publication_states')->orderBy('record_id')->get()->map(static fn ($row): array => (array) $row)->all();

    // The strict restore still refuses a used site.
    $strict = sitePackImportPlan($changed['sitePack'], SCENARIO_DEMO_SCOPE, scenarioDemoPackPath('blog'), $admin);
    storageViewExpect($strict->plan?->errorCodes() === [Larena\Storage\SitePack\StorageSitePackPlanner::SITE_PAGES_NOT_EMPTY], 'the strict restore went into a used site: ' . json_encode($strict->plan?->errors));

    $planned = sitePackImportPlan($changed['sitePack'], SCENARIO_DEMO_SCOPE, scenarioDemoPackPath('blog'), $admin, new StorageSitePackImportOptions(addMissing: true));
    storageViewExpect($planned->plan?->ok() === true && $planned->plan->records['skip'] === 2 && $planned->plan->records['keep'] === 2 && $planned->plan->records['replaced'] === 0,
        'the merge plan is off: ' . json_encode($planned->plan?->toArray()));
    $skips = $connection->table('larena_storage_sitepack_ids')->where('run_ref', $planned->runRef)->where('action', 'skip')->orderBy('target_ref')->pluck('target_ref')->all();
    $expectedSkips = [$homeId, $mine->recordId];
    sort($expectedSkips);
    storageViewExpect($skips === $expectedSkips, 'the kept pages are not the person\'s Home and blog: ' . json_encode($skips));
    $done = sitePackImportApply($changed['sitePack'], $planned, $admin);
    storageViewExpect($done->isDone() && ($done->counts['records'] ?? null) === 2 && ($done->counts['records_skipped'] ?? null) === 2,
        'the merge did not end: ' . json_encode($done->toArray()));
    $after = scenarioDemoPages($connection);
    foreach ($before as $id => $page) {
        storageViewExpect(($after[$id] ?? null) === $page, 'the person\'s page ' . $page['slug'] . ' changed: ' . json_encode($after[$id] ?? null));
    }
    $added = array_diff_key($after, $before);
    storageViewExpect(count($added) === 2 && array_values(array_unique(array_column($added, 'parent'))) === [$mine->recordId],
        'the posts are not under the person\'s blog page: ' . json_encode($added));
    $publishedAfter = $connection->table('larena_storage_publication_states')->orderBy('record_id')->get()->map(static fn ($row): array => (array) $row)->all();
    storageViewExpect($publishedAfter === $publishedBefore, 'the merge published or changed a publication');
    storageViewExpect($connection->table('larena_storage_localized_values')->whereIn('record_id', [$homeId, $mine->recordId])->count() === 0,
        'the merge translated the person\'s pages');
    $audit = $changed['runtime']['events']->of('storage.sitepack.imported');
    storageViewExpect(($audit[0]->payload['records_skipped'] ?? null) === 2 && ($audit[0]->payload['add_missing'] ?? null) === true, 'the merge is not audited with what it skipped');

    // ===== Reapplying the same pack adds nothing. =====
    $snapshot = scenarioDemoPages($connection);
    $again = sitePackImportPlan($changed['sitePack'], SCENARIO_DEMO_SCOPE, scenarioDemoPackPath('blog'), $admin, new StorageSitePackImportOptions(addMissing: true));
    storageViewExpect($again->plan?->ok() === true && $again->plan->records['skip'] === 4 && $again->plan->replay !== null, 'the second plan is off: ' . json_encode($again->plan?->toArray()));
    $againDone = sitePackImportApply($changed['sitePack'], $again, $admin);
    storageViewExpect($againDone->isDone() && ($againDone->counts['records'] ?? 0) === 0 && scenarioDemoPages($connection) === $snapshot, 'reapplying added something');

    // ===== A second scenario adds its pages without touching the first one's. =====
    $two = $installations[] = scenarioDemoInstallation();
    $demo = new StorageScenarioDemoContent($two['connection'], $two['sitePack']);
    $company = new Larena\Core\Scenario\SiteScenario('company_site', 'x::t', 'x::d', ['larena.capability.content.base'], [], scenarioDemoPackPath('company_site'));
    $blog = new Larena\Core\Scenario\SiteScenario('blog', 'x::t', 'x::d', ['larena.capability.content.base'], [], scenarioDemoPackPath('blog'));
    storageViewExpect($demo->import($company, (string) $company->sitePack, $admin) === SiteScenarioDemoContent::IMPORTED, 'company_site did not import');
    $first = scenarioDemoPages($two['connection']);
    storageViewExpect($demo->import($blog, (string) $blog->sitePack, $admin) === SiteScenarioDemoContent::IMPORTED, 'blog did not import beside company_site');
    $both = scenarioDemoPages($two['connection']);
    foreach ($first as $id => $page) {
        storageViewExpect(($both[$id] ?? null) === $page, 'the first scenario\'s page ' . $page['slug'] . ' changed');
    }
    storageViewExpect(array_keys(scenarioDemoBySlug($both)) === ['blog', 'first-post', 'home', 'how-we-work', 'services', 'support', 'web-development']
        && scenarioDemoBySlug($both)['home'] === scenarioDemoBySlug($first)['home'], 'the two scenarios\' pages are off: ' . json_encode($both));

    // ===== What `addMissing` needs. =====
    sitePackRejects(static fn () => new StorageSitePackImportOptions(copy: true, addMissing: true), 'storage_sitepack_import_options_invalid', 'addMissing went with copy');
    $items = $installations[] = scenarioDemoInstallation();
    $items['runtime']['workbench']->createStructure(SCENARIO_DEMO_SCOPE, ['structure_id' => 'workbench.items', 'label' => 'Items', 'fields' => [
        storageViewField('title', 10, 'string', ['max_length' => 200], true),
    ]], $admin);
    $items['runtime']['workbench']->createRecord(SCENARIO_DEMO_SCOPE, 'workbench.items', ['title' => 'One'], $admin);
    $itemsPack = sys_get_temp_dir() . '/larena-storage-scenario-items-' . bin2hex(random_bytes(6)) . '.zip';
    $export = $items['sitePack']->run($items['sitePack']->startExport(SCENARIO_DEMO_SCOPE, 'workbench.items', $admin, null, false)->runRef, $admin);
    copy($items['sitePack']->archive($export->runRef, $admin)->path, $itemsPack);
    $unsupported = sitePackImportPlan($items['sitePack'], SCENARIO_DEMO_SCOPE, $itemsPack, $admin, new StorageSitePackImportOptions(addMissing: true));
    @unlink($itemsPack);
    storageViewExpect(in_array(Larena\Storage\SitePack\StorageSitePackPlanner::ADD_MISSING_UNSUPPORTED, $unsupported->plan?->errorCodes() ?? [], true),
        'addMissing took a pack that is not of the site pages: ' . json_encode($unsupported->plan?->errors));

    // ===== Wired: core's applier with the storage binding brings a shipped scenario's pages once. =====
    $wired = $installations[] = scenarioDemoInstallation();
    $wired['connection']->getSchemaBuilder()->create(DatabaseSiteScenarioState::TABLE, static function (Blueprint $table): void {
        $table->id();
        $table->string('state_key')->unique();
        $table->string('state_status');
        $table->string('launch_record_id')->nullable();
        $table->string('evidence_path')->nullable();
        $table->json('payload')->nullable();
        $table->timestamps();
    });
    $applier = new SiteScenarioApplier(
        SiteScenarioCatalog::fromDirectories(['larena/core' => ScenarioRegistrar::CORE_SCENARIOS]),
        new DatabaseSiteScenarioState($wired['connection']),
        new class implements SiteCapabilityLicence {
            public function allows(string $capability): bool
            {
                return true;
            }
        },
        new UnavailableSiteScenarioSettings(),
        new StorageScenarioDemoContent($wired['connection'], $wired['sitePack']),
        new NullSiteScenarioAudit(),
    );
    $outcome = $applier->apply('landing', $admin);
    $landing = scenarioDemoPages($wired['connection']);
    storageViewExpect($outcome->demo === SiteScenarioDemoContent::IMPORTED && array_keys(scenarioDemoBySlug($landing)) === ['basic', 'home', 'pricing'],
        'the applier did not bring the landing pages: ' . json_encode([$outcome->toArray(), $landing]));
    storageViewExpect($applier->apply('landing', $admin)->demo === Larena\Core\Scenario\SiteScenarioOutcome::DEMO_KEPT && scenarioDemoPages($wired['connection']) === $landing,
        'applying the scenario again changed the pages');
} catch (StorageRejected $exception) {
    throw new RuntimeException('refused: ' . $exception->reasonCode, 0, $exception);
} finally {
    foreach ($installations as $installation) {
        scenarioDemoDrop($installation);
    }
}

echo "Storage scenario demo content passed.\n";
