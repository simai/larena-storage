<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Closure;
use Illuminate\Http\Request;
use Larena\Core\Site\CurrentSite;
use Larena\Core\Site\SiteLanguageFolders;
use Larena\Core\Site\SiteMap;
use Larena\Core\Site\SiteMapStore;
use Larena\Storage\Contracts\RequestSiteScope;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers a public GET or HEAD request with the site's published redirect rule for its path (larena-specs
 * docs/architecture/2026-10-29-sites-work-plan.md, step 2.5): the rule's code (301, 302) and its target, the request's
 * query kept (appended to a target's own). An exact rule wins over a prefix rule; of the prefix rules the longest
 * answers, and its target takes the rest of the path. Any other method, and the admin and API addresses, pass on
 * untouched, as does a path no rule answers.
 *
 * The site is the request's (RequestSiteScope, which the application binds over its site resolution), else the
 * default site. The application adds the middleware to its web group after the site is resolved. The map comes from
 * SiteRedirectCache: a request answered from the cache reads no database.
 *
 * A rule names an address inside its site, a language's folder included: on a `/shop` domain the rule `/old` answers
 * `/shop/old`, and its target is `/shop/new`. An absolute target goes where it says. A rule of a language keeps the
 * language's folder wherever that language is served: on its own domain the rule `/en/old` answers `/old`, its target
 * `/en/new` is `/new`, and a target outside the language goes to the site's main domain. So the rules of two languages on
 * two domains never answer each other's addresses (step 4.7).
 */
final readonly class SiteRedirectMiddleware
{
    /** The addresses that are never redirected: the admin and the API. */
    public const SKIPPED = ['/admin', '/api'];

    /**
     * @param Closure(): string $defaultSite the default site's scope ref
     * @param list<string> $skipped
     * @param (Closure(string, string): bool)|null $kept whether a site keeps a first segment out of its site-root rule
     */
    public function __construct(
        private SiteRedirectCache $maps,
        private Closure $defaultSite,
        private ?RequestSiteScope $sites = null,
        private array $skipped = self::SKIPPED,
        private ?Closure $kept = null,
        private ?SiteLanguageFolders $folders = null,
        private ?SiteMapStore $domains = null,
    ) {
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $redirect = $this->redirect($request);
        if ($redirect !== null) {
            return $redirect;
        }

        return $next($request);
    }

    /** The redirect the request gets, or null when it passes on. */
    public function redirect(Request $request): ?RedirectResponse
    {
        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return null;
        }
        $path = SiteRedirectPath::request($request->getPathInfo());
        if ($path === null) {
            return null;
        }
        foreach ($this->skipped as $skipped) {
            if ($path === $skipped || str_starts_with($path, $skipped . '/')) {
                return null;
            }
        }
        $site = $this->sites?->siteScopeRef($request);
        $current = $request->attributes->get(CurrentSite::ATTRIBUTE);
        $site ??= ($this->defaultSite)();
        $folder = $current instanceof CurrentSite && $current->folder !== '' && SiteMap::inFolder($current->folder, $path) ? $current->folder : '';
        $inSite = $folder === '' ? $path : $current->pathInSite($path);
        // On a language's own domain the rules of that language keep its folder.
        $language = $current instanceof CurrentSite && is_string($current->locale) && $current->locale !== '' && $this->folders !== null
            ? $this->folders->prefix($site, $current->locale) : '';
        $kept = $this->kept === null ? null : fn (string $segment): bool => ($this->kept)($site, $segment);
        $hit = $this->maps->map($site)->resolve(SiteLanguageFolders::under($language, $inSite), $kept);
        if ($hit === null) {
            return null;
        }
        $target = $hit['target'];
        $location = SiteRedirectPath::isAbsolute($target) ? $target : $this->location($request, $current, $site, $folder, $language, $target);
        $query = $request->getQueryString();
        if ($query !== null && $query !== '') {
            [$base, $suffix] = SiteRedirectPath::split($location);
            $fragment = '';
            $hash = strpos($suffix, '#');
            if ($hash !== false) {
                $fragment = substr($suffix, $hash);
                $suffix = substr($suffix, 0, $hash);
            }
            $location = $base . ($suffix === '' ? '?' . $query : $suffix . '&' . $query) . $fragment;
        }

        return new RedirectResponse($location, $hit['code']);
    }

    private function location(Request $request, mixed $current, string $site, string $folder, string $language, string $target): string
    {
        if ($language === '') {
            return $request->getBaseUrl() . $folder . SiteRedirectPath::location($target);
        }
        [$path] = SiteRedirectPath::split($target);
        if ($path === $language || str_starts_with($path, $language . '/')) {
            $rest = substr($target, strlen($language));

            return $request->getBaseUrl() . $folder . SiteRedirectPath::location($rest === '' || $rest[0] !== '/' ? '/' . $rest : $rest);
        }
        $main = $current instanceof CurrentSite ? $this->domains?->get()->mainOf($current->site, null) : null;

        return $main === null || $main->isWildcard() ? $request->getBaseUrl() . $folder . SiteRedirectPath::location($target)
            : $request->getScheme() . '://' . $main->host . ($main->folder ?? '') . SiteRedirectPath::location($target);
    }
}
