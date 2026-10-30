<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Larena\Core\Contracts\SiteLanguages;
use Larena\Core\Site\SiteLanguageFolders;
use Larena\Storage\Contracts\LocalizedValueGuard;
use Larena\Storage\Contracts\LocalizedValueObserver;
use Larena\Storage\Contracts\PublicationGuard;
use Larena\Storage\Contracts\PublicationObserver;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Contracts\RecordTreeListener;
use Larena\Storage\Contracts\SitePageAddressObserver;
use Larena\Storage\Exceptions\LocalizedValueRejected;
use Larena\Storage\Exceptions\PublicationRejected;
use Larena\Storage\Exceptions\RelationRejected;
use Larena\Storage\Runtime\DatabasePublicationLifecycle;
use Larena\Storage\SiteRedirect\SiteRedirectPath;
use Larena\Storage\SiteRedirect\SiteRedirects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A site page's address changes where the public sees it change: when a revision with another slug is published, or
 * when the page is moved (a move takes effect at once; a tree has no drafts). This class sees both and does what follows
 * (owner decision 35, sites pages §5):
 *
 * - the checks of SitePageRules before the change (a translated slug that would move an address unpublished is refused);
 * - the addresses' cache forgotten, once the transaction around the change commits;
 * - where the page was published at the old address and is reachable at the new one, one prefix redirect 301 old → new
 *   through SiteRedirects::ensure: it answers the old address and every address below it. A page of the main language is
 *   also seen in the other languages' folders while it has no address of its own there, so those get their rule too. The address taken up frees it
 *   from an automatic rule pointing away from it (a rename back). ensure() writes as the person, who must have the site
 *   as their current one and may write its rules; when they may not (or a schedule publishes, with no person), the
 *   change stands and the missing rule is logged: a page must not stay unpublished for a rule's sake;
 * - the pages below hear of it (SitePageAddressObserver), and so does a moved page.
 *
 * A page published at no address before gets no rule; nor does one that is no longer reachable.
 */
final class SitePageAddressChanges implements PublicationGuard, PublicationObserver, RecordTreeListener, LocalizedValueObserver, LocalizedValueGuard
{
    public const NOTIFY_LIMIT = 1000;

    /** @var array<string, ?string> a page's address before its publication, by publication */
    private array $beforePublication = [];

    /** @var array<string, list<array{site: string, locale: string, address: ?string}>> a page's addresses before a move, by record */
    private array $beforeMove = [];

    /**
     * @param Closure(): SitePageAddressCache $cache the request's
     * @param Closure(): SiteRedirects $redirects
     * @param Closure(): ?SitePageAddressObserver $observer
     */
    public function __construct(
        private readonly SitePageTree $tree,
        private readonly SitePageAddresses $addresses,
        private readonly SitePageRules $rules,
        private readonly Closure $cache,
        private readonly Closure $redirects,
        private readonly Closure $observer,
        private readonly ?LoggerInterface $log = null,
        private readonly ?Translator $translator = null,
        private readonly ?SiteLanguageFolders $folders = null,
        private readonly ?SiteLanguages $languages = null,
        private readonly ?SiteRecordAddresses $records = null,
    ) {
    }

    public function assertMayPublish(string $schemaId, string $recordId, string $scopeRef, string $locale, int $revision): void
    {
        $why = $this->rules->whyNotPublish($schemaId, $recordId, $scopeRef, $locale, $revision);
        if ($why !== null) {
            throw new PublicationRejected($why);
        }
        if ($this->records?->isRecordOf($schemaId, $scopeRef) === true) {
            $this->beforePublication[self::key($schemaId, $recordId, $scopeRef, $locale)] = $this->records->addressOf($schemaId, $scopeRef, $locale, $recordId);

            return;
        }
        if (!$this->tree->isPageOf($schemaId, $scopeRef)) {
            return;
        }
        $this->beforePublication[self::key($schemaId, $recordId, $scopeRef, $locale)] = $this->addresses->addressOf($schemaId, $scopeRef, $locale, $recordId);
    }

    public function publicationChanged(PublicationState $state, int $projectionVersion): void
    {
        if ($this->records?->isRecordOf($state->schemaId, $state->scopeRef) === true) {
            $this->recordChanged($state);

            return;
        }
        if (!$this->tree->isPageOf($state->schemaId, $state->scopeRef)) {
            return;
        }
        $this->forget($state->scopeRef);
        $key = self::key($state->schemaId, $state->recordId, $state->scopeRef, $state->locale);
        $published = array_key_exists($key, $this->beforePublication);
        $before = $this->beforePublication[$key] ?? null;
        unset($this->beforePublication[$key]);
        $after = $state->isPublished() ? $this->addresses->addressOf($state->schemaId, $state->scopeRef, $state->locale, $state->recordId) : null;
        if ($published && $after !== null && $after !== $before) {
            [$actor, $correlationId] = $this->lastWriter($state);
            $this->followEverywhere($state->schemaId, $state->recordId, $state->scopeRef, $state->locale, $before, $after, $actor, $correlationId);
        }
        // What stands below moved with it, or went with it, whichever transition this was.
        $this->notify($state->schemaId, $state->scopeRef, $state->locale, $this->tree->descendants($state->schemaId, $state->recordId, self::NOTIFY_LIMIT));
    }

    /** A record's address moved with its publication: its old one leads to the new one, that address alone. */
    private function recordChanged(PublicationState $state): void
    {
        $this->forget($state->scopeRef);
        $key = self::key($state->schemaId, $state->recordId, $state->scopeRef, $state->locale);
        $before = $this->beforePublication[$key] ?? null;
        unset($this->beforePublication[$key]);
        $after = $state->isPublished() ? $this->records?->addressOf($state->schemaId, $state->scopeRef, $state->locale, $state->recordId) : null;
        if ($after !== null && $before !== null && $after !== $before) {
            [$actor, $correlationId] = $this->lastWriter($state);
            $this->follow($state->scopeRef, $state->locale, $before, $after, $actor, $correlationId, SiteRedirectPath::MATCH_EXACT);
        }
    }

    public function placing(string $relationKey, string $schemaId, string $recordId, ?string $newParentRecordId): void
    {
        if ($relationKey !== SitePagePath::RELATION || $this->tree->sitesOf($schemaId) === []) {
            return;
        }
        $why = $this->rules->whyNotMove($schemaId, $recordId, $newParentRecordId);
        if ($why !== null) {
            throw new RelationRejected($why);
        }
        $before = [];
        foreach ($this->tree->publishedPlaces($schemaId, $recordId) as $place) {
            $before[] = $place + ['address' => $this->addresses->addressOf($schemaId, $place['site'], $place['locale'], $recordId)];
        }
        $this->beforeMove[$schemaId . "\n" . $recordId] = $before;
    }

    public function placed(string $relationKey, string $schemaId, string $recordId, ?string $newParentRecordId, string $actor, ?string $correlationId): void
    {
        if ($relationKey !== SitePagePath::RELATION) {
            return;
        }
        $sites = $this->tree->sitesOf($schemaId);
        foreach ($sites as $site) {
            $this->forget($site);
        }
        $key = $schemaId . "\n" . $recordId;
        $before = $this->beforeMove[$key] ?? [];
        unset($this->beforeMove[$key]);
        $moved = [$recordId, ...$this->tree->descendants($schemaId, $recordId, self::NOTIFY_LIMIT - 1)];
        foreach ($before as $place) {
            $after = $this->addresses->addressOf($schemaId, $place['site'], $place['locale'], $recordId);
            if ($place['address'] !== null && $after !== null && $after !== $place['address']) {
                $this->followEverywhere($schemaId, $recordId, $place['site'], $place['locale'], $place['address'], $after, $actor, $correlationId);
            }
            $this->notify($schemaId, $place['site'], $place['locale'], $moved);
        }
    }

    public function removed(string $relationKey, string $schemaId): void
    {
        if ($relationKey === SitePagePath::RELATION) {
            foreach ($this->tree->sitesOf($schemaId) as $site) {
                $this->forget($site);
            }
        }
    }

    /** A translated slug is refused where it would move an address that is out; see SitePageRules::whyNotTranslate(). */
    public function assertMayWriteLocalized(string $schemaId, string $recordId, int $revision, string $locale, array $values): void
    {
        if (!array_key_exists(SitePagePath::SLUG_FIELD, $values)) {
            return;
        }
        $why = $this->rules->whyNotTranslate($schemaId, $recordId, $revision, $locale, $values[SitePagePath::SLUG_FIELD]);
        if ($why === null && $this->records !== null && $this->records->sitesOf($schemaId) !== []) {
            $why = $this->tree->placesOfRevision($schemaId, $recordId, $revision) !== [] ? SitePageRules::SLUG_PUBLISHED : null;
        }
        if ($why !== null) {
            throw new LocalizedValueRejected($why);
        }
    }

    /** Only a revision that is out can change an address: a draft's translations, carried or new, leave the map as it is. */
    public function localizedValuesWritten(string $schemaId, string $recordId, int $revision, string $locale): void
    {
        if ($this->tree->placesOfRevision($schemaId, $recordId, $revision) === []) {
            return;
        }
        foreach ([...$this->tree->sitesOf($schemaId), ...($this->records?->sitesOf($schemaId) ?? [])] as $site) {
            $this->forget($site);
        }
    }

    /** Now, for this process's own next read, and again for every other once the change is visible to it. */
    private function forget(string $site): void
    {
        $forget = function () use ($site): void {
            ($this->cache)()->forget($site);
        };
        $forget();
        $database = $this->tree->connection();
        if ($database->transactionLevel() > 0) {
            $database->afterCommit($forget);
        }
    }

    /** The rule in the language it changed in and, for the main language, in those that show the page in its source text. */
    private function followEverywhere(string $schemaId, string $recordId, string $site, string $locale, ?string $from, string $to, string $actor, ?string $correlationId): void
    {
        $this->follow($site, $locale, $from, $to, $actor, $correlationId);
        if ($this->languages === null || $locale !== $this->languages->main($site) || $this->languages->ownStructure($site)) {
            return;
        }
        foreach ($this->languages->languages($site) as $language) {
            if ($language !== $locale && $this->addresses->addressOf($schemaId, $site, $language, $recordId) === null) {
                $this->follow($site, $language, $from, $to, $actor, $correlationId);
            }
        }
    }

    /** $from and $to are addresses in the language's site; a rule names them as a visitor opens them, its folder included. */
    private function follow(string $site, string $locale, ?string $from, string $to, string $actor, ?string $correlationId, string $match = SiteRedirectPath::MATCH_PREFIX): void
    {
        try {
            $redirects = ($this->redirects)();
            $redirects->release($site, $this->inFolder($site, $locale, $to), $actor, $correlationId);
            if ($from !== null && $from !== SitePagePath::HOME_ADDRESS) {
                $redirects->ensure($site, $this->inFolder($site, $locale, $from), $this->inFolder($site, $locale, $to), 301, $match, $this->note(), $actor, $correlationId);
            }
        } catch (Throwable $failure) {
            $this->log?->warning('site_page_redirect_not_written', ['site' => $site, 'from' => $from, 'to' => $to, 'reason' => $failure->getMessage()]);
        }
    }

    private function inFolder(string $site, string $locale, string $address): string
    {
        return $this->folders === null ? $address : $this->folders->address($site, $locale, $address);
    }

    /** @param list<string> $recordIds */
    private function notify(string $schemaId, string $site, string $locale, array $recordIds): void
    {
        if ($recordIds === []) {
            return;
        }
        try {
            $observer = ($this->observer)();
            if ($observer !== null) {
                $this->tree->nextAddressEpoch($site);
                $observer->addressesChanged($site, $locale, $recordIds);
            }
        } catch (Throwable $failure) {
            $this->log?->warning('site_page_address_observer_failed', ['schema' => $schemaId, 'site' => $site, 'reason' => $failure->getMessage()]);
        }
    }

    /** @return array{0: string, 1: ?string} who made the transition just logged, and its correlation */
    private function lastWriter(PublicationState $state): array
    {
        $row = $this->tree->connection()->table(DatabasePublicationLifecycle::LOG_TABLE)
            ->where('publication_id', $state->publicationId)->orderByDesc('id')->first(['actor_id', 'correlation_id']);

        return [is_object($row) ? (string) $row->actor_id : '', is_object($row) && is_string($row->correlation_id) ? $row->correlation_id : null];
    }

    private function note(): string
    {
        $note = $this->translator?->get('larena-storage::admin.site_pages.redirect_note');

        return is_string($note) && !str_starts_with($note, 'larena-storage::') ? $note : 'The page\'s address changed.';
    }

    private static function key(string $schemaId, string $recordId, string $site, string $locale): string
    {
        return $schemaId . "\n" . $recordId . "\n" . $site . "\n" . $locale;
    }
}
