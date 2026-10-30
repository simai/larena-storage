<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Closure;
use Larena\Storage\Runtime\DatabaseReadContracts;

/**
 * What a site page's address may be (owner decision 35, sites plan stage 3), said as a reason code a person can be told;
 * the record validator, the publication guard and the tree listener refuse with it, and the admin asks first, because
 * the operations' runtime does not pass a handler's reason on:
 *
 * - `site_page_address_taken`: a sibling in the section already has the slug (current values on a write, published
 *   ones in the language on a publication; a move checks both); the same slug under another parent is fine;
 * - `site_page_slug_reserved`: at the top level, a first segment the application already answers;
 * - `site_page_slug_invalid`: not a latin slug, when a slug is set or changed;
 * - `site_page_home_fixed`: the page published at `/` keeps its slug and its place; a prefix redirect from `/` would
 *   take the whole site with it.
 *
 * A write that leaves the slug as it is passes: a title is never refused for an address made before these rules.
 */
final readonly class SitePageRules
{
    public const ADDRESS_TAKEN = 'site_page_address_taken';

    public const SLUG_RESERVED = 'site_page_slug_reserved';

    public const SLUG_INVALID = 'site_page_slug_invalid';

    public const HOME_FIXED = 'site_page_home_fixed';

    /** @param Closure(): list<string> $reserved the first segments the application answers */
    public function __construct(
        private SitePageTree $tree,
        private SitePageAddresses $addresses,
        private DatabaseReadContracts $read,
        private Closure $reserved,
    ) {
    }

    /** Why a page may not be written with this slug; $parent is where a new page is going. */
    public function whyNotWrite(string $schemaId, ?string $recordId, ?string $parent, string $slug): ?string
    {
        if ($recordId !== null) {
            if ($this->tree->currentSlug($schemaId, $recordId) === $slug) {
                return null;
            }
            $parent = $this->tree->parentOf($schemaId, $recordId);
            if ($this->isPublishedHome($schemaId, $recordId)) {
                return self::HOME_FIXED;
            }
        }
        if (!SitePagePath::isSlug($slug)) {
            return self::SLUG_INVALID;
        }

        return $this->whyNotInSection($schemaId, $recordId, $parent, $slug);
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

    private function whyNotInSection(string $schemaId, ?string $recordId, ?string $parent, string $slug): ?string
    {
        if ($this->isReserved($schemaId, $parent, $slug)) {
            return self::SLUG_RESERVED;
        }

        return $this->tree->slugTaken($schemaId, $this->tree->section($schemaId, $parent, $recordId), $slug) ? self::ADDRESS_TAKEN : null;
    }

    private function isReserved(string $schemaId, ?string $parent, string $slug): bool
    {
        return $slug !== SitePagePath::HOME_SLUG && in_array($slug, ($this->reserved)(), true) && $this->tree->isTopLevel($schemaId, $parent);
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
