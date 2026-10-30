<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Larena\Core\Contracts\SiteLanguages;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\StarterStructureRoles;

/**
 * Where a site's records with addresses stand (role site_record@v1, sites plan, step 5.8): a page of the site names a
 * storage in its `records_storage` field, and each record published there has its address under that page. A record is at
 * an address in a language only while the page is reachable there. One storage is given out by one page (a second is
 * refused when written: SitePageRecordValidator), so a record has one address; a page at the same address wins.
 */
final readonly class SiteRecordAddresses
{
    public const ROLE = StarterStructureRoles::SITE_RECORD_REF;

    public const SECTION_FIELD = 'records_storage';

    public const SLUG_TAKEN = 'site_record_slug_taken';

    public const SLUG_INVALID = 'site_record_slug_invalid';

    public const ADDRESS_TAKEN = 'site_record_address_taken';

    public const SECTION_INVALID = 'site_page_records_storage_invalid';

    public const SECTION_TAKEN = 'site_page_records_storage_taken';

    private const STRUCTURES = 'larena_storage_workbench_structures';

    private const RECORDS = 'larena_storage_records';

    private const VERSIONS = 'larena_storage_record_versions';

    private const LOCALIZED = 'larena_storage_localized_values';

    private const STATE_FIELD = 'larena_record_state';

    private const CHUNK = 1000;

    public function __construct(
        private SitePageTree $tree,
        private SitePageAddresses $addresses,
        private DatabaseReadContracts $read,
        private ?SiteLanguages $languages = null,
    ) {
    }

    /** Whether the schema's records have addresses on this site. */
    public function isRecordOf(string $schemaId, string $site): bool
    {
        return $this->tree->connection()->table(DatabaseStructureRoleRegistry::BINDINGS_TABLE)
            ->where('role_ref', self::ROLE)->where('schema_id', $schemaId)->where('scope_ref', $site)->where('status', 'active')->exists();
    }

    /** @return list<string> the sites a schema's records have addresses on */
    public function sitesOf(string $schemaId): array
    {
        return array_values(array_map('strval', $this->tree->connection()->table(DatabaseStructureRoleRegistry::BINDINGS_TABLE)
            ->where('role_ref', self::ROLE)->where('schema_id', $schemaId)->where('status', 'active')->orderBy('scope_ref')->pluck('scope_ref')->all()));
    }

    /**
     * The storages a page of the site can give out: bound to the role here and not archived.
     *
     * @return array<string, array{schema: string, label: string}> by storage id
     */
    public function storages(string $site): array
    {
        $database = $this->tree->connection();
        $schemas = $database->table(DatabaseStructureRoleRegistry::BINDINGS_TABLE)
            ->where('role_ref', self::ROLE)->where('scope_ref', $site)->where('status', 'active')->pluck('schema_id')->all();
        if ($schemas === []) {
            return [];
        }
        $storages = [];
        foreach ($database->table(self::STRUCTURES . ' as heads')
            ->join('larena_storage_workbench_structure_versions as versions', static function ($join): void {
                $join->on('versions.structure_id', '=', 'heads.structure_id')->on('versions.scope_ref', '=', 'heads.scope_ref')->on('versions.version', '=', 'heads.current_version');
            })
            ->whereIn('heads.storage_schema_id', $schemas)->whereNull('heads.archived_at')->whereNull('heads.import_run_ref')
            ->orderBy('versions.label')->orderBy('heads.structure_id')->get(['heads.structure_id', 'heads.storage_schema_id', 'versions.label']) as $row) {
            $storages[(string) $row->structure_id] = ['schema' => (string) $row->storage_schema_id, 'label' => (string) $row->label];
        }

        return $storages;
    }

    /** How many of a storage's records are out on the site, in any language: what a page that stops giving it out takes away. */
    public function publishedCount(string $site, string $structureId): int
    {
        $schemaId = $this->storages($site)[$structureId]['schema'] ?? null;

        return $schemaId === null ? 0 : (int) $this->tree->connection()->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->where('schema_id', $schemaId)->where('scope_ref', $site)->where('state', PublicationStateValue::Published->value)
            ->distinct()->count('record_id');
    }

    /** Why a page may not name this storage: it must be one the site can give out, and no other page's. Empty is none. */
    public function whyNotSection(string $pagesSchema, ?string $recordId, string $site, string $structureId): ?string
    {
        if ($structureId === '') {
            return null;
        }
        if (!isset($this->storages($site)[$structureId])) {
            return self::SECTION_INVALID;
        }
        $holder = $this->tree->connection()->table(self::RECORDS . ' as r')
            ->join(self::VERSIONS . ' as v', static function ($join): void {
                $join->on('v.schema_id', '=', 'r.schema_id')->on('v.record_id', '=', 'r.record_id')->on('v.revision', '=', 'r.current_revision');
            })
            ->where('r.schema_id', $pagesSchema)->where('v.values_json->' . self::SECTION_FIELD, $structureId)
            ->when($recordId !== null, static fn ($query) => $query->where('r.record_id', '!=', $recordId))
            ->get(['v.values_json']);
        foreach ($holder as $row) {
            $values = json_decode((string) $row->values_json, true);
            if (!is_array($values) || ($values[self::STATE_FIELD] ?? null) !== 'archived') {
                return self::SECTION_TAKEN;
            }
        }

        return null;
    }

    /**
     * Every record reachable in a language: address => "schema\nrecord\nsection page". $pages are the site's pages in that
     * language (record => address); a page out only in the source language stands in for it, as its children do there.
     *
     * @param array<string, string> $pages
     * @return array<string, string>
     */
    public function build(string $pagesSchema, string $site, string $locale, array $pages): array
    {
        $sections = $this->sectionsOf($pagesSchema, $site, $locale);
        if ($sections === []) {
            return [];
        }
        $source = $this->sourceLanguage($site, $locale);
        $sourcePages = null;
        $storages = $this->storages($site);
        $held = [];
        foreach ($sections as $sectionId => $structureId) {
            $schemaId = $storages[$structureId]['schema'] ?? null;
            $address = $pages[$sectionId] ?? null;
            if ($address === null && $source !== null) {
                $sourcePages ??= array_flip($this->addresses->build($pagesSchema, $site, $source));
                $address = $sourcePages[$sectionId] ?? null;
            }
            if ($schemaId === null || $address === null) {
                continue;
            }
            $after = null;
            do {
                $heads = $this->read->publishedHeadPage($schemaId, $site, $locale, self::CHUNK, $after);
                foreach ($heads === [] ? [] : $this->read->keyValuesAt($schemaId, $heads, $locale, SitePagePath::SLUG_FIELD) as $recordId => $slug) {
                    if (SitePagePath::isSlug($slug)) {
                        $held[SitePagePath::under($address, $slug)][] = $schemaId . "\n" . $recordId . "\n" . $sectionId;
                    }
                }
                $after = $heads === [] ? null : $heads[array_key_last($heads)]['record_id'];
            } while (count($heads) === self::CHUNK);
        }
        $records = [];
        foreach ($held as $address => $entries) {
            if (count($entries) === 1) {
                $records[(string) $address] = $entries[0];
            }
        }

        return $records;
    }

    /**
     * The pages out in a language (or its source) that give out a storage: page => storage id.
     *
     * @return array<string, string>
     */
    public function sectionsOf(string $pagesSchema, string $site, string $locale): array
    {
        $sections = [];
        foreach (array_unique(array_filter([$locale, $this->sourceLanguage($site, $locale)])) as $language) {
            foreach ($this->tree->connection()->table(DatabasePublicationLifecycle::STATES_TABLE . ' as s')
                ->join(self::VERSIONS . ' as v', static function ($join): void {
                    $join->on('v.schema_id', '=', 's.schema_id')->on('v.record_id', '=', 's.record_id')->on('v.revision', '=', 's.published_revision');
                })
                ->where('s.schema_id', $pagesSchema)->where('s.scope_ref', $site)->where('s.locale', $language)
                ->where('s.state', PublicationStateValue::Published->value)
                ->whereNotNull('v.values_json->' . self::SECTION_FIELD)
                ->get(['s.record_id', 'v.values_json']) as $row) {
                $values = json_decode((string) $row->values_json, true);
                $storage = is_array($values) ? ($values[self::SECTION_FIELD] ?? null) : null;
                if (is_string($storage) && $storage !== '') {
                    $sections[(string) $row->record_id] ??= $storage;
                }
            }
        }

        return $sections;
    }

    /** A published record's address in a language, read now; null when it is not reachable there. */
    public function addressOf(string $schemaId, string $site, string $locale, string $recordId): ?string
    {
        $revision = $this->read->publishedRevisions($schemaId, $site, $locale, [$recordId])[$recordId] ?? null;

        return $revision === null ? null : $this->addressAt($schemaId, $site, $locale, $recordId, $revision);
    }

    /** Where a revision of a record would stand in a language, published or not; null when its section is not reachable. */
    public function addressAt(string $schemaId, string $site, string $locale, string $recordId, int $revision): ?string
    {
        $slug = $this->read->keyValuesAt($schemaId, [['record_id' => $recordId, 'revision' => $revision]], $locale, SitePagePath::SLUG_FIELD)[$recordId] ?? null;
        $section = $this->sectionAddress($schemaId, $site, $locale);

        return $section === null || !SitePagePath::isSlug($slug) ? null : SitePagePath::under($section, $slug);
    }

    /** Why this revision of a record may not become its published head in a language; a record with no section yet may. */
    public function whyNotPublish(string $schemaId, string $recordId, string $site, string $locale, int $revision): ?string
    {
        if (!$this->isRecordOf($schemaId, $site)) {
            return null;
        }
        $slug = $this->read->keyValuesAt($schemaId, [['record_id' => $recordId, 'revision' => $revision]], $locale, SitePagePath::SLUG_FIELD)[$recordId] ?? null;
        if (!SitePagePath::isSlug($slug)) {
            return self::SLUG_INVALID;
        }
        foreach ($this->read->keyHolderIds($schemaId, $site, $locale, SitePagePath::SLUG_FIELD, $slug) as $holder) {
            if ($holder !== $recordId) {
                return self::ADDRESS_TAKEN;
            }
        }
        $pagesSchema = $this->tree->schemaOf($site);
        $section = $pagesSchema === null ? null : $this->sectionOf($pagesSchema, $schemaId, $site, $locale);
        if ($section === null) {
            return null;
        }
        $revisions = $this->read->publishedRevisions($pagesSchema, $site, $locale, $this->tree->section($pagesSchema, $section));
        $heads = [];
        foreach ($revisions as $id => $pageRevision) {
            $heads[] = ['record_id' => (string) $id, 'revision' => $pageRevision];
        }

        return $heads !== [] && in_array($slug, $this->read->keyValuesAt($pagesSchema, $heads, $locale, SitePagePath::SLUG_FIELD), true) ? self::ADDRESS_TAKEN : null;
    }

    /** Whether a page with this slug under $parent would stand where a published record already does. */
    public function holdsUnder(string $pagesSchema, string $site, string $locale, ?string $parent, string $slug): bool
    {
        if ($parent === null) {
            return false;
        }
        $structureId = $this->sectionsOf($pagesSchema, $site, $locale)[$parent] ?? null;
        $schemaId = $structureId === null ? null : ($this->storages($site)[$structureId]['schema'] ?? null);

        return $schemaId !== null && $this->read->keyHolderIds($schemaId, $site, $locale, SitePagePath::SLUG_FIELD, $slug) !== [];
    }

    /** Why a record may not take this slug: a slug is one record's in the whole storage, in any of its languages. */
    public function whyNotSlug(string $schemaId, ?string $recordId, string $slug): ?string
    {
        if (!SitePagePath::isSlug($slug)) {
            return self::SLUG_INVALID;
        }
        $database = $this->tree->connection();
        foreach ($database->table(self::RECORDS . ' as r')
            ->join(self::VERSIONS . ' as v', static function ($join): void {
                $join->on('v.schema_id', '=', 'r.schema_id')->on('v.record_id', '=', 'r.record_id')->on('v.revision', '=', 'r.current_revision');
            })
            ->where('r.schema_id', $schemaId)->where('v.values_json->' . SitePagePath::SLUG_FIELD, $slug)
            ->when($recordId !== null, static fn ($query) => $query->where('r.record_id', '!=', $recordId))
            ->get(['v.values_json']) as $row) {
            $values = json_decode((string) $row->values_json, true);
            if (!is_array($values) || ($values[self::STATE_FIELD] ?? null) !== 'archived') {
                return self::SLUG_TAKEN;
            }
        }
        if (!$database->getSchemaBuilder()->hasTable(self::LOCALIZED)) {
            return null;
        }
        $translated = $database->table(self::LOCALIZED . ' as l')
            ->join(self::RECORDS . ' as r', static function ($join): void {
                $join->on('r.schema_id', '=', 'l.schema_id')->on('r.record_id', '=', 'l.record_id')->on('r.current_revision', '=', 'l.revision');
            })
            ->where('l.schema_id', $schemaId)->where('l.field_key', SitePagePath::SLUG_FIELD)->where('l.value_json', json_encode($slug, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->when($recordId !== null, static fn ($query) => $query->where('l.record_id', '!=', $recordId))
            ->exists();

        return $translated ? self::SLUG_TAKEN : null;
    }

    private function sectionAddress(string $schemaId, string $site, string $locale): ?string
    {
        $pagesSchema = $this->tree->schemaOf($site);
        $section = $pagesSchema === null ? null : $this->sectionOf($pagesSchema, $schemaId, $site, $locale);
        if ($pagesSchema === null || $section === null) {
            return null;
        }
        $address = $this->addresses->addressOf($pagesSchema, $site, $locale, $section);
        $source = $address === null ? $this->sourceLanguage($site, $locale) : null;

        return $address ?? ($source === null ? null : $this->addresses->addressOf($pagesSchema, $site, $source, $section));
    }

    /** The page that gives out this schema's records in a language. */
    private function sectionOf(string $pagesSchema, string $schemaId, string $site, string $locale): ?string
    {
        $storages = $this->storages($site);
        foreach ($this->sectionsOf($pagesSchema, $site, $locale) as $pageId => $structureId) {
            if (($storages[$structureId]['schema'] ?? null) === $schemaId) {
                return (string) $pageId;
            }
        }

        return null;
    }

    private function sourceLanguage(string $site, string $locale): ?string
    {
        if ($this->languages === null || $this->languages->ownStructure($site)) {
            return null;
        }
        $main = $this->languages->main($site);

        return $main === $locale ? null : $main;
    }
}
