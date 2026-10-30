<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Illuminate\Http\Request;

/**
 * The site a public request belongs to, for what Storage answers per site before routing (the site redirects). The
 * application binds it over its own site resolution (core's request-scoped current site); without a binding, or
 * when it answers null, the request is the default site's (ScopeBaselineInstaller::defaultSiteRef()).
 */
interface RequestSiteScope
{
    /** The site's scope ref (site:main), or null when the request names none. */
    public function siteScopeRef(Request $request): ?string;
}
