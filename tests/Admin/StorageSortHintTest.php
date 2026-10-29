<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Larena\Storage\Admin\StorageSortHint;

// A large records list sorted by a field Storage has no index for gets a hint above the list, never a
// refusal: «Сортировка по полю «X» на большом хранилище идёт медленно.» and, for a person who may
// configure the storage, a link to the field's settings: «Включите для поля «Быстрая сортировка и
// фильтр»» for a string of at most 255 characters, «Задайте полю максимальную длину до 255 символов…»
// for a longer string or a text. Anyone else reads the first sentence alone; a small list or an
// indexed sort says nothing. The words come from the package's language files, ru and en.

final class SortHintTranslator implements \Illuminate\Contracts\Translation\Translator
{
    /** @var array<string, mixed> */
    private array $admin;

    public function __construct(private string $locale = 'ru')
    {
        $this->admin = storage_admin_words($locale);
    }

    public function get($key, array $replace = [], $locale = null)
    {
        $asked = $key;
        $key = storage_admin_key((string) $key);
        if (!str_starts_with($key, 'larena-admin::admin.')) {
            return $asked;
        }
        $value = $this->admin;
        foreach (explode('.', substr($key, strlen('larena-admin::admin.'))) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $asked;
            }
            $value = $value[$part];
        }

        return is_string($value) ? $value : $asked;
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $key;
    }

    public function getLocale()
    {
        return $this->locale;
    }

    public function setLocale($locale)
    {
        $this->locale = $locale;
    }
}

$selected = ['structure_id' => 'workbench.items', 'fields' => [
    ['key' => 'title', 'label' => 'Название', 'type' => 'string', 'constraints' => ['max_length' => 200]],
    ['key' => 'code', 'label' => '', 'type' => 'string', 'constraints' => []],
    ['key' => 'long', 'label' => 'Длинная', 'type' => 'string', 'constraints' => ['max_length' => 1000]],
    ['key' => 'body', 'label' => 'Текст', 'type' => 'text', 'constraints' => []],
    ['key' => 'qty', 'label' => 'Количество', 'type' => 'integer', 'constraints' => []],
]];
$settings = '/admin/storage/workbench.items/settings';
$hints = new StorageSortHint(new SortHintTranslator());
$slow = static fn (string $key): array => ['sort_unindexed' => [$key], 'large' => true];

// A string the list can index: turn on «Быстрая сортировка и фильтр», linked to the field's panel.
$title = $hints->hint($slow('title'), $selected, true, $settings);
assert($title === ['field' => 'title', 'text' => 'Сортировка по полю «Название» на большом хранилище идёт медленно.',
    'link' => ['text' => 'Включите для поля «Быстрая сортировка и фильтр»', 'href' => $settings . '?record_id=title&record_mode=edit']]);
// Without the right to configure: the first sentence alone.
assert($hints->hint($slow('title'), $selected, false, $settings) === ['field' => 'title',
    'text' => 'Сортировка по полю «Название» на большом хранилище идёт медленно.', 'link' => null]);

// A string without a maximum length, a longer one or a text: give it a maximum length of up to 255.
foreach (['code' => 'code', 'long' => 'Длинная', 'body' => 'Текст'] as $key => $label) {
    $hint = $hints->hint($slow($key), $selected, true, $settings);
    assert($hint !== null && $hint['text'] === 'Сортировка по полю «' . $label . '» на большом хранилище идёт медленно.', $key);
    assert($hint['link'] === ['text' => 'Задайте полю максимальную длину до 255 символов, чтобы включить быструю сортировку',
        'href' => $settings . '?record_id=' . $key . '&record_mode=edit'], $key);
}
// Another field (an index over the storage's budget): only that the sort is slow, with nothing to switch on.
assert($hints->hint($slow('qty'), $selected, true, $settings) === ['field' => 'qty',
    'text' => 'Сортировка по полю «Количество» на большом хранилище идёт медленно.', 'link' => null]);

// Nothing to say: a small list, an indexed sort, no storage, a field the storage does not have.
assert($hints->hint(['sort_unindexed' => ['title'], 'large' => false], $selected, true, $settings) === null);
assert($hints->hint(['sort_unindexed' => [], 'large' => true], $selected, true, $settings) === null);
assert($hints->hint([], $selected, true, $settings) === null);
assert($hints->hint($slow('title'), null, true, $settings) === null);
assert($hints->hint($slow('missing'), $selected, true, $settings) === null);
// Several slow keys: the first one is named.
assert($hints->hint(['sort_unindexed' => ['body', 'title'], 'large' => true], $selected, true, $settings)['field'] === 'body');

// A list nobody sorted is newest first (the gateway's sort_default): the same place says so, without a link, in ru
// and en; a sort the person chose, or a large list's slow sort, says what it says instead.
$own = ['sort_unindexed' => [], 'large' => true, 'sort_default' => true];
assert($hints->hint($own, $selected, true, $settings) === ['field' => '_created_at',
    'text' => 'Сортировка: дата создания, сначала новые.', 'link' => null]);
assert($hints->hint(['sort_default' => true], null, false, $settings)['text'] === 'Сортировка: дата создания, сначала новые.');
assert((new StorageSortHint(new SortHintTranslator('en')))->hint($own, $selected, true, $settings)['text'] === 'Sorted by date created, newest first.');
assert($hints->hint(['sort_unindexed' => [], 'large' => true, 'sort_default' => false], $selected, true, $settings) === null);
assert($hints->hint(['sort_unindexed' => ['qty'], 'large' => true, 'sort_default' => false], $selected, true, $settings)['field'] === 'qty');

// What makes a sort fast, by field.
assert(StorageSortHint::advice(['type' => 'string', 'constraints' => ['max_length' => 255]]) === 'indexed');
assert(StorageSortHint::advice(['type' => 'string', 'constraints' => ['max_length' => 256]]) === 'max_length');
assert(StorageSortHint::advice(['type' => 'text']) === 'max_length');
assert(StorageSortHint::advice(['type' => 'date']) === null);

// The same words in English.
$english = (new StorageSortHint(new SortHintTranslator('en')))->hint($slow('title'), $selected, true, $settings);
assert($english !== null && $english['text'] === 'Sorting a large storage by «Название» is slow.'
    && $english['link']['text'] === 'Turn on «Fast sorting and filter» for the field');
$englishLong = (new StorageSortHint(new SortHintTranslator('en')))->hint($slow('long'), $selected, true, $settings);
assert($englishLong !== null && $englishLong['link']['text'] === 'Give the field a maximum length of up to 255 characters to enable fast sorting');

echo "StorageSortHintTest passed.\n";
