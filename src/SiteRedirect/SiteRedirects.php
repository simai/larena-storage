<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The redirects other steps write for a site (a page's address changed, the languages' scheme switched): ensure()
 * keeps one automatic rule per path and match, written through the workbench like a person's (so it is checked,
 * collapses chains and drops the site's map) and published at once, since an automatic rule needs no review.
 *
 * A rule goes into the site's «Переадресации» storage (SiteRedirectsStorage), the oldest when an import added more.
 */
final readonly class SiteRedirects
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const PUBLISHED = 'published';

    public const UNCHANGED = 'unchanged';

    /** @param Closure(string): string $defaultLocale the language a new rule of a site is published in */
    public function __construct(
        private ConnectionInterface $database,
        private StorageWorkbench $workbench,
        private PublicationLifecycle $publication,
        private SiteRedirectRules $rules,
        private SiteRedirectValidator $validator,
        private Closure $defaultLocale,
    ) {
    }

    /**
     * Makes the site send $from to $to with $code, an automatic rule with $reason as its note, and publishes it.
     * An existing rule of the path and match (manual or automatic) is updated to it; one that already says so and is
     * published at its current revision is left as it is. Refused like a person's write of the rule, or with
     * `site_redirect_code_invalid`, `site_redirect_match_invalid` or `site_redirects_storage_missing`.
     *
     * @return array{state: string, schema_id: string, record_id: string, revision: int, from_address: string, to_address: string, locales: list<string>}
     */
    public function ensure(string $siteRef, string $from, string $to, int $code, string $match, string $reason, string $actor, ?string $correlationId = null): array
    {
        if (!in_array($code, [301, 302], true)) {
            throw new StorageRejected('site_redirect_code_invalid');
        }
        if (!in_array($match, SiteRedirectPath::MATCHES, true)) {
            throw new StorageRejected('site_redirect_match_invalid');
        }
        $from = SiteRedirectPath::from($from);
        $to = SiteRedirectPath::to($to, $match);
        $note = trim($reason);
        $storages = [];
        foreach ($this->rules->storages($siteRef) as $storage) {
            $storages[$storage['schema_id']] = $storage;
        }
        if ($storages === []) {
            throw new StorageRejected('site_redirects_storage_missing');
        }
        $existing = null;
        foreach ($this->rules->current($siteRef) as $rule) {
            if ($rule->from === $from && $rule->match === $match && isset($storages[$rule->schemaId])) {
                $existing = $rule;
                break;
            }
        }
        $values = ['from_address' => $from, 'to_address' => $to, 'status_code' => (string) $code, 'match' => $match,
            'origin' => SiteRedirectRule::ORIGIN_AUTO] + ($note === '' ? [] : ['note' => $note]);

        return $this->database->transaction(function () use ($siteRef, $storages, $existing, $values, $actor, $correlationId): array {
            if ($existing === null) {
                $storage = array_values($storages)[0];
                $record = $this->workbench->createRecord($storage['scope_ref'], $storage['structure_id'], $values, $actor, $correlationId);
                $state = self::CREATED;
                $locales = [];
            } else {
                $storage = $storages[$existing->schemaId];
                $read = $this->workbench->readRecord($storage['scope_ref'], $storage['structure_id'], $existing->recordId, $actor);
                $current = array_filter($read->values, static fn (string|int $key): bool => !str_starts_with((string) $key, 'larena_'), ARRAY_FILTER_USE_KEY);
                $wanted = ['to_address' => $this->validator->target($siteRef, $values['to_address'], $existing->schemaId, $existing->recordId)] + $values;
                ksort($current);
                ksort($wanted);
                $locales = $this->rules->publishedLocales($existing->schemaId, $existing->recordId, $siteRef);
                if ($current === $wanted && $locales !== []) {
                    $published = true;
                    foreach ($locales as $locale) {
                        $published = $published && $this->publication->head($existing->schemaId, $existing->recordId, $siteRef, $locale)?->publishedRevision === $read->revision;
                    }
                    if ($published) {
                        return self::receipt(self::UNCHANGED, $storage['schema_id'], $read->recordId, $read->revision, $current, $locales);
                    }
                }
                if ($current === $wanted) {
                    $record = $read;
                    $state = self::PUBLISHED;
                } else {
                    $record = $this->workbench->updateRecord($storage['scope_ref'], $storage['structure_id'], $existing->recordId, $read->revision, $values, $actor, $correlationId);
                    $state = self::UPDATED;
                }
            }
            $locales = $locales === [] ? [($this->defaultLocale)($siteRef)] : $locales;
            foreach ($locales as $locale) {
                $this->publication->publish($storage['schema_id'], $record->recordId, $siteRef, $locale, $record->revision, $actor, $correlationId);
            }

            return self::receipt($state, $storage['schema_id'], $record->recordId, $record->revision, $record->values, $locales);
        });
    }

    /**
     * Frees an address a page has taken up again (a rename back): the automatic rules from it are taken off the site and
     * sent to the archive, so the page answers and a rule back to it is no cycle. A person's own rule stays: it is theirs
     * to change. Returns how many rules went.
     */
    public function release(string $siteRef, string $from, string $actor, ?string $correlationId = null): int
    {
        $from = SiteRedirectPath::from($from);
        $storages = [];
        foreach ($this->rules->storages($siteRef) as $storage) {
            $storages[$storage['schema_id']] = $storage;
        }
        $released = 0;
        foreach ($this->rules->current($siteRef) as $rule) {
            $storage = $storages[$rule->schemaId] ?? null;
            if ($rule->from !== $from || $rule->origin !== SiteRedirectRule::ORIGIN_AUTO || $storage === null) {
                continue;
            }
            $this->database->transaction(function () use ($rule, $storage, $siteRef, $actor, $correlationId): void {
                foreach ($this->rules->publishedLocales($rule->schemaId, $rule->recordId, $siteRef) as $locale) {
                    $this->publication->unpublish($rule->schemaId, $rule->recordId, $siteRef, $locale, $actor, $correlationId);
                }
                $read = $this->workbench->readRecord($storage['scope_ref'], $storage['structure_id'], $rule->recordId, $actor);
                $this->workbench->archiveRecord($storage['scope_ref'], $storage['structure_id'], $rule->recordId, $read->revision, $actor, $correlationId);
            });
            ++$released;
        }

        return $released;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $locales
     * @return array{state: string, schema_id: string, record_id: string, revision: int, from_address: string, to_address: string, locales: list<string>}
     */
    private static function receipt(string $state, string $schemaId, string $recordId, int $revision, array $values, array $locales): array
    {
        return [
            'state' => $state,
            'schema_id' => $schemaId,
            'record_id' => $recordId,
            'revision' => $revision,
            'from_address' => is_string($values['from_address'] ?? null) ? $values['from_address'] : '',
            'to_address' => is_string($values['to_address'] ?? null) ? $values['to_address'] : '',
            'locales' => $locales,
        ];
    }
}
