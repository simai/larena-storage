<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Larena\Core\Contracts\SiteLanguages;
use Larena\Core\Contracts\SiteLanguageStructure;
use Larena\Storage\Contracts\SitePageAddressObserver;
use Larena\Storage\Runtime\DatabaseReadContracts;
use Larena\Storage\SiteRedirect\SiteRedirectPath;
use Larena\Storage\SiteRedirect\SiteRedirectRule;
use Larena\Storage\SiteRedirect\SiteRedirectRules;
use Larena\Storage\SiteRedirect\SiteRedirects;
use Larena\Storage\SiteRedirect\SiteRedirectsStorage;

/**
 * A site's language structure as Storage carries it out (sites pages §4.2, §4.4).
 *
 * Common or own structure: no address moves, only what a language shows. Under the own structure a page out only in the
 * main language is not in another language's folder, and the request for it leads to the main language's page with a 302
 * (the site's page route says so at the moment of the request, so nothing is stored and the switch back leaves nothing).
 *
 * «Основной язык тоже в папке»: one 301 rule. On, a site-root rule from `/` to the language's folder; off, the prefix rule
 * from the folder to `/`. The automatic rules' targets move with it, so no chain is left behind.
 */
final readonly class SiteLanguageScheme implements SiteLanguageStructure
{
    /**
     * @param Closure(): SitePageAddressCache $cache the request's
     * @param Closure(): ?SitePageAddressObserver $observer
     */
    public function __construct(
        private SitePageTree $tree,
        private DatabaseReadContracts $read,
        private Closure $cache,
        private SiteLanguages $languages,
        private SiteRedirects $redirects,
        private SiteRedirectRules $rules,
        private SiteRedirectsStorage $storages,
        private Closure $observer,
        private ?Translator $translator = null,
    ) {
    }

    public function preview(string $siteRef, string $scheme, bool $mainInFolder): array
    {
        $main = $this->languages->main($siteRef);
        $own = $this->languages->ownStructure($siteRef);
        $wanted = $scheme === self::OWN;
        $count = 0;
        $samples = [];
        if ($wanted !== $own) {
            $cache = ($this->cache)();
            $pages = $cache->all($siteRef, $main);
            foreach ($this->languages->languages($siteRef) as $language) {
                if ($language === $main) {
                    continue;
                }
                $there = $cache->all($siteRef, $language);
                foreach ($pages as $recordId => $address) {
                    if (!isset($there[$recordId])) {
                        ++$count;
                        if (count($samples) < 5) {
                            $samples[] = '/' . $language . ($address === '/' ? '' : $address);
                        }
                    }
                }
            }
        }
        $was = $this->languages->mainInFolder($siteRef);

        return ['appear' => $wanted ? 0 : $count, 'hide' => $wanted ? $count : 0, 'samples' => $samples,
            'rules' => $mainInFolder === $was ? 0 : count($this->movedRules($siteRef, $main, $mainInFolder)),
            'folder' => $mainInFolder === $was ? null : '/' . $main];
    }

    public function apply(string $siteRef, string $scheme, bool $mainInFolder, string $actor, Closure $settings): void
    {
        $this->tree->connection()->transaction(function () use ($siteRef, $mainInFolder, $actor, $settings): void {
            $moved = $mainInFolder !== $this->languages->mainInFolder($siteRef);
            if ($moved) {
                $this->moveMainLanguage($siteRef, $this->languages->main($siteRef), $mainInFolder, $actor);
            }
            $settings();
            ($this->cache)()->forget($siteRef);
            if ($moved) {
                $this->tellAddressesChanged($siteRef);
            }
        });
    }

    public function mainLanguageInUse(string $siteRef): bool
    {
        $schemaId = $this->tree->schemaOf($siteRef);

        return $schemaId !== null && $this->read->publishedHeadPage($schemaId, $siteRef, $this->languages->main($siteRef), 1, null) !== [];
    }

    private function moveMainLanguage(string $siteRef, string $main, bool $on, string $actor): void
    {
        $folder = '/' . $main;
        $note = $this->note();
        if ($on) {
            $this->storages->offerEveryMatch($siteRef, $actor);
            $this->redirects->release($siteRef, $folder, $actor);
            foreach ($this->movedRules($siteRef, $main, true) as [$rule, $target]) {
                $this->redirects->ensure($siteRef, $rule->from, $target, $rule->code, $rule->match, $note, $actor);
            }
            $this->redirects->ensure($siteRef, SitePagePath::HOME_ADDRESS, $folder, 301, SiteRedirectPath::MATCH_SITE_ROOT, $note, $actor);

            return;
        }
        $this->redirects->release($siteRef, SitePagePath::HOME_ADDRESS, $actor);
        foreach ($this->movedRules($siteRef, $main, false) as [$rule, $target]) {
            $this->redirects->ensure($siteRef, $rule->from, $target, $rule->code, $rule->match, $note, $actor);
        }
        $this->redirects->ensure($siteRef, $folder, SitePagePath::HOME_ADDRESS, 301, SiteRedirectPath::MATCH_PREFIX, $note, $actor);
    }

    /**
     * The automatic rules whose target stands in the main language and their new one. A person's own rule is theirs to
     * change: the site-root rule leads on from its old target.
     *
     * @return list<array{0: SiteRedirectRule, 1: string}>
     */
    private function movedRules(string $siteRef, string $main, bool $on): array
    {
        $folder = '/' . $main;
        $others = array_map(static fn (string $language): string => '/' . $language, array_diff($this->languages->languages($siteRef), [$main]));
        $moved = [];
        foreach ($this->rules->current($siteRef) as $rule) {
            if ($rule->origin !== SiteRedirectRule::ORIGIN_AUTO || $rule->match === SiteRedirectPath::MATCH_SITE_ROOT || SiteRedirectPath::isAbsolute($rule->to)) {
                continue;
            }
            $under = static fn (string $prefix): bool => $rule->to === $prefix || str_starts_with($rule->to, $prefix . '/');
            if ($on && !$under($folder) && array_filter($others, $under) === []) {
                $moved[] = [$rule, $rule->to === '/' ? $folder : $folder . $rule->to];
            } elseif (!$on && $under($folder)) {
                $rest = substr($rule->to, strlen($folder));
                $moved[] = [$rule, $rest === '' ? '/' : $rest];
            }
        }

        return $moved;
    }

    /** The search index keeps a page's address as a visitor opens it: the main language's have a new folder. */
    private function tellAddressesChanged(string $siteRef): void
    {
        $observer = ($this->observer)();
        if ($observer === null) {
            return;
        }
        $main = $this->languages->main($siteRef);
        $this->tree->nextAddressEpoch($siteRef);
        $observer->addressesChanged($siteRef, $main, array_map('strval', array_keys(array_slice(($this->cache)()->all($siteRef, $main), 0, SitePageAddressChanges::NOTIFY_LIMIT, true))));
    }

    private function note(): string
    {
        $note = $this->translator?->get('larena-storage::admin.site_pages.language_folder_note');

        return is_string($note) && !str_starts_with($note, 'larena-storage::') ? $note : 'The main language\'s folder changed.';
    }
}
