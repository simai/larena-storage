<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StructureRoleRecordValidator;
use Larena\Storage\Contracts\StructureRoleRecordWrite;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\StarterStructureRoles;

/**
 * The rules of a site redirect (site_redirect@v1, larena-specs docs/architecture/2026-10-29-sites-pages-languages.md
 * §5), checked on every write of a rule against the site's other current rules (drafts included, archived aside):
 *
 * - `from_address` a site path without a query or fragment, `to_address` a site path or an absolute https URL, both
 *   normalized (SiteRedirectPath): `site_redirect_from_invalid`, `site_redirect_to_invalid`;
 * - not to itself (`site_redirect_same_path`), one rule per path and match (`site_redirect_duplicate`);
 * - no cycle (`site_redirect_cycle`): following the target through the other rules never comes back to a path the
 *   rule answers, nor does any rule the new one would capture;
 * - chains collapse: the rule is stored with its final target (A → B while B → C is stored as A → C), and once it is
 *   written every rule whose target it now answers (X → A) is rewritten to the final target as a new revision,
 *   published again in the languages it was published in.
 *
 * Every write drops the site's map once it commits.
 */
final readonly class SiteRedirectValidator implements StructureRoleRecordValidator
{
    /** More hops than any chain the rules keep (they keep none). */
    private const MAX_HOPS = 32;

    /** @param Closure(): SiteRedirectCache $cache the request's map cache */
    public function __construct(
        private ConnectionInterface $database,
        private SiteRedirectRules $rules,
        private PublicationLifecycle $publication,
        private Closure $cache,
    ) {
    }

    public function roleRef(): string
    {
        return StarterStructureRoles::SITE_REDIRECT_REF;
    }

    public function validate(StructureRoleRecordWrite $write): array
    {
        $values = $write->values;
        $from = $values['from_address'] ?? null;
        $to = $values['to_address'] ?? null;
        $match = $values['match'] ?? null;
        // A value of the wrong kind is the field types' to refuse, with their own reason.
        if (!is_string($from) || !is_string($to) || !in_array($match, [SiteRedirectPath::MATCH_EXACT, SiteRedirectPath::MATCH_PREFIX], true)) {
            return $values;
        }
        $from = SiteRedirectPath::from($from);
        $to = SiteRedirectPath::to($to, $match);
        if (!SiteRedirectPath::isAbsolute($to) && SiteRedirectPath::split($to)[0] === $from) {
            throw new StorageRejected('site_redirect_same_path');
        }
        $others = $this->others($write->roleScopeRef, $write->schemaId, $write->recordId);
        foreach ($others as $other) {
            if ($other->from === $from && $other->match === $match) {
                throw new StorageRejected('site_redirect_duplicate');
            }
        }
        $code = $values['status_code'] ?? null;
        $rule = new SiteRedirectRule($write->schemaId, $write->recordId ?? '', $from, $to, in_array($code, ['301', '302'], true) ? (int) $code : 301, $match,
            ($values['origin'] ?? null) === SiteRedirectRule::ORIGIN_AUTO ? SiteRedirectRule::ORIGIN_AUTO : SiteRedirectRule::ORIGIN_MANUAL);
        $final = self::follow($to, SiteRedirectMap::of($others));
        if (self::answers($rule, $final)) {
            throw new StorageRejected('site_redirect_cycle');
        }
        // The rules it captures must not come back to themselves through it.
        $with = SiteRedirectMap::of([$rule->withTarget($final), ...$others]);
        foreach ($others as $other) {
            if (self::answers($rule, $other->to) && self::answers($other, self::follow($other->to, $with))) {
                throw new StorageRejected('site_redirect_cycle');
            }
        }

        return ['from_address' => $from, 'to_address' => $final] + $values;
    }

    public function written(StructureRoleRecordWrite $write, StorageWorkbenchRecord $record, StorageWorkbench $workbench): void
    {
        $siteRef = $write->roleScopeRef;
        SiteRedirectInvalidation::forgetAfterCommit($this->database, $this->cache, $siteRef);
        $rule = SiteRedirectRule::fromValues($write->schemaId, $record->recordId, $record->values);
        if ($rule === null) {
            return;
        }
        $current = $this->rules->current($siteRef);
        $map = SiteRedirectMap::of($current);
        $storages = [];
        foreach ($this->rules->storages($siteRef) as $storage) {
            $storages[$storage['schema_id']] = $storage;
        }
        foreach ($current as $other) {
            if (($other->schemaId === $rule->schemaId && $other->recordId === $rule->recordId) || !self::answers($rule, $other->to)) {
                continue;
            }
            $target = self::follow($other->to, $map);
            $storage = $storages[$other->schemaId] ?? null;
            if ($target === $other->to || $storage === null) {
                continue;
            }
            $read = $workbench->readRecord($storage['scope_ref'], $storage['structure_id'], $other->recordId, $write->actor);
            $values = array_filter($read->values, static fn (string|int $key): bool => !str_starts_with((string) $key, 'larena_'), ARRAY_FILTER_USE_KEY);
            $values['to_address'] = $target;
            $rewritten = $workbench->updateRecord($storage['scope_ref'], $storage['structure_id'], $other->recordId, $read->revision, $values,
                $write->actor, $write->correlationId);
            foreach ($this->rules->publishedLocales($other->schemaId, $other->recordId, $siteRef) as $locale) {
                $this->publication->publish($other->schemaId, $other->recordId, $siteRef, $locale, $rewritten->revision, $write->actor, $write->correlationId);
            }
        }
    }

    /**
     * Where a target ends after the site's current rules but the one of $recordId (the rule being written): the
     * target itself when no rule answers it. Refused with `site_redirect_cycle` when the rules come back on
     * themselves.
     */
    public function target(string $siteRef, string $target, ?string $schemaId = null, ?string $recordId = null): string
    {
        return self::follow($target, SiteRedirectMap::of($this->others($siteRef, $schemaId, $recordId)));
    }

    /** @return list<SiteRedirectRule> the site's current rules but the one written */
    private function others(string $siteRef, ?string $schemaId, ?string $recordId): array
    {
        return array_values(array_filter($this->rules->current($siteRef),
            static fn (SiteRedirectRule $rule): bool => $recordId === null || $rule->schemaId !== $schemaId || $rule->recordId !== $recordId));
    }

    /** Whether a rule answers a target's path (an absolute URL leaves the site: no rule answers it). */
    private static function answers(SiteRedirectRule $rule, string $target): bool
    {
        return !SiteRedirectPath::isAbsolute($target) && $rule->matches(SiteRedirectPath::split($target)[0]);
    }

    /** A target followed through the map to its end; a query or fragment it had stays unless the end has its own. */
    private static function follow(string $target, SiteRedirectMap $map): string
    {
        $seen = [];
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if (SiteRedirectPath::isAbsolute($target)) {
                return $target;
            }
            [$path, $suffix] = SiteRedirectPath::split($target);
            if (isset($seen[$path])) {
                throw new StorageRejected('site_redirect_cycle');
            }
            $seen[$path] = true;
            $hit = $map->resolve($path);
            if ($hit === null) {
                return $target;
            }
            $target = $suffix !== '' && strpbrk($hit['target'], '?#') === false ? $hit['target'] . $suffix : $hit['target'];
        }

        throw new StorageRejected('site_redirect_cycle');
    }
}
