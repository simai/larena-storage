<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\PublicationObserver;
use Larena\Storage\Contracts\PublicationState;

/**
 * A publication transition of a redirect rule (publish, unpublish, schedule, archive, a sweep) drops its site's map,
 * once the transaction around it has committed. Transitions of other records leave the maps as they are.
 *
 * The publication lifecycle takes one observer: Storage composes this one with the application's
 * (PublicationObserverChain).
 */
final readonly class SiteRedirectInvalidation implements PublicationObserver
{
    /** @param Closure(): SiteRedirectCache $cache the request's map cache */
    public function __construct(
        private ConnectionInterface $database,
        private SiteRedirectRules $rules,
        private Closure $cache,
    ) {
    }

    public function publicationChanged(PublicationState $state, int $projectionVersion): void
    {
        if ($this->rules->holdsRules($state->schemaId, $state->scopeRef)) {
            self::forgetAfterCommit($this->database, $this->cache, $state->scopeRef);
        }
    }

    /** @param Closure(): SiteRedirectCache $cache */
    public static function forgetAfterCommit(ConnectionInterface $database, Closure $cache, string $siteRef): void
    {
        $forget = static function () use ($cache, $siteRef): void {
            $cache()->forget($siteRef);
        };
        if ($database instanceof Connection && $database->transactionLevel() > 0) {
            // Now as well: this process reads the change it made; then again for every other once it is visible.
            $forget();
            $database->afterCommit($forget);

            return;
        }
        $forget();
    }
}
