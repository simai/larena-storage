<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Larena\Core\Contracts\SiteBinding;
use Larena\Core\Contracts\SiteLanguages;
use Larena\Core\Scope\ScopeRef;
use Larena\Core\Site\SiteLanguageFolders;
use Larena\Core\Site\SiteMapStore;

/**
 * The same page in each of a site's languages, by its record and not by its address (sites plan, step 4.6): what the
 * switcher links to and, later, what `hreflang` lists. Each address comes from the map, one cached read per language.
 *
 * A language where the page has its own address links there (`exact`). Where it has none, the common structure shows the
 * page out in the main language with its source text, at that language's folder; the own structure has nothing to show,
 * so the link leads to that language's home and says so (`home`). A language on its own domain links there.
 */
final readonly class SiteLanguageLinks
{
    public function __construct(
        private SitePageAddressCache $addresses,
        private SiteLanguages $languages,
        private SiteLanguageFolders $folders,
        private SiteMapStore $domains,
    ) {
    }

    /**
     * @param ?SiteBinding $binding the domain the request came by
     * @return list<array{locale: string, url: string, current: bool, exact: bool, home: bool}>
     */
    public function for(string $site, string $recordId, string $locale, ?SiteBinding $binding, string $scheme, string $siteFolder = ''): array
    {
        $main = $this->languages->main($site);
        $own = $this->languages->ownStructure($site);
        $links = [];
        foreach ($this->languages->languages($site) as $language) {
            $address = $this->addresses->addressOf($site, $language, $recordId) ?? $this->addresses->itemAddressOf($site, $language, $recordId);
            $exact = $address !== null;
            $home = false;
            if ($address === null) {
                $source = $own ? null : ($this->addresses->addressOf($site, $main, $recordId) ?? $this->addresses->itemAddressOf($site, $main, $recordId));
                $home = $source === null;
                $address = $source ?? SitePagePath::HOME_ADDRESS;
            }
            $links[] = ['locale' => $language, 'url' => $this->url($site, $language, $address, $binding, $scheme, $siteFolder),
                'current' => $language === $locale, 'exact' => $exact, 'home' => $home];
        }

        return $links;
    }

    /**
     * The address a search engine is told to keep: on the main domain of the site and the language, whatever domain the
     * request came by; $origin (the request's) where no main domain is known. Absolute.
     */
    public function canonical(string $site, string $language, string $address, string $scheme, string $origin): string
    {
        $ref = ScopeRef::tryParse($site);
        $domain = $ref === null ? null : $this->domains->get()->mainOf($ref, $language);
        $own = $domain !== null && $domain->locale === $language;
        $path = SiteLanguageFolders::under($own ? '' : $this->folders->prefix($site, $language), $address);
        if ($domain === null || $domain->isWildcard()) {
            return $origin . $path;
        }

        return $scheme . '://' . $domain->host . ($domain->folder ?? '') . $path;
    }

    /**
     * Where the same record stands in each language, for `hreflang`: the languages that show it, its own or its source text,
     * and `x-default`, the main language's. Empty on a record shown in fewer than two languages.
     *
     * @return array<string, string> language or x-default => absolute address
     */
    public function alternates(string $site, string $recordId, string $scheme, string $origin): array
    {
        $main = $this->languages->main($site);
        $own = $this->languages->ownStructure($site);
        $at = fn (string $language): ?string => $this->addresses->addressOf($site, $language, $recordId) ?? $this->addresses->itemAddressOf($site, $language, $recordId);
        $source = $own ? null : $at($main);
        $found = [];
        foreach ($this->languages->languages($site) as $language) {
            $address = $at($language) ?? $source;
            if ($address !== null) {
                $found[$language] = $this->canonical($site, $language, $address, $scheme, $origin);
            }
        }
        if (count($found) < 2) {
            return [];
        }

        return $found + (isset($found[$main]) ? ['x-default' => $found[$main]] : []);
    }

    /** An address of the site's in a language, as a visitor on $binding follows it: a language on another domain is absolute. */
    public function url(string $site, string $language, string $address, ?SiteBinding $binding, string $scheme, string $siteFolder = ''): string
    {
        $ref = ScopeRef::tryParse($site);
        $domain = $ref === null ? null : $this->domains->get()->mainOf($ref, $language);
        // A language with a domain of its own stands at its root; every other in its folder of the site's main domain.
        $own = $domain !== null && $domain->locale === $language;
        $path = SiteLanguageFolders::under($own ? '' : $this->folders->prefix($site, $language), $address);
        if ($domain === null || $domain->isWildcard() || ($binding !== null && $binding->host === $domain->host)) {
            return $siteFolder . $path;
        }
        // No domain was named by the request (the default site): the links stay where the visitor is.
        if ($binding === null && !$own) {
            return $siteFolder . $path;
        }

        return $scheme . '://' . $domain->host . ($domain->folder ?? '') . $path;
    }
}
