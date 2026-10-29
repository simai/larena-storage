<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Contracts\Translation\Translator;

/**
 * What the records list says when it is sorted by a field the storage's list has no index for and
 * the list is large (Storage's `sort_unindexed` and `large`, {@see StorageSectionGateway}): sorting
 * then reads every matched record, seconds on a million. A hint above the list, never a refusal:
 *
 * - a string of at most 255 characters: «Включите для поля «Быстрая сортировка и фильтр»»;
 * - a longer string or a text: «задайте полю максимальную длину до 255 символов…»;
 * - any other field (an index over the storage's budget): only that the sort is slow.
 *
 * The advice links to the field's settings panel for a person who may configure the storage; anyone
 * else reads the first sentence alone. The page shows the hint on load, and the list's port carries
 * it in its answer (`data.sort_hint`) for a sort changed in place.
 *
 * A list nobody sorted is in its own order, newest first (StorageListOrder; the gateway's
 * `sort_default`): the same place then says so, «Сортировка: дата создания, сначала новые», since the
 * table marks a sort only on a column and the creation time is not one. A sort the person picks
 * replaces the line.
 */
final readonly class StorageSortHint
{
    /** The longest string the list can index (Storage's StorageViewColumn::VARCHAR_MAX). */
    public const INDEXABLE_MAX_LENGTH = 255;

    public function __construct(private Translator $translator)
    {
    }

    /**
     * The hint for one answered list, or null when there is nothing to say.
     *
     * @param array<string, mixed> $records the records part of the gateway's snapshot
     * @param array<string, mixed>|null $selected the storage as the gateway projects it
     * @param string $settingsPath the storage's settings page, e.g. /admin/storage/workbench.items/settings
     * @return array{field:string,text:string,link:array{text:string,href:string}|null}|null
     */
    public function hint(array $records, ?array $selected, bool $configure, string $settingsPath): ?array
    {
        $unindexed = is_array($records['sort_unindexed'] ?? null) ? $records['sort_unindexed'] : [];
        $key = $unindexed[0] ?? null;
        if (($records['large'] ?? false) !== true || !is_string($key) || $selected === null) {
            return $this->ownOrder($records);
        }
        $field = null;
        foreach (is_array($selected['fields'] ?? null) ? $selected['fields'] : [] as $candidate) {
            if (is_array($candidate) && ($candidate['key'] ?? null) === $key) {
                $field = $candidate;
                break;
            }
        }
        if ($field === null) {
            return $this->ownOrder($records);
        }
        $label = is_string($field['label'] ?? null) && trim($field['label']) !== '' ? trim($field['label']) : $key;
        $text = $this->text('slow', [':field' => $label]);
        $advice = match (self::advice($field)) {
            'indexed' => $this->text('enable_indexed', [':option' => $this->option()]),
            'max_length' => $this->text('set_max_length', [':length' => (string) self::INDEXABLE_MAX_LENGTH]),
            default => null,
        };
        $link = $advice !== null && $configure
            ? ['text' => $advice, 'href' => $settingsPath.'?record_id='.rawurlencode($key).'&record_mode=edit']
            : null;

        return ['field' => $key, 'text' => $text, 'link' => $link];
    }

    /**
     * The list in its own order (no sort asked for): newest first, said without a link.
     *
     * @param array<string, mixed> $records
     * @return array{field:string,text:string,link:null}|null
     */
    private function ownOrder(array $records): ?array
    {
        return ($records['sort_default'] ?? false) === true
            ? ['field' => StorageListOrder::CREATED_AT, 'text' => $this->text('newest_first'), 'link' => null]
            : null;
    }

    /**
     * What makes the field's sort fast: `indexed` (a string the list can index), `max_length` (a
     * string or a text too long for it) or null (nothing the person can switch on).
     *
     * @param array<string, mixed> $field
     */
    public static function advice(array $field): ?string
    {
        $type = $field['type'] ?? null;
        if ($type === 'text') {
            return 'max_length';
        }
        if ($type !== 'string') {
            return null;
        }
        $constraints = is_array($field['constraints'] ?? null) ? $field['constraints'] : [];
        $length = $constraints['max_length'] ?? null;

        return is_int($length) && $length >= 1 && $length <= self::INDEXABLE_MAX_LENGTH ? 'indexed' : 'max_length';
    }

    private function option(): string
    {
        $text = $this->translator->get('larena-storage::admin.section.indexed');

        return is_string($text) ? $text : '';
    }

    /** @param array<string, string> $replace */
    private function text(string $key, array $replace = []): string
    {
        $text = $this->translator->get('larena-storage::admin.section.sort_hint.'.$key);

        return strtr(is_string($text) ? $text : '', $replace);
    }
}
