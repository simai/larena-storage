<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Closure;
use Larena\Core\Contracts\SiteLanguages;
use Larena\Storage\Runtime\DatabaseReadContracts;

/**
 * What a site page's address may be (owner decision 35, sites plan stage 3), said as a reason code a person can be told;
 * the record validator, the publication guard and the tree listener refuse with it, and the admin asks first, because
 * the operations' runtime does not pass a handler's reason on:
 *
 * - `site_page_address_taken`: a sibling in the section already has the slug (current values on a write, published
 *   ones in the language on a publication; a move checks both); the same slug under another parent is fine;
 * - `site_page_slug_reserved`: at the top level, a first segment the application already answers, or a language of the
 *   site (its folder);
 * - `site_page_slug_invalid`: not a latin slug, when a slug is set or changed;
 * - `site_page_home_fixed`: the page published at `/` keeps its slug and its place; a prefix redirect from `/` would
 *   take the whole site with it. Home is `home` in every language, and no other page takes it in one;
 * - `site_page_slug_published`: a translated slug written onto a revision that is out would move the address with none of
 *   the checks of a publication; it comes with a new revision and its publication.
 *
 * Every check is made in a language when a slug is translated, against what its siblings show there (sites plan, step
 * 4.3); a shared slug is checked against the shared slugs and where it would show.
 *
 * A write that leaves the slug as it is passes: a title is never refused for an address made before these rules.
 */
final readonly class SitePageRules
{
    public const ADDRESS_TAKEN = 'site_page_address_taken';

    public const SLUG_RESERVED = 'site_page_slug_reserved';

    public const SLUG_INVALID = 'site_page_slug_invalid';

    public const HOME_FIXED = 'site_page_home_fixed';

    public const SLUG_PUBLISHED = 'site_page_slug_published';

    /** @param Closure(): list<string> $reserved the first segments the application answers */
    public function __construct(
        private SitePageTree $tree,
        private SitePageAddresses $addresses,
        private DatabaseReadContracts $read,
        private Closure $reserved,
        private ?SiteLanguages $languages = null,
    ) {
    }

    /**
     * Why a page may not be written with this slug; $parent is where a new page is going. With a locale the slug is that
     * language's translation; without one, the shared slug.
     */
    public function whyNotWrite(string $schemaId, ?string $recordId, ?string $parent, string $slug, ?string $locale = null): ?string
    {
        if ($recordId !== null) {
            if ($this->tree->currentSlug($schemaId, $recordId, $locale) === $slug) {
                return null;
            }
            $parent = $this->tree->parentOf($schemaId, $recordId);
            if ($this->isPublishedHome($schemaId, $recordId)) {
                return self::HOME_FIXED;
            }
        }
        if ($locale !== null && $this->breaksHome($schemaId, $recordId, $slug)) {
            return self::HOME_FIXED;
        }
        if (!SitePagePath::isSlug($slug)) {
            return self::SLUG_INVALID;
        }

        return $this->whyNotInSection($schemaId, $recordId, $parent, $slug, $locale);
    }

    /**
     * Why a translation of the slug may not be written onto this revision, whatever writes it: one that is out would
     * move the address unchecked.
     */
    public function whyNotTranslate(string $schemaId, string $recordId, int $revision, string $locale, mixed $slug): ?string
    {
        if ($this->tree->sitesOf($schemaId) === []) {
            return null;
        }
        if (!SitePagePath::isSlug($slug)) {
            return self::SLUG_INVALID;
        }
        foreach ($this->tree->placesOfRevision($schemaId, $recordId, $revision) as $place) {
            if (in_array($locale, $this->read->localeChain($place['locale'])->locales, true)) {
                return self::SLUG_PUBLISHED;
            }
        }

        return null;
    }

    /** Why a page may not go under $newParent. */
    public function whyNotMove(string $schemaId, string $recordId, ?string $newParent): ?string
    {
        if ($this->isPublishedHome($schemaId, $recordId)) {
            return self::HOME_FIXED;
        }
        $slug = $this->tree->currentSlug($schemaId, $recordId);
        if ($slug !== null && SitePagePath::isSlug($slug)) {
            $why = $this->whyNotInSection($schemaId, $recordId, $newParent, $slug);
            if ($why !== null) {
                return $why;
            }
        }
        foreach ($this->tree->translatedSlugs($schemaId, $recordId) as $locale => $translated) {
            $why = SitePagePath::isSlug($translated) ? $this->whyNotInSection($schemaId, $recordId, $newParent, $translated, (string) $locale) : null;
            if ($why !== null) {
                return $why;
            }
        }
        // Each language it is out in: a sibling there may show an older slug than its current one.
        foreach ($this->tree->publishedPlaces($schemaId, $recordId) as $place) {
            $revision = $this->read->publishedRevisions($schemaId, $place['site'], $place['locale'], [$recordId])[$recordId] ?? null;
            $published = $revision === null ? null : ($this->read->keyValuesAt($schemaId, [['record_id' => $recordId, 'revision' => $revision]], $place['locale'], SitePagePath::SLUG_FIELD)[$recordId] ?? null);
            if ($published !== null && $this->publishedSiblingHolds($schemaId, $place['site'], $place['locale'], $recordId, $newParent, $published)) {
                return self::ADDRESS_TAKEN;
            }
        }

        return null;
    }

    /** Why this revision of a page may not become its published head in a language. */
    public function whyNotPublish(string $schemaId, string $recordId, string $site, string $locale, int $revision): ?string
    {
        if (!$this->tree->isPageOf($schemaId, $site)) {
            return null;
        }
        $slug = $this->read->keyValuesAt($schemaId, [['record_id' => $recordId, 'revision' => $revision]], $locale, SitePagePath::SLUG_FIELD)[$recordId] ?? '';
        if ($slug === '') {
            return null;
        }
        if ($slug !== SitePagePath::HOME_SLUG && $this->addresses->addressOf($schemaId, $site, $locale, $recordId) === SitePagePath::HOME_ADDRESS) {
            return self::HOME_FIXED;
        }
        $parent = $this->tree->parentOf($schemaId, $recordId);
        if ($this->isReserved($schemaId, $parent, $slug)) {
            return self::SLUG_RESERVED;
        }

        return $this->publishedSiblingHolds($schemaId, $site, $locale, $recordId, $parent, $slug) ? self::ADDRESS_TAKEN : null;
    }

    private function whyNotInSection(string $schemaId, ?string $recordId, ?string $parent, string $slug, ?string $locale = null): ?string
    {
        if ($this->isReserved($schemaId, $parent, $slug)) {
            return self::SLUG_RESERVED;
        }

        return $this->tree->slugTaken($schemaId, $this->tree->section($schemaId, $parent, $recordId), $slug, $locale, $recordId) ? self::ADDRESS_TAKEN : null;
    }

    private function isReserved(string $schemaId, ?string $parent, string $slug): bool
    {
        if ($slug === SitePagePath::HOME_SLUG || !$this->tree->isTopLevel($schemaId, $parent)) {
            return false;
        }
        $reserved = ($this->reserved)();
        foreach ($this->languages === null ? [] : $this->tree->sitesOf($schemaId) as $site) {
            $reserved = SitePageReservedSlugs::withLanguages($reserved, $this->languages->languages($site));
        }

        return in_array($slug, $reserved, true);
    }

    /** Whether a translated slug leaves Home without `home`, or makes `home` of another page. */
    private function breaksHome(string $schemaId, ?string $recordId, string $slug): bool
    {
        $isHome = $recordId !== null && $this->tree->homeId($schemaId) === $recordId;

        return $isHome ? $slug !== SitePagePath::HOME_SLUG : $slug === SitePagePath::HOME_SLUG;
    }

    private function publishedSiblingHolds(string $schemaId, string $site, string $locale, string $recordId, ?string $parent, string $slug): bool
    {
        $revisions = $this->read->publishedRevisions($schemaId, $site, $locale, $this->tree->section($schemaId, $parent, $recordId));
        if ($revisions === []) {
            return false;
        }
        $heads = [];
        foreach ($revisions as $id => $revision) {
            $heads[] = ['record_id' => (string) $id, 'revision' => $revision];
        }

        return in_array($slug, $this->read->keyValuesAt($schemaId, $heads, $locale, SitePagePath::SLUG_FIELD), true);
    }

    private function isPublishedHome(string $schemaId, string $recordId): bool
    {
        foreach ($this->tree->publishedPlaces($schemaId, $recordId) as $place) {
            if ($this->addresses->addressOf($schemaId, $place['site'], $place['locale'], $recordId) === SitePagePath::HOME_ADDRESS) {
                return true;
            }
        }

        return false;
    }
}
