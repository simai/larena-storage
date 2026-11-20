<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

use InvalidArgumentException;
use Larena\Property\Runtime\CompositionDocument;
use Larena\Storage\Exceptions\PublicationRejected;
use Larena\Storage\Exceptions\StorageConflict;
use Larena\Storage\Exceptions\StoragePersistenceFailed;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * The server side of simai.document-port 1.0.0 (SIMAI Framework, ui-source src/core/contracts/ports/simai.document-port;
 * sf-editor reads it): one storage element, a `composition@1` value with a document per language, answered for the
 * seven intents of the port with one of its four answers.
 *
 * - load: the element as stored, the reader's rights and the limits; with `levels` the resolved document and its area
 *   marks, with `publish` the live publication;
 * - save: a new draft revision for every language against the revision the editor has (`expected_revision`, compared,
 *   never trusted); a stale one is a conflict `stale-revision` carrying the element as stored; languages left out keep
 *   their values, `null` removes one (never the main one); round trip: each value is stored as sent, the `simai:area`
 *   marks of a resolved document removed and every other key kept;
 * - history.list, restore (a new draft with an older revision's values), publish (the current draft, the languages
 *   that have a value), rollback (an earlier publication published again as a new one);
 * - a write with a `client_key` is remembered: the same key with the same input answers what it answered, the same key
 *   with other input is a conflict `idempotency-conflict`.
 *
 * Reasons are the port's hyphenated codes; the store's own refusals are translated here (reason()). A store that
 * cannot be reached is answered `unavailable storage-unavailable`, never thrown. The answer is a PHP array; written as
 * JSON with DocumentPortJson so that an empty object stays `{}`.
 */
final class DocumentPort
{
    public const PORT = 'simai.document-port';

    public const EDITION = '1.0.0';

    /** The digest of the conformance kit this server passes (port.json conformance.sha256, draft 2). */
    public const KIT_SHA256 = 'ff40fe17b040aa87a81b829413a9713fb5aa11461d0d5725fa16def1a96d8115';

    /** The intents of the port and the capability each needs (null: every host). */
    public const INTENTS = [
        'document.load' => null,
        'document.resolve' => 'levels',
        'document.save' => null,
        'history.list' => 'history',
        'document.restore' => 'history',
        'document.publish' => 'publish',
        'document.rollback' => 'publish',
    ];

    /** Seconds the editor waits before it repeats an unavailable answer. */
    public const RETRY_AFTER = 30;

    private const HISTORY_LIMIT = 20;

    private const KEY_PATTERN = '/\A[A-Za-z0-9._:-]{1,100}\z/D';

    private function __construct(
        private readonly ?DocumentPortElement $element,
        private readonly ?DocumentPortStore $store,
        private readonly ?string $refusal,
    ) {
    }

    public static function bound(DocumentPortElement $element, DocumentPortStore $store): self
    {
        return new self($element, $store, null);
    }

    /**
     * A port that answers every intent with one refusal: the host could not bind the element for this reader
     * (`session-expired`, `access-denied`, `not-found`) or cannot reach its store (`storage-unavailable`).
     */
    public static function refusing(string $reason): self
    {
        if (!in_array($reason, ['session-expired', 'access-denied', 'not-found', 'storage-unavailable'], true)) {
            throw new InvalidArgumentException('document_port_refusal_invalid');
        }

        return new self(null, null, $reason);
    }

    /** The capabilities the element announces (none for a refusing port). @return list<string> */
    public function capabilities(): array
    {
        return $this->element === null ? [] : $this->element->capabilities;
    }

    /** @return array<string, mixed> the answer */
    public function send(string $intent, mixed $payload = []): array
    {
        if (!array_key_exists($intent, self::INTENTS)) {
            return self::refused('unknown-intent');
        }
        if ($this->refusal !== null || $this->element === null || $this->store === null) {
            return $this->refusal === 'storage-unavailable' ? self::unavailable() : self::refused($this->refusal ?? 'not-found');
        }
        $capability = self::INTENTS[$intent];
        if ($capability !== null && !$this->element->has($capability)) {
            return self::refused('capability-not-provided');
        }
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return self::refused('invalid', null, 'the payload is not an object');
        }
        /** @var array<string, mixed> $payload */
        try {
            return match ($intent) {
                'document.load' => $this->load(),
                'document.resolve' => $this->resolve($payload),
                'document.save' => $this->save($payload),
                'history.list' => $this->history($payload),
                'document.restore' => $this->restore($payload),
                'document.publish' => $this->publish($payload),
                'document.rollback' => $this->rollback($payload),
            };
        } catch (StorageConflict) {
            return $this->conflict('stale-revision');
        } catch (StoragePersistenceFailed) {
            return self::unavailable();
        } catch (StorageRejected $rejected) {
            return self::reason($rejected->reasonCode) === 'storage-unavailable'
                ? self::unavailable()
                : self::refused(self::reason($rejected->reasonCode), null, $rejected->reasonCode);
        } catch (PublicationRejected $rejected) {
            return self::refused('invalid', null, $rejected->reasonCode);
        } catch (InvalidArgumentException $invalid) {
            return self::refused('invalid', null, $invalid->getMessage());
        } catch (Throwable) {
            return self::unavailable();
        }
    }

    /**
     * The port's reason for a Larena code: a write the reader may not make is access-denied, a locked area
     * area-locked, a record that is gone not-found, a value the record cannot hold too-large, a failed store
     * storage-unavailable; any other refusal is invalid.
     */
    public static function reason(string $code): string
    {
        return match (true) {
            in_array($code, ['area_locked', 'document_area_locked'], true) => 'area-locked',
            in_array($code, ['document_access_denied', 'storage_workbench_record_archived'], true),
            str_ends_with($code, '_denied') => 'access-denied',
            in_array($code, ['storage_workbench_record_unknown', 'storage_workbench_structure_unknown', 'storage_record_not_found',
                'document_not_found'], true), str_ends_with($code, '_not_found') => 'not-found',
            in_array($code, ['storage_workbench_record_values_too_large', 'property_composition_too_large'], true) => 'too-large',
            in_array($code, ['property_composition_locale_mismatch'], true) => 'structure-mismatch',
            in_array($code, ['storage_persistence_failed', 'storage_unavailable', 'storage_workbench_record_head_corrupt'], true) => 'storage-unavailable',
            str_contains($code, 'revision_conflict') => 'stale-revision',
            default => 'invalid',
        };
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $element = $this->element();
        $current = $this->store()->current();

        return self::applied([
            'target' => $element->target,
            'revision' => $current['revision'],
            'languages' => $element->languages,
            'values' => $current['values'],
            'rights' => $element->rights,
            'limits' => $element->limits,
        ] + ($element->has('levels') ? $this->resolveAll($current['values']) : [])
          + ($element->has('publish') ? ['published' => $this->published()] : []));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function resolve(array $payload): array
    {
        $values = $payload['values'] ?? null;
        if (!CompositionDocument::isObject($values) || $values === []) {
            return self::refused('invalid', ['diagnostics' => [['code' => 'values-required', 'severity' => 'error', 'path' => '/values']]]);
        }
        /** @var array<string, mixed> $values */
        $refusal = $this->checkValues($values, false);
        if ($refusal !== null) {
            return $refusal;
        }

        return self::applied($this->resolveAll(array_map(static fn (mixed $document): mixed => CompositionDocument::withoutAreaMarks($document), $values)));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function save(array $payload): array
    {
        $element = $this->element();
        $key = $payload['client_key'] ?? null;
        $values = $payload['values'] ?? null;
        $expected = $payload['expected_revision'] ?? null;
        if (($key !== null && !self::isKey($key)) || !self::isKey($expected) || !CompositionDocument::isObject($values) || $values === []) {
            return self::refused('invalid', ['diagnostics' => [['code' => 'payload-invalid', 'severity' => 'error', 'path' => '']]]);
        }
        /** @var string $expected @var array<string, mixed> $values */
        $hash = self::paramsHash('document.save', $payload);
        if (is_string($key)) {
            $again = $this->remembered($key, 'document.save', $hash);
            if ($again !== null) {
                return $again;
            }
        }
        if (!$element->rights['save']) {
            return self::refused('access-denied');
        }
        $current = $this->store()->current();
        if ($expected !== $current['revision']) {
            return $this->conflict('stale-revision', $current);
        }
        $refusal = $this->checkValues($values, true);
        if ($refusal !== null) {
            return $refusal;
        }
        $next = $current['values'];
        foreach ($values as $language => $document) {
            if ($document === null) {
                unset($next[$language]);
                continue;
            }
            $level = $this->levelValue((string) $language, $document);
            if (isset($level['diagnostics'])) {
                return self::refused('would-lose', ['diagnostics' => $level['diagnostics']]);
            }
            $next[(string) $language] = $level['value'];
        }
        if ($element->has('levels') || $element->parents !== []) {
            $shapes = [];
            foreach ($next as $document) {
                $shape = array_map(static fn (array $region): string => self::regionName($region) . ':' . self::stateOf($region), self::regionsOf($document));
                sort($shape);
                $shapes[] = implode(',', $shape);
            }
            if (count(array_unique($shapes)) > 1) {
                return self::refused('structure-mismatch');
            }
            $first = reset($next);
            foreach (self::regionsOf($first === false ? [] : $first) as $region) {
                if ($this->lockedAbove(self::regionName($region)) !== null) {
                    return self::refused('area-locked');
                }
            }
        }
        $written = $this->store()->write($expected, $next, is_string($key) ? ['intent' => 'document.save', 'client_key' => $key, 'params_hash' => $hash] : null);

        return $this->draftAnswer($written['revision'], $written['values']);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function history(array $payload): array
    {
        $limit = $payload['limit'] ?? self::HISTORY_LIMIT;
        $before = $payload['before'] ?? null;
        $beforePublication = $payload['before_publication'] ?? null;
        if (!is_int($limit) || $limit < 1 || $limit > 100 || ($before !== null && !self::isKey($before))
            || ($beforePublication !== null && !self::isKey($beforePublication))) {
            return self::refused('invalid');
        }
        $drafts = $this->store()->drafts();
        $publications = $this->element()->has('publish') ? $this->store()->publications() : [];
        $from = static function (array $list, mixed $at, string $key): int {
            if ($at === null) {
                return 0;
            }
            foreach ($list as $index => $entry) {
                if ($entry[$key] === $at) {
                    return $index + 1;
                }
            }

            return -1;
        };
        $d = $from($drafts, $before, 'revision');
        $p = $from($publications, $beforePublication, 'id');
        if ($d < 0 || $p < 0) {
            return self::refused('not-found');
        }

        return self::applied([
            'drafts' => array_slice($drafts, $d, $limit),
            'publications' => array_slice($publications, $p, $limit),
            'more' => ['drafts' => count($drafts) > $d + $limit, 'publications' => count($publications) > $p + $limit],
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function restore(array $payload): array
    {
        $key = $payload['client_key'] ?? null;
        $revision = $payload['revision'] ?? null;
        $expected = $payload['expected_revision'] ?? null;
        if (!self::isKey($key) || !self::isKey($revision) || !self::isKey($expected)) {
            return self::refused('invalid');
        }
        /** @var string $key @var string $revision @var string $expected */
        $hash = self::paramsHash('document.restore', $payload);
        $again = $this->remembered($key, 'document.restore', $hash);
        if ($again !== null) {
            return $again;
        }
        if (!$this->element()->rights['save']) {
            return self::refused('access-denied');
        }
        $current = $this->store()->current();
        if ($expected !== $current['revision']) {
            return $this->conflict('stale-revision', $current);
        }
        $values = $revision === $current['revision'] ? $current['values'] : $this->store()->valuesAt($revision);
        if ($values === null) {
            return self::refused('not-found');
        }
        $written = $this->store()->write($expected, $values,
            ['intent' => 'document.restore', 'client_key' => $key, 'params_hash' => $hash, 'restored_from' => $revision]);

        return $this->draftAnswer($written['revision'], $written['values']);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function publish(array $payload): array
    {
        $element = $this->element();
        $key = $payload['client_key'] ?? null;
        $revision = $payload['revision'] ?? null;
        $expected = array_key_exists('expected_published', $payload) ? $payload['expected_published'] : false;
        $languages = $payload['languages'] ?? null;
        if (!self::isKey($key) || !self::isKey($revision) || ($expected !== null && !self::isKey($expected))
            || ($languages !== null && (!is_array($languages) || $languages === [] || !array_is_list($languages)
                || count(array_unique(array_map(static fn (mixed $one): string => is_string($one) ? $one : '', $languages))) !== count($languages)))
            || (array_key_exists('at', $payload) && !is_string($payload['at']))) {
            return self::refused('invalid');
        }
        /** @var string $key @var string $revision @var string|null $expected @var list<string>|null $languages */
        $hash = self::paramsHash('document.publish', $payload);
        $again = $this->remembered($key, 'document.publish', $hash);
        if ($again !== null) {
            return $again;
        }
        if (array_key_exists('at', $payload) && !$element->has('scheduled-publish')) {
            return self::refused('capability-not-provided');
        }
        if (!$element->rights['publish']) {
            return self::refused('access-denied');
        }
        if ($expected !== $this->store()->live()) {
            return $this->conflict('environment-changed');
        }
        $current = $this->store()->current();
        if ($revision !== $current['revision']) {
            return $this->conflict('stale-revision', $current);
        }
        foreach ($languages ?? [] as $language) {
            if (!in_array($language, $element->languages, true)) {
                return self::refused('unknown-language');
            }
            if (!array_key_exists($language, $current['values'])) {
                return self::refused('missing-translation');
            }
        }
        $published = array_values(array_filter($languages ?? $element->languages,
            static fn (string $language): bool => array_key_exists($language, $current['values'])));
        $publication = $this->store()->publish($revision, $published, null, ['intent' => 'document.publish', 'client_key' => $key, 'params_hash' => $hash]);

        return self::applied(['publication' => $publication]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function rollback(array $payload): array
    {
        $key = $payload['client_key'] ?? null;
        $publication = $payload['publication'] ?? null;
        $expected = array_key_exists('expected_published', $payload) ? $payload['expected_published'] : false;
        if (!self::isKey($key) || !self::isKey($publication) || ($expected !== null && !self::isKey($expected))) {
            return self::refused('invalid');
        }
        /** @var string $key @var string $publication @var string|null $expected */
        $hash = self::paramsHash('document.rollback', $payload);
        $again = $this->remembered($key, 'document.rollback', $hash);
        if ($again !== null) {
            return $again;
        }
        if (!$this->element()->rights['publish']) {
            return self::refused('access-denied');
        }
        if ($expected !== $this->store()->live()) {
            return $this->conflict('environment-changed');
        }
        $from = null;
        foreach ($this->store()->publications() as $one) {
            if ($one['id'] === $publication) {
                $from = $one;
            }
        }
        if ($from === null) {
            return self::refused('not-found');
        }
        $made = $this->store()->publish($from['revision'], $from['languages'], $from['id'], ['intent' => 'document.rollback', 'client_key' => $key, 'params_hash' => $hash]);

        return self::applied(['publication' => $made]);
    }

    /**
     * The answer a client_key already has: the same intent and input answers what the write made; anything else
     * is an idempotency-conflict. Null when the key is new.
     *
     * @return array<string, mixed>|null
     */
    private function remembered(string $key, string $intent, string $hash): ?array
    {
        $receipt = $this->store()->receipt($key);
        if ($receipt === null) {
            return null;
        }
        if ($receipt['intent'] !== $intent || !hash_equals($receipt['params_hash'], $hash)) {
            return $this->conflict('idempotency-conflict');
        }
        if ($receipt['publication'] !== null) {
            foreach ($this->store()->publications() as $publication) {
                if ($publication['id'] === $receipt['publication']) {
                    return self::applied(['publication' => $publication]);
                }
            }
        }
        if ($receipt['revision'] !== null) {
            $values = $this->store()->valuesAt($receipt['revision']);
            if ($values !== null) {
                return $this->draftAnswer($receipt['revision'], $values);
            }
        }

        return self::refused('not-found');
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function draftAnswer(string $revision, array $values): array
    {
        return self::applied(['revision' => $revision, 'values' => $values]
            + ($this->element()->has('levels') ? $this->resolveAll($values) : []));
    }

    /**
     * The checks of a write's values, language by language: one of the element's languages exactly, one the reader
     * may write, the main one never removed, a locale that is its key, a valid document within the limits.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>|null the refusal, or null
     */
    private function checkValues(array $values, bool $writing): ?array
    {
        $element = $this->element();
        foreach ($values as $language => $document) {
            $language = (string) $language;
            if (!in_array($language, $element->languages, true)) {
                return self::refused('unknown-language');
            }
            if ($writing && !$element->mayWrite($language)) {
                return self::refused('access-denied');
            }
            if ($document === null) {
                if ($writing && $language === $element->mainLanguage()) {
                    return self::refused('invalid', ['diagnostics' => [['code' => 'main-language-removed', 'severity' => 'error', 'path' => '/values/' . $language, 'language' => $language]]]);
                }
                if (!$writing) {
                    return self::refused('invalid', ['diagnostics' => [['code' => 'document-required', 'severity' => 'error', 'path' => '/values/' . $language, 'language' => $language]]]);
                }
                continue;
            }
            if (is_array($document) && array_key_exists('locale', $document) && $document['locale'] !== $language) {
                return self::refused('structure-mismatch');
            }
            $errors = CompositionDocument::errors($document);
            if ($errors !== []) {
                return self::refused('invalid', ['diagnostics' => array_map(static fn (array $error): array => [
                    'code' => $error['code'], 'severity' => 'error', 'path' => '/values/' . $language . $error['path'], 'language' => $language,
                ], array_slice($errors, 0, 20))]);
            }
            if (CompositionDocument::nodes($document) > $element->limits['max_nodes'] || CompositionDocument::bytes($document) > $element->limits['max_bytes']) {
                return self::refused('too-large');
            }
        }

        return null;
    }

    /**
     * A value as this level stores it: a region marked inherited is not stored (refused would-lose when its content
     * is not the parent's), and the simai:area key goes from every region, every other extensions key staying.
     *
     * @return array{value: array<string, mixed>}|array{diagnostics: list<array<string, mixed>>}
     */
    private function levelValue(string $language, mixed $document): array
    {
        /** @var array<string, mixed> $document */
        $diagnostics = [];
        $shell = self::shellIndex($document);
        if ($shell !== null) {
            $regions = $document['root']['slots']['default'][$shell]['slots']['regions'];
            $kept = [];
            foreach ($regions as $index => $region) {
                if (!is_array($region) || (($region['extensions'][CompositionDocument::AREA_MARK]['state'] ?? null) !== 'inherited')) {
                    $kept[] = $region;
                    continue;
                }
                $bare = self::unmarked($region);
                if (!self::same($bare, $this->parentArea($language, self::regionName($region)))) {
                    $diagnostics[] = ['code' => 'inherited-area-changed', 'severity' => 'error',
                        'path' => '/root/slots/default/' . $shell . '/slots/regions/' . $index, 'language' => $language];
                }
            }
            $document['root']['slots']['default'][$shell]['slots']['regions'] = $kept;
        }
        if ($diagnostics !== []) {
            return ['diagnostics' => $diagnostics];
        }
        /** @var array<string, mixed> $value */
        $value = CompositionDocument::withoutAreaMarks($document);

        return ['value' => $value];
    }

    /**
     * The values over the parents, by Recipe: an area a level leaves out inherits, one it carries replaces, one it
     * carries empty clears; every region marked with its origin, state and lock.
     *
     * @param array<string, mixed> $values
     * @return array{resolved: array<string, mixed>, areas: array<string, mixed>}
     */
    private function resolveAll(array $values): array
    {
        $element = $this->element();
        $chain = [$element->target, ...(is_array($element->target['parents'] ?? null) ? $element->target['parents'] : [])];
        $names = [];
        foreach (array_reverse($chain) as $level) {
            foreach ($this->valuesOf($level, $values) as $document) {
                foreach (self::regionsOf($document) as $region) {
                    $name = self::regionName($region);
                    if (!in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }
            }
        }
        $resolved = [];
        $areas = [];
        $top = $chain[count($chain) - 1];
        foreach ($values as $language => $own) {
            $language = (string) $language;
            $base = ($top['ref'] ?? null) === $element->target['ref'] ? $own : ($this->valuesOf($top, $values)[$language] ?? $own);
            $document = is_array($base) ? $base : [];
            $document['id'] = is_array($own) ? ($own['id'] ?? null) : null;
            $shell = self::shellIndex($document);
            foreach ($names as $name) {
                $origin = null;
                $source = null;
                foreach ($chain as $level) {
                    foreach (self::regionsOf($this->valuesOf($level, $values)[$language] ?? null) as $region) {
                        if (self::regionName($region) === $name) {
                            $origin = $level;
                            $source = $region;
                            break 2;
                        }
                    }
                }
                if ($origin === null || $source === null) {
                    continue;
                }
                $mark = [
                    'origin' => $origin['level'],
                    'origin_ref' => $origin['ref'],
                    'state' => $origin['ref'] === $element->target['ref'] ? self::stateOf($source) : 'inherited',
                    'locked' => $this->lockedAbove($name) !== null,
                ];
                $region = $source;
                $region['extensions'] = (is_array($region['extensions'] ?? null) ? $region['extensions'] : []) + [CompositionDocument::AREA_MARK => $mark];
                $region['extensions'][CompositionDocument::AREA_MARK] = $mark;
                if ($shell !== null) {
                    $regions = $document['root']['slots']['default'][$shell]['slots']['regions'];
                    $found = false;
                    foreach ($regions as $index => $one) {
                        if (is_array($one) && self::regionName($one) === $name) {
                            $regions[$index] = $region;
                            $found = true;
                        }
                    }
                    if (!$found) {
                        $regions[] = $region;
                    }
                    $document['root']['slots']['default'][$shell]['slots']['regions'] = $regions;
                }
                $areas[$name] = $mark;
            }
            $resolved[$language] = $document;
        }

        return ['resolved' => $resolved, 'areas' => $areas];
    }

    /**
     * @param array<string, mixed> $level
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function valuesOf(array $level, array $values): array
    {
        $element = $this->element();
        if (($level['ref'] ?? null) === $element->target['ref']) {
            return $values;
        }
        return $element->parents[(string) ($level['ref'] ?? '')]['values'] ?? [];
    }

    /** The parent level that locks an area, nearest first; null when none does. @return array<string, mixed>|null */
    private function lockedAbove(string $name): ?array
    {
        $element = $this->element();
        foreach (is_array($element->target['parents'] ?? null) ? $element->target['parents'] : [] as $level) {
            if (in_array($name, $element->parents[(string) ($level['ref'] ?? '')]['locked'] ?? [], true)) {
                return $level;
            }
        }

        return null;
    }

    /** The parent's area a region marked inherited stands for, in one language. @return array<string, mixed>|null */
    private function parentArea(string $language, string $name): ?array
    {
        $element = $this->element();
        foreach (is_array($element->target['parents'] ?? null) ? $element->target['parents'] : [] as $level) {
            foreach (self::regionsOf($element->parents[(string) ($level['ref'] ?? '')]['values'][$language] ?? null) as $region) {
                if (self::regionName($region) === $name) {
                    return $region;
                }
            }
        }

        return null;
    }

    /** @return array{id: string, revision: string, state: string, at: string, languages: list<string>}|null */
    private function published(): ?array
    {
        $live = $this->store()->live();
        foreach ($live === null ? [] : $this->store()->publications() as $publication) {
            if ($publication['id'] === $live) {
                return $publication;
            }
        }

        return null;
    }

    /**
     * @param array{revision: string, at: string, values: array<string, mixed>}|null $current
     * @return array<string, mixed>
     */
    private function conflict(string $reason, ?array $current = null): array
    {
        $current ??= $this->store()->current();
        $element = $this->element();

        return ['answer' => 'conflict', 'reason' => $reason, 'message' => 'conflict: ' . $reason, 'current_revision' => $current['revision'],
            'current' => ['revision' => $current['revision'], 'values' => $current['values']]
                + ($element->has('publish') ? ['published' => $this->published()] : [])
                + ($element->has('levels') ? $this->resolveAll($current['values']) : [])];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function applied(array $data): array
    {
        return ['answer' => 'applied', 'data' => $data];
    }

    /** @param array<string, mixed>|null $data @return array<string, mixed> */
    private static function refused(string $reason, ?array $data = null, ?string $code = null): array
    {
        return ['answer' => 'refused', 'reason' => $reason, 'message' => 'refused: ' . $reason . ($code !== null && $code !== $reason ? ' (' . $code . ')' : '')]
            + ($data !== null ? ['data' => $data] : []);
    }

    /** @return array<string, mixed> */
    private static function unavailable(): array
    {
        return ['answer' => 'unavailable', 'reason' => 'storage-unavailable', 'message' => 'unavailable: storage-unavailable', 'retry_after' => self::RETRY_AFTER];
    }

    /** The fingerprint of a write's input: the intent and its payload, key order aside. @param array<string, mixed> $payload */
    private static function paramsHash(string $intent, array $payload): string
    {
        return hash('sha256', $intent . "\n" . json_encode(self::canonical($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function isKey(mixed $value): bool
    {
        return is_string($value) && preg_match(self::KEY_PATTERN, $value) === 1;
    }

    /** Equal, key order aside (the round-trip rule). */
    public static function same(mixed $left, mixed $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /** The index of the layout.regions shell among the root's children, or null. @param array<string, mixed> $document */
    private static function shellIndex(array $document): ?int
    {
        $children = $document['root']['slots']['default'] ?? null;
        if (!is_array($children)) {
            return null;
        }
        foreach ($children as $index => $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'layout.regions' && is_array($node['slots']['regions'] ?? null)) {
                return is_int($index) ? $index : null;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private static function regionsOf(mixed $document): array
    {
        if (!is_array($document)) {
            return [];
        }
        $shell = self::shellIndex($document);
        if ($shell === null) {
            return [];
        }

        return array_values(array_filter($document['root']['slots']['default'][$shell]['slots']['regions'], is_array(...)));
    }

    /** @param array<string, mixed> $region */
    private static function regionName(array $region): string
    {
        $name = $region['props']['name'] ?? '';

        return is_string($name) ? $name : '';
    }

    /** @param array<string, mixed> $region */
    private static function stateOf(array $region): string
    {
        $children = $region['slots']['default'] ?? [];

        return is_array($children) && $children !== [] ? 'replaced' : 'cleared';
    }

    /** @param array<string, mixed> $region @return array<string, mixed> */
    private static function unmarked(array $region): array
    {
        if (is_array($region['extensions'] ?? null)) {
            unset($region['extensions'][CompositionDocument::AREA_MARK]);
            if ($region['extensions'] === []) {
                unset($region['extensions']);
            }
        }

        return $region;
    }

    private function element(): DocumentPortElement
    {
        return $this->element ?? throw new StorageRejected('document_not_found');
    }

    private function store(): DocumentPortStore
    {
        return $this->store ?? throw new StorageRejected('document_not_found');
    }
}
