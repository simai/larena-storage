<?php

declare(strict_types=1);

namespace Larena\Storage\View\Site;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * One slot of a site витрина: a published storage in one scope and one locale.
 *
 * States: `pending` (its rows are not complete: nothing writes it and it does not
 * answer; the rebuild fills it), `building` (a rebuild is filling it; writers keep
 * it current), `ready` (it holds exactly the published records of its scope and
 * locale, resolved along `localeChain`), `failed` (a rebuild could not fill it).
 *
 * A ready slot answers only for the locale chain it was built along: a changed chain
 * silences it until it is rebuilt.
 */
final readonly class PublishedSiteViewSlot
{
    public const STATE_PENDING = 'pending';
    public const STATE_BUILDING = 'building';
    public const STATE_READY = 'ready';
    public const STATE_FAILED = 'failed';
    public const STATES = [self::STATE_PENDING, self::STATE_BUILDING, self::STATE_READY, self::STATE_FAILED];

    public function __construct(
        public int $id,
        public string $schemaId,
        public string $scopeRef,
        public string $locale,
        public string $state,
        public ?string $localeChain = null,
        public ?string $buildCursor = null,
        public ?string $buildRunRef = null,
        public ?string $builtAt = null,
    ) {
        if ($id < 1 || !in_array($state, self::STATES, true)) {
            throw new StorageRejected('storage_site_view_slot_invalid');
        }
    }

    /** Writers keep the slot's rows current while it is ready or being built. */
    public function written(): bool
    {
        return $this->state === self::STATE_READY || $this->state === self::STATE_BUILDING;
    }

    /** It answers reads: ready, along this locale chain. */
    public function answers(string $localeChain): bool
    {
        return $this->state === self::STATE_READY && $this->localeChain === $localeChain;
    }

    public function key(): string
    {
        return self::keyOf($this->schemaId, $this->scopeRef, $this->locale);
    }

    public static function keyOf(string $schemaId, string $scopeRef, string $locale): string
    {
        return $schemaId . "\0" . $scopeRef . "\0" . $locale;
    }
}
