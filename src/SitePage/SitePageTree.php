<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Illuminate\Database\Connection;
use Larena\Storage\Enums\PublicationStateValue;
use Larena\Storage\Enums\RelationKind;
use Larena\Storage\Enums\RelationStatus;
use Larena\Storage\Exceptions\ReadContractRejected;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\Runtime\DatabaseRecordRelations;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;
use Larena\Storage\Runtime\RelationPath;

/**
 * Where a site's pages stand: the storage that plays the pages' role, the tree's edges (the relation's materialized
 * path, so no walk) and the current values a page's section compares. A page with no edge, or with an edge to itself (a
 * detached one), is at the top level. A slug may be translated (sites plan, step 4.3): with a language named it reads
 * as that language shows it, along the fallback chain to the shared value; with none, the shared value.
 */
final readonly class SitePageTree
{
    private const RECORDS = 'larena_storage_records';

    private const VERSIONS = 'larena_storage_record_versions';

    private const STATE_FIELD = 'larena_record_state';

    private const ARCHIVED = 'archived';

    private const EPOCHS = 'larena_storage_site_address_epochs';

    private const LOCALIZED = 'larena_storage_localized_values';

    private const BATCH = 500;

    public function __construct(private Connection $database, private DatabaseReadContracts $read)
    {
    }

    public function connection(): Connection
    {
        return $this->database;
    }

    /** The schema of a site's pages, the site's choice where it has several; null while it has none. */
    public function schemaOf(string $site): ?string
    {
        try {
            return $this->read->roleSchema(SitePagePath::ROLE, $site);
        } catch (ReadContractRejected) {
            return null;
        }
    }

    /**
     * The sites a schema holds pages of: usually one, none for any other storage.
     *
     * @return list<string>
     */
    public function sitesOf(string $schemaId): array
    {
        return array_values(array_map('strval', $this->database->table(DatabaseStructureRoleRegistry::BINDINGS_TABLE)
            ->where('role_ref', SitePagePath::ROLE)
            ->where('schema_id', $schemaId)
            ->where('status', 'active')
            ->orderBy('scope_ref')
            ->pluck('scope_ref')
            ->all()));
    }

    public function isPageOf(string $schemaId, string $site): bool
    {
        return in_array($site, $this->sitesOf($schemaId), true);
    }

    public function parentOf(string $schemaId, string $recordId): ?string
    {
        $parent = $this->edges($schemaId)->where('from_record_id', $recordId)->value('to_record_id');

        return is_string($parent) && $parent !== $recordId ? $parent : null;
    }

    /**
     * Every page with a parent: child => parent.
     *
     * @return array<string, string>
     */
    public function parents(string $schemaId): array
    {
        $parents = [];
        foreach ($this->edges($schemaId)->get(['from_record_id', 'to_record_id']) as $edge) {
            $child = (string) $edge->from_record_id;
            $parent = (string) $edge->to_record_id;
            if ($child !== $parent) {
                $parents[$child] = $parent;
            }
        }

        return $parents;
    }

    /** @return list<string> a page's ancestors, root first */
    public function ancestors(string $schemaId, string $recordId): array
    {
        $path = $this->edges($schemaId)->where('from_record_id', $recordId)->value('path');
        if (!is_string($path) || $path === '') {
            return [];
        }

        return array_values(array_diff(RelationPath::parse($path)->ancestorIds(), [$recordId]));
    }

    /**
     * The pages under a page, at most $limit.
     *
     * @return list<string>
     */
    public function descendants(string $schemaId, string $recordId, int $limit): array
    {
        $path = $this->edges($schemaId)->where('from_record_id', $recordId)->value('path');
        $prefix = (is_string($path) && $path !== '' ? RelationPath::parse($path) : RelationPath::root($recordId))->descendantPrefix();

        return array_values(array_map('strval', $this->edges($schemaId)->where('path', 'like', $prefix . '%')
            ->limit($limit)->pluck('from_record_id')->all()));
    }

    /** The top-level page whose current slug is `home`, the site's Home once published. */
    public function homeId(string $schemaId): ?string
    {
        foreach (array_chunk($this->topLevel($schemaId), self::BATCH) as $chunk) {
            $found = $this->holders($schemaId, $chunk, SitePagePath::HOME_SLUG);
            if ($found !== []) {
                return $found[0];
            }
        }

        return null;
    }

    /** Whether a page placed under $parent stands at the site's top level of addresses. */
    public function isTopLevel(string $schemaId, ?string $parent): bool
    {
        return $parent === null || $parent === $this->homeId($schemaId);
    }

    /**
     * The pages of the section a page under $parent stands in, this one aside: its siblings by address.
     *
     * @return list<string>
     */
    public function section(string $schemaId, ?string $parent, ?string $except = null): array
    {
        $ids = $this->isTopLevel($schemaId, $parent)
            ? [...$this->topLevel($schemaId), ...(($home = $this->homeId($schemaId)) === null ? [] : $this->children($schemaId, $home))]
            : $this->children($schemaId, $parent);

        return array_values(array_unique(array_diff($ids, $except === null ? [] : [$except])));
    }

    public function currentSlug(string $schemaId, string $recordId, ?string $locale = null): ?string
    {
        if ($locale !== null) {
            $revision = $this->database->table(self::RECORDS)->where('schema_id', $schemaId)->where('record_id', $recordId)->value('current_revision');

            return $revision === null ? null
                : ($this->read->keyValuesAt($schemaId, [['record_id' => $recordId, 'revision' => (int) $revision]], $locale, SitePagePath::SLUG_FIELD)[$recordId] ?? null);
        }
        $values = $this->database->table(self::RECORDS . ' as r')
            ->join(self::VERSIONS . ' as v', static function ($join): void {
                $join->on('v.schema_id', '=', 'r.schema_id')->on('v.record_id', '=', 'r.record_id')->on('v.revision', '=', 'r.current_revision');
            })
            ->where('r.schema_id', $schemaId)->where('r.record_id', $recordId)->value('v.values_json');
        $decoded = is_string($values) ? json_decode($values, true) : null;
        $slug = is_array($decoded) ? ($decoded[SitePagePath::SLUG_FIELD] ?? null) : null;

        return is_string($slug) ? $slug : null;
    }

    /** A page's order among its siblings, as its current values have it. */
    public function currentOrder(string $schemaId, string $recordId): ?int
    {
        return $this->orders($schemaId, [$recordId])[$recordId] ?? null;
    }

    /** The order that puts a new page after the others of the section it goes to. */
    public function nextOrder(string $schemaId, ?string $parent): int
    {
        $orders = $this->orders($schemaId, $this->section($schemaId, $parent));

        return $orders === [] ? 0 : max($orders) + 1;
    }

    /**
     * @param list<string> $recordIds
     * @return array<string, int>
     */
    private function orders(string $schemaId, array $recordIds): array
    {
        $orders = [];
        foreach (array_chunk($recordIds, 500) as $chunk) {
            foreach ($this->database->table(self::RECORDS . ' as r')
                ->join(self::VERSIONS . ' as v', static function ($join): void {
                    $join->on('v.schema_id', '=', 'r.schema_id')->on('v.record_id', '=', 'r.record_id')->on('v.revision', '=', 'r.current_revision');
                })
                ->where('r.schema_id', $schemaId)->whereIn('r.record_id', $chunk)->get(['r.record_id', 'v.values_json']) as $row) {
                $values = json_decode((string) $row->values_json, true);
                $order = is_array($values) ? ($values[SitePagePath::ORDER_FIELD] ?? null) : null;
                if (is_int($order)) {
                    $orders[(string) $row->record_id] = $order;
                }
            }
        }

        return $orders;
    }

    /**
     * The current slugs of pages, by record: what the admin's address of an unpublished page reads.
     *
     * @param list<string> $recordIds
     * @return array<string, string>
     */
    public function currentSlugs(string $schemaId, array $recordIds): array
    {
        $slugs = [];
        foreach ($recordIds as $recordId) {
            $slug = $this->currentSlug($schemaId, $recordId);
            if ($slug !== null) {
                $slugs[$recordId] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * The translated slugs of a page's current revision, by language.
     *
     * @return array<string, string>
     */
    public function translatedSlugs(string $schemaId, string $recordId): array
    {
        return $this->translatedSlugsOf($schemaId, [$recordId])[$recordId] ?? [];
    }

    /**
     * Whether a page of $ids stands at this slug, archived ones aside. In a language: as each shows it there. With none
     * (a shared slug, written for $writer, null for a new page): a page with that shared slug, or a page with that
     * translation in a language where the writer would show the shared slug.
     *
     * @param list<string> $ids
     */
    public function slugTaken(string $schemaId, array $ids, string $slug, ?string $locale = null, ?string $writer = null): bool
    {
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            if ($locale !== null) {
                if (in_array($slug, $this->read->keyValuesAt($schemaId, $this->activeHeads($schemaId, $chunk), $locale, SitePagePath::SLUG_FIELD), true)) {
                    return true;
                }
                continue;
            }
            if ($this->holders($schemaId, $chunk, $slug) !== []) {
                return true;
            }
            $own = $writer === null ? [] : $this->translatedSlugs($schemaId, $writer);
            foreach ($this->translatedSlugsOf($schemaId, $chunk) as $translated) {
                foreach ($translated as $language => $value) {
                    if ($value === $slug && array_intersect($this->read->localeChain((string) $language)->locales, array_map('strval', array_keys($own))) === []) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether a revision of a page is out, in a language: what a change to its translations changes for the public.
     *
     * @return list<array{site: string, locale: string}> where it is the published head
     */
    public function placesOfRevision(string $schemaId, string $recordId, int $revision): array
    {
        $places = [];
        foreach ($this->database->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->where('schema_id', $schemaId)->where('record_id', $recordId)
            ->where('state', PublicationStateValue::Published->value)->where('published_revision', $revision)
            ->get(['scope_ref', 'locale']) as $row) {
            $places[] = ['site' => (string) $row->scope_ref, 'locale' => (string) $row->locale];
        }

        return $places;
    }

    /**
     * The shared slug of a revision, whatever the languages show: what names a page's assembly (`site.{key}`).
     */
    public function sharedSlugAt(string $schemaId, string $recordId, int $revision): ?string
    {
        $values = $this->database->table(self::VERSIONS)->where('schema_id', $schemaId)->where('record_id', $recordId)->where('revision', $revision)->value('values_json');
        $decoded = is_string($values) ? json_decode($values, true) : null;
        $slug = is_array($decoded) ? ($decoded[SitePagePath::SLUG_FIELD] ?? null) : null;

        return is_string($slug) ? $slug : null;
    }

    /**
     * Where a page is published: its sites and languages.
     *
     * @return list<array{site: string, locale: string}>
     */
    public function publishedPlaces(string $schemaId, string $recordId): array
    {
        $places = [];
        foreach ($this->database->table(DatabasePublicationLifecycle::STATES_TABLE)
            ->where('schema_id', $schemaId)->where('record_id', $recordId)
            ->where('state', PublicationStateValue::Published->value)
            ->orderBy('scope_ref')->orderBy('locale')
            ->get(['scope_ref', 'locale']) as $row) {
            $places[] = ['site' => (string) $row->scope_ref, 'locale' => (string) $row->locale];
        }

        return $places;
    }

    /** How many times the site's addresses changed with no publication of the pages concerned; see the table's migration. */
    public function addressEpoch(string $site): int
    {
        if (!$this->database->getSchemaBuilder()->hasTable(self::EPOCHS)) {
            return 1;
        }

        return max(1, (int) $this->database->table(self::EPOCHS)->where('site_ref', $site)->value('epoch'));
    }

    public function nextAddressEpoch(string $site): void
    {
        if (!$this->database->getSchemaBuilder()->hasTable(self::EPOCHS)) {
            return;
        }
        $this->database->table(self::EPOCHS)->updateOrInsert(['site_ref' => $site],
            ['epoch' => $this->addressEpoch($site) + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** @return \Illuminate\Database\Query\Builder the active tree edges of the schema */
    private function edges(string $schemaId): \Illuminate\Database\Query\Builder
    {
        return $this->database->table(DatabaseRecordRelations::TABLE)
            ->where('relation_key', SitePagePath::RELATION)
            ->where('schema_id', $schemaId)
            ->where('kind', RelationKind::TreeParent->value)
            ->where('status', RelationStatus::Active->value);
    }

    /** @return list<string> */
    private function children(string $schemaId, string $parent): array
    {
        return array_values(array_map('strval', $this->edges($schemaId)->where('to_record_id', $parent)
            ->where('from_record_id', '!=', $parent)->pluck('from_record_id')->all()));
    }

    /** @return list<string> the pages with no parent, in one query however many pages sit below them */
    private function topLevel(string $schemaId): array
    {
        return array_values(array_map('strval', $this->database->table(self::RECORDS . ' as r')
            ->leftJoin(DatabaseRecordRelations::TABLE . ' as e', static function ($join): void {
                $join->on('e.schema_id', '=', 'r.schema_id')->on('e.from_record_id', '=', 'r.record_id')
                    ->where('e.relation_key', SitePagePath::RELATION)
                    ->where('e.kind', RelationKind::TreeParent->value)
                    ->where('e.status', RelationStatus::Active->value)
                    ->whereColumn('e.to_record_id', '!=', 'e.from_record_id');
            })
            ->where('r.schema_id', $schemaId)
            ->whereNull('e.relation_id')
            ->orderBy('r.created_at')->orderBy('r.record_id')
            ->pluck('r.record_id')
            ->all()));
    }

    /**
     * The current revisions of the pages of $ids, archived ones aside.
     *
     * @param list<string> $ids
     * @return list<array{record_id: string, revision: int}>
     */
    private function activeHeads(string $schemaId, array $ids): array
    {
        $heads = [];
        foreach ($this->database->table(self::RECORDS . ' as r')
            ->join(self::VERSIONS . ' as v', static function ($join): void {
                $join->on('v.schema_id', '=', 'r.schema_id')->on('v.record_id', '=', 'r.record_id')->on('v.revision', '=', 'r.current_revision');
            })
            ->where('r.schema_id', $schemaId)->whereIn('r.record_id', $ids)
            ->orderBy('r.created_at')->orderBy('r.record_id')
            ->get(['r.record_id', 'r.current_revision', 'v.values_json']) as $row) {
            $values = json_decode((string) $row->values_json, true);
            if (!is_array($values) || ($values[self::STATE_FIELD] ?? null) !== self::ARCHIVED) {
                $heads[] = ['record_id' => (string) $row->record_id, 'revision' => (int) $row->current_revision];
            }
        }

        return $heads;
    }

    /**
     * The translated slugs of the current revisions of $ids, archived ones aside: record => language => slug.
     *
     * @param list<string> $ids
     * @return array<string, array<string, string>>
     */
    private function translatedSlugsOf(string $schemaId, array $ids): array
    {
        if ($ids === [] || !$this->database->getSchemaBuilder()->hasTable(self::LOCALIZED)) {
            return [];
        }
        $revisions = [];
        foreach ($this->activeHeads($schemaId, $ids) as $head) {
            $revisions[$head['record_id']] = $head['revision'];
        }
        $slugs = [];
        foreach ($this->database->table(self::LOCALIZED)
            ->where('schema_id', $schemaId)->whereIn('record_id', array_map('strval', array_keys($revisions)))
            ->whereIn('revision', array_values(array_unique($revisions)))->where('field_key', SitePagePath::SLUG_FIELD)
            ->get(['record_id', 'revision', 'locale', 'value_json']) as $row) {
            $value = json_decode((string) $row->value_json, true);
            if (($revisions[(string) $row->record_id] ?? null) === (int) $row->revision && is_string($value)) {
                $slugs[(string) $row->record_id][(string) $row->locale] = $value;
            }
        }

        return $slugs;
    }

    /**
     * @param list<string> $ids
     * @return list<string> the pages of $ids with this current shared slug, archived ones aside
     */
    private function holders(string $schemaId, array $ids, string $slug): array
    {
        if ($ids === []) {
            return [];
        }
        $holders = [];
        foreach ($this->database->table(self::RECORDS . ' as r')
            ->join(self::VERSIONS . ' as v', static function ($join): void {
                $join->on('v.schema_id', '=', 'r.schema_id')->on('v.record_id', '=', 'r.record_id')->on('v.revision', '=', 'r.current_revision');
            })
            ->where('r.schema_id', $schemaId)
            ->whereIn('r.record_id', $ids)
            ->where('v.values_json->' . SitePagePath::SLUG_FIELD, $slug)
            ->orderBy('r.created_at')->orderBy('r.record_id')
            ->get(['r.record_id', 'v.values_json']) as $row) {
            $values = json_decode((string) $row->values_json, true);
            if (!is_array($values) || ($values[self::STATE_FIELD] ?? null) !== self::ARCHIVED) {
                $holders[] = (string) $row->record_id;
            }
        }

        return $holders;
    }
}
