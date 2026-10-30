<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Closure;
use Illuminate\Http\Request;
use Larena\Core\Site\CurrentSite;
use Larena\Core\Site\SiteMap;
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
 * `/shop/old`, and its target is `/shop/new`. An absolute target goes where it says.
 */
final readonly class SiteRedirectMiddleware
{
    /** The addresses that are never redirected: the admin and the API. */
    public const SKIPPED = ['/admin', '/api'];

    /**
     * @param Closure(): string $defaultSite the default site's scope ref
     * @param list<string> $skipped
     */
    public function __construct(
        private SiteRedirectCache $maps,
        private Closure $defaultSite,
        private ?RequestSiteScope $sites = null,
        private array $skipped = self::SKIPPED,
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
        $folder = $current instanceof CurrentSite && $current->folder !== '' && SiteMap::inFolder($current->folder, $path) ? $current->folder : '';
        $hit = $this->maps->map($site ?? ($this->defaultSite)())->resolve($folder === '' ? $path : $current->pathInSite($path));
        if ($hit === null) {
            return null;
        }
        $target = $hit['target'];
        $location = SiteRedirectPath::isAbsolute($target) ? $target : $request->getBaseUrl() . $folder . SiteRedirectPath::location($target);
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
}
