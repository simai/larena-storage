<?php

declare(strict_types=1);

namespace Larena\Storage\Cards;

use Larena\Property\Contracts\PropertyField;
use Larena\Property\Runtime\PropertyFieldDescriber;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The cards of a list (C306, cards study CV-4): the options of `layouts.cards` in a view of `simai.view-config/1`,
 * checked against the storage's fields, and the cards' window of records.
 *
 * - `cover_field`: a file field whose picture tops the card (with several files, the first), or null for cards without
 *   a cover; by default the first file field drawn as a picture (templates image, image_card, image_list), else null;
 * - `cover`: `fit` — `cover` crops the picture to the frame (default), `contain` shows it whole on a tint; `ratio` —
 *   `1:1`, `4:3` (default), `3:4` or `16:9`;
 * - `title_field`: a string or text field (the dataview role `title`); by default the first such field;
 * - `card_fields`: up to four fields under the title, drawn in the «Кратко» place; by default the first fields after
 *   the title and the cover;
 * - `card_labels`: whether the fields show their labels (default false);
 * - `size`: `s`, `m` (default) or `l`, the least card width; `density`: `default` or `compact`;
 * - `more`: `button` («Показать ещё», default) or `pages`;
 * - `per_page`: records a window answers, 24 by default.
 *
 * window() answers {page, per_page} with {records, total, has_next}: the list's page (its filters, search and sort, no
 * grouping, no manual order) read from the витрина like any list page. Page numbers reach as deep as a list's offset
 * does; past it «Показать ещё» goes on by the continuation the window gives.
 */
final readonly class StorageCardsDescription
{
    public const PER_PAGE = 24;
    public const MAX_PER_PAGE = 100;
    public const MAX_CARD_FIELDS = 4;
    public const FITS = ['cover', 'contain'];
    public const RATIOS = ['1:1', '4:3', '3:4', '16:9'];
    public const SIZES = ['s', 'm', 'l'];
    /** The file templates that draw a picture: a field drawn so is the default cover. */
    public const PICTURE_TEMPLATES = ['image', 'image_card', 'image_list'];
    private const KEYS = ['cover_field', 'cover', 'title_field', 'card_fields', 'card_labels', 'size', 'density', 'more', 'per_page'];
    private const TITLE_TYPES = ['string', 'text'];

    /**
     * @param list<string> $cardFields
     * @param array<string, array<string, mixed>> $fields the storage's fields by key
     */
    private function __construct(
        public ?string $coverField,
        public string $fit,
        public string $ratio,
        public string $titleField,
        public array $cardFields,
        public bool $cardLabels,
        public string $size,
        public string $density,
        public string $more,
        public int $perPage,
        private array $fields,
    ) {
    }

    /**
     * @param array<array-key, mixed> $cards the `layouts.cards` options
     * @param list<array<string, mixed>> $fields the storage's fields (a structure's `fields`)
     */
    public static function fromArray(array $cards, array $fields, PropertyFieldDescriber $describer = new PropertyFieldDescriber()): self
    {
        $byKey = [];
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null)) {
                $byKey[$field['key']] = $field;
            }
        }
        if (array_diff(array_map('strval', array_keys($cards)), self::KEYS) !== []) {
            self::reject('unknown_key');
        }
        if (array_key_exists('cover_field', $cards)) {
            $cover = $cards['cover_field'];
            if ($cover !== null && (!is_string($cover) || ($byKey[$cover]['type'] ?? null) !== 'file')) {
                self::reject('cover_field');
            }
        } else {
            $cover = self::firstPicture($byKey, $describer);
        }
        $frame = $cards['cover'] ?? [];
        if (!is_array($frame) || array_diff(array_map('strval', array_keys($frame)), ['fit', 'ratio']) !== []) {
            self::reject('cover');
        }
        $fit = $frame['fit'] ?? 'cover';
        $ratio = $frame['ratio'] ?? '4:3';
        if (!in_array($fit, self::FITS, true) || !in_array($ratio, self::RATIOS, true)) {
            self::reject('cover');
        }
        $title = $cards['title_field'] ?? self::firstOf($byKey, self::TITLE_TYPES);
        if (!is_string($title) || !in_array($byKey[$title]['type'] ?? null, self::TITLE_TYPES, true)) {
            self::reject('title_field');
        }
        if (array_key_exists('card_fields', $cards)) {
            $cardFields = $cards['card_fields'];
            if (!is_array($cardFields) || !array_is_list($cardFields) || count($cardFields) > self::MAX_CARD_FIELDS
                || count(array_unique($cardFields, SORT_REGULAR)) !== count($cardFields)) {
                self::reject('card_fields');
            }
            foreach ($cardFields as $key) {
                if (!is_string($key) || !array_key_exists($key, $byKey)) {
                    self::reject('card_fields');
                }
            }
        } else {
            $cardFields = array_slice(array_values(array_filter(array_keys($byKey), static fn (string $key): bool => $key !== $title && $key !== $cover)), 0, self::MAX_CARD_FIELDS);
        }
        $labels = $cards['card_labels'] ?? false;
        $size = $cards['size'] ?? 'm';
        $density = $cards['density'] ?? 'default';
        $more = $cards['more'] ?? 'button';
        $perPage = $cards['per_page'] ?? self::PER_PAGE;
        if (!is_bool($labels)) {
            self::reject('card_labels');
        }
        if (!in_array($size, self::SIZES, true)) {
            self::reject('size');
        }
        if ($density !== 'default' && $density !== 'compact') {
            self::reject('density');
        }
        if ($more !== 'button' && $more !== 'pages') {
            self::reject('more');
        }
        if (!is_int($perPage) || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            self::reject('per_page');
        }

        return new self(is_string($cover) ? $cover : null, $fit, $ratio, $title, array_map('strval', $cardFields), $labels, $size, $density, $more, $perPage, $byKey);
    }

    /** @return array<string, mixed> the normalized `layouts.cards` options */
    public function toArray(): array
    {
        return ['cover_field' => $this->coverField, 'cover' => ['fit' => $this->fit, 'ratio' => $this->ratio], 'title_field' => $this->titleField,
            'card_fields' => $this->cardFields, 'card_labels' => $this->cardLabels, 'size' => $this->size, 'density' => $this->density,
            'more' => $this->more, 'per_page' => $this->perPage];
    }

    /**
     * The cards' part of describe(): the fields in the Framework catalogue's words, the options and the window.
     *
     * @return array{fields: list<array<string, mixed>>, cards: array<string, mixed>, window: array{page: int, per_page: int}}
     */
    public function describe(PropertyFieldDescriber $describer = new PropertyFieldDescriber()): array
    {
        $fields = [];
        foreach ($this->fields as $field) {
            $fields[] = $describer->describe(PropertyField::fromArray($field, false));
        }

        return ['fields' => $fields, 'cards' => $this->toArray(), 'window' => ['page' => 1, 'per_page' => $this->perPage]];
    }

    /**
     * One window of cards: {page, per_page} of the list (its filters, search and sort) → {records, total, has_next}.
     * `total_exact` says whether the total is counted or estimated (a large list); `continuation` goes on past the
     * deepest page number.
     *
     * @return array{records: list<StorageWorkbenchRecord>, total: int, total_exact: bool, has_next: bool, page: int|null, per_page: int, continuation: string|null}
     */
    public function window(StorageWorkbench $workbench, StorageWorkbenchRecordQuery $records, string $actor, ?int $page = 1, ?int $perPage = null, ?string $continuation = null): array
    {
        $perPage ??= $this->perPage;
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE || ($page !== null && $page < 1) || ($page === null) === ($continuation === null)) {
            throw new StorageRejected('storage_cards_window_invalid');
        }
        $result = $workbench->listRecords(new StorageWorkbenchRecordQuery($records->scopeRef, $records->structureId, $records->filters,
            $records->search, $records->sort, $perPage, $continuation, $records->includeArchived, $page, $records->countMode), $actor);

        return ['records' => $result->items, 'total' => $result->matchedCount, 'total_exact' => $result->countExact,
            'has_next' => $result->continuation !== null, 'page' => $page, 'per_page' => $perPage, 'continuation' => $result->continuation];
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     */
    private static function firstPicture(array $byKey, PropertyFieldDescriber $describer): ?string
    {
        foreach ($byKey as $key => $field) {
            if (($field['type'] ?? null) !== 'file') {
                continue;
            }
            $described = $describer->describe(PropertyField::fromArray($field, false));
            if (in_array($described['template'], self::PICTURE_TEMPLATES, true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $byKey
     * @param list<string> $types
     */
    private static function firstOf(array $byKey, array $types): ?string
    {
        foreach ($byKey as $key => $field) {
            if (in_array($field['type'] ?? null, $types, true)) {
                return (string) $key;
            }
        }

        return null;
    }

    private static function reject(string $what): never
    {
        throw new StorageRejected('storage_cards_description_invalid:' . $what);
    }
}
