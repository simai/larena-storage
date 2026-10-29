<?php

declare(strict_types=1);

namespace Larena\Storage\Demo;

use Larena\Access\Contracts\AccessSubjectDirectory;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StorageWorkbenchRecordQuery;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Enums\StorageWorkbenchCountMode;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * The storage demo: «Каталог решений (демо)» with every Storage field type, choice lists with colors
 * and icons, people, dates and several hundred records, and «Разделы каталога», which the catalog's
 * «Раздел» field points at. Paging, search, every filter kind, bulk actions and the record panel can be
 * tried on realistic data.
 *
 * Records are generated deterministically and written through the workbench as the named person, with
 * that person's rights in the scope. Installing again only adds what is missing. The demo is removed like
 * any storage: archive the two storages and delete them in the storage section (catalog first).
 */
final readonly class StorageDemoData
{
    public const STRUCTURE_ID = 'workbench.demo_solutions';

    public const SECTIONS_ID = 'workbench.demo_sections';

    public const MAX_RECORDS = 480;

    public const DEFAULT_RECORDS = 450;

    /** How many administrators the demo may name as authors (user:admin_identity:1..N that are active). */
    private const PEOPLE = 50;

    private const PAGE = 100;

    /** Catalog sections: records link to them through the record field «Раздел». */
    private const SECTIONS = [
        'Платформа' => 'Ядро, Framework и обновления.',
        'Хранилище и данные' => 'Хранилища, резервные копии, импорт и экспорт.',
        'Платежи и биллинг' => 'Оплата, счета и тарифы.',
        'Контент' => 'Редактор, страницы и поиск по сайту.',
        'Магазин' => 'Каталог товаров и корзина.',
        'Пользователи' => 'Регистрации, уведомления и общение.',
        'Аналитика' => 'Отчёты и показатели.',
    ];

    private const SECTION_OF = ['Бекап файлов' => 'Хранилище и данные', 'Платежи' => 'Платежи и биллинг',
        'Хранилище данных' => 'Хранилище и данные', 'Редактор контента' => 'Контент', 'Framework 5' => 'Платформа',
        'Регистрации' => 'Пользователи', 'Обновления' => 'Платформа', 'Биллинг' => 'Платежи и биллинг', 'Каталог' => 'Магазин',
        'Корзина' => 'Магазин', 'Поиск' => 'Контент', 'Уведомления' => 'Пользователи', 'Отчёты' => 'Аналитика',
        'Импорт' => 'Хранилище и данные', 'Экспорт' => 'Хранилище и данные', 'Чаты' => 'Пользователи'];

    private const STATUSES = [
        ['value' => 'new', 'label' => 'Новое', 'color' => 'info', 'icon' => 'fiber_new'],
        ['value' => 'in_progress', 'label' => 'В работе', 'color' => 'primary', 'icon' => 'autorenew'],
        ['value' => 'review', 'label' => 'На проверке', 'color' => 'warning', 'icon' => 'rate_review'],
        ['value' => 'done', 'label' => 'Готово', 'color' => 'success', 'icon' => 'check_circle'],
        ['value' => 'canceled', 'label' => 'Отменено', 'color' => 'error', 'icon' => 'cancel'],
    ];

    private const PRIORITIES = [
        ['value' => 'low', 'label' => 'Низкий', 'color' => 'neutral', 'icon' => 'arrow_downward'],
        ['value' => 'medium', 'label' => 'Средний', 'color' => 'info', 'icon' => 'drag_handle'],
        ['value' => 'high', 'label' => 'Высокий', 'color' => 'warning', 'icon' => 'arrow_upward'],
        ['value' => 'critical', 'label' => 'Критичный', 'color' => 'error', 'icon' => 'priority_high'],
    ];

    private const MODULES = [
        ['value' => 'billing', 'label' => 'Биллинг', 'color' => '#7c3aed'],
        ['value' => 'storage', 'label' => 'Хранилище', 'color' => '#0891b2'],
        ['value' => 'content', 'label' => 'Контент', 'color' => '#16a34a'],
        ['value' => 'framework', 'label' => 'Framework', 'color' => '#2563eb'],
        ['value' => 'payments', 'label' => 'Платежи', 'color' => '#db2777'],
        ['value' => 'registrations', 'label' => 'Регистрации', 'color' => '#ea580c'],
        ['value' => 'updates', 'label' => 'Обновления', 'color' => '#4b5563'],
    ];

    private const NAMES = ['Бекап файлов', 'Платежи', 'Хранилище данных', 'Редактор контента', 'Framework 5', 'Регистрации',
        'Обновления', 'Биллинг', 'Каталог', 'Корзина', 'Поиск', 'Уведомления', 'Отчёты', 'Импорт', 'Экспорт', 'Чаты'];

    private const CODES = ['simai.filebackup', 'simai.pay', 'simai.storage', 'simai.editorjs', 'simai.main', 'simai.reg',
        'simai.update', 'simai.billing', 'simai.catalog', 'simai.cart', 'simai.search', 'simai.notify', 'simai.reports',
        'simai.import', 'simai.export', 'simai.chat'];

    public function __construct(
        private StorageWorkbench $storage,
        private ?AccessSubjectDirectory $people = null,
    ) {
    }

    /**
     * Creates or completes the demo in the scope as the person; what it did.
     *
     * @return array{scope_ref: string, structure_id: string, sections_id: string, created_structure: bool, created_records: int, sections: int, linked_records: int, linked_solutions: int, requested_records: int, people: int}
     */
    public function install(string $actor, string $scopeRef, int $records = self::DEFAULT_RECORDS): array
    {
        if ($records < 1 || $records > self::MAX_RECORDS) {
            throw new StorageRejected('storage_demo_records_invalid');
        }
        $people = $this->people($actor);
        $sections = $this->sections($actor, $scopeRef);
        $catalog = $this->structure($actor, $scopeRef, self::STRUCTURE_ID);
        $createdStructure = $catalog === null;
        $catalog = $catalog === null
            ? $this->storage->createStructure($scopeRef, $this->descriptor(), $actor)
            : $this->addField($actor, $scopeRef, $catalog, $this->sectionField());
        $existing = $this->existingRecords($actor, $scopeRef, self::STRUCTURE_ID);
        $created = 0;
        $linked = 0;
        for ($index = 1; $index <= $records; $index++) {
            $values = $this->values($index, $people, $sections);
            $current = $existing[$values['title']] ?? null;
            if ($current === null) {
                $this->storage->createRecord($scopeRef, self::STRUCTURE_ID, $values, $actor);
                $created++;
                continue;
            }
            // A record made before the sections existed gains its section; nothing else changes.
            if ($current->state !== 'archived' && !isset($current->values['section']) && isset($values['section'])) {
                $this->storage->updateRecord($scopeRef, self::STRUCTURE_ID, $current->recordId, $current->revision,
                    $current->values + ['section' => $values['section']], $actor);
                $linked++;
            }
        }
        // «Связанное решение» points at the catalog itself, so a record picker has hundreds of targets to
        // search, page through and show off the current page. The field needs the catalog to exist first.
        $this->addField($actor, $scopeRef, $this->storage->readStructure($scopeRef, self::STRUCTURE_ID, $actor), $this->linkedSolutionField());

        return ['scope_ref' => $scopeRef, 'structure_id' => self::STRUCTURE_ID, 'sections_id' => self::SECTIONS_ID,
            'created_structure' => $createdStructure, 'created_records' => $created, 'sections' => count($sections),
            'linked_records' => $linked, 'linked_solutions' => $this->linkSolutions($actor, $scopeRef),
            'requested_records' => $records, 'people' => count($people)];
    }

    /** The structure when the scope has it; an archived one is refused, since its records take no writes. */
    private function structure(string $actor, string $scopeRef, string $structureId): ?StorageWorkbenchStructure
    {
        foreach ($this->storage->listStructures($scopeRef, $actor, true) as $structure) {
            if ($structure->structureId === $structureId) {
                if ($structure->archivedAt !== null) {
                    throw new StorageRejected('storage_demo_structure_archived');
                }

                return $structure;
            }
        }

        return null;
    }

    /**
     * The authors records name: the person, and the other active administrators Access knows.
     *
     * @return list<string>
     */
    private function people(string $actor): array
    {
        $people = [$actor];
        if ($this->people === null) {
            return $people;
        }
        for ($id = 1; $id <= self::PEOPLE; $id++) {
            $ref = 'user:admin_identity:' . $id;
            if ($ref !== $actor && $this->people->isActive($ref)) {
                $people[] = $ref;
            }
        }

        return $people;
    }

    /** @return array<string, mixed> */
    private function descriptor(): array
    {
        $field = static fn (string $key, string $label, int $position, string $type, int $version, bool $required, array $constraints = []): array => [
            'key' => $key, 'label' => $label, 'position' => $position, 'type' => $type, 'type_version' => $version,
            'required' => $required, 'visibility' => 'admin', 'constraints' => $constraints,
        ];

        return ['structure_id' => self::STRUCTURE_ID, 'label' => 'Каталог решений (демо)', 'fields' => [
            $field('title', 'Название', 10, 'string', 2, true, ['min_length' => 1, 'max_length' => 120]),
            $field('code', 'Код решения', 20, 'string', 2, true, ['min_length' => 3, 'max_length' => 64]),
            $this->sectionField(),
            $field('version', 'Последняя версия', 30, 'string', 2, false, ['max_length' => 20]),
            $field('status', 'Статус', 40, 'choice', 1, true, ['options' => self::STATUSES]),
            $field('priority', 'Приоритет', 50, 'choice', 1, false, ['options' => self::PRIORITIES]),
            $field('modules', 'Модули', 60, 'choices', 1, false, ['options' => self::MODULES, 'max_items' => 7]),
            $field('author', 'Автор', 70, 'user', 1, false),
            $field('progress', 'Прогресс, %', 80, 'integer', 1, false, ['min' => 0, 'max' => 100]),
            $field('price', 'Стоимость, ₽', 90, 'number', 1, false, ['min' => '0', 'max' => '1000000']),
            $field('active', 'Активно', 100, 'boolean', 1, false),
            $field('deadline', 'Крайний срок', 110, 'date', 1, false, ['min' => '2026-01-01', 'max' => '2027-12-31']),
            $field('created_on', 'Создано', 120, 'datetime', 1, false),
            $field('updated_on', 'Обновлено', 130, 'datetime', 1, false),
            $field('summary', 'Описание', 140, 'text', 1, false, ['max_length' => 2000]),
            $field('document', 'Документ', 150, 'file', 1, false),
            $field('related', 'Связанный материал', 160, 'relation', 1, false),
        ]];
    }

    /**
     * One deterministic record per index, spread over every option and range so filters have work.
     *
     * @param list<string> $people @param array<string, string> $sections title => record id
     * @return array<string, mixed>
     */
    private function values(int $index, array $people, array $sections): array
    {
        $pick = static fn (int $salt, int $size): int => (int) (hexdec(substr(hash('sha256', $index . ':' . $salt), 0, 8)) % $size);
        $name = self::NAMES[$pick(1, count(self::NAMES))];
        $code = self::CODES[$pick(2, count(self::CODES))];
        $status = self::STATUSES[$pick(3, count(self::STATUSES))]['value'];
        $progress = match ($status) {
            'new' => 0, 'done' => 100, 'canceled' => $pick(4, 40), default => 10 + $pick(5, 81),
        };
        $modules = [];
        foreach (self::MODULES as $offset => $module) {
            if ($pick(10 + $offset, 3) === 0) {
                $modules[] = $module['value'];
            }
        }
        $created = (new \DateTimeImmutable('2026-01-05T09:00:00'))->modify('+' . ($index * 17 % 540) . ' days')->modify('+' . $pick(6, 600) . ' minutes');
        $updated = $created->modify('+' . $pick(7, 40) . ' days')->modify('+' . $pick(8, 480) . ' minutes');
        $values = [
            'title' => sprintf('SIMAI: %s №%03d', $name, $index),
            'code' => $code,
            'version' => sprintf('%d.%d.%d', 1 + $pick(20, 5), $pick(21, 10), $pick(22, 20)),
            'status' => $status,
            'priority' => self::PRIORITIES[$pick(23, count(self::PRIORITIES))]['value'],
            'progress' => $progress,
            'price' => sprintf('%d.%02d', 500 + $pick(24, 250000), $pick(25, 100)),
            'active' => $pick(26, 4) !== 0,
            'deadline' => min('2027-12-31', $created->modify('+' . (30 + $pick(27, 300)) . ' days')->format('Y-m-d')),
            'created_on' => $created->format('Y-m-d\TH:i:s'),
            'updated_on' => $updated->format('Y-m-d\TH:i:s'),
            'summary' => sprintf("Решение «%s» (%s).\nДемонстрационная запись для проверки списка, фильтров и панели записи.", $name, $code),
        ];
        if ($modules !== []) {
            $values['modules'] = $modules;
        }
        if (isset($sections[self::SECTION_OF[$name]])) {
            $values['section'] = $sections[self::SECTION_OF[$name]];
        }
        $values['author'] = $people[$pick(28, count($people))];

        return $values;
    }

    /**
     * Every record of the structure, archived ones too, by title.
     *
     * @return array<string, StorageWorkbenchRecord>
     */
    private function existingRecords(string $actor, string $scopeRef, string $structureId): array
    {
        $titles = [];
        $continuation = null;
        do {
            $page = $this->storage->listRecords(new StorageWorkbenchRecordQuery($scopeRef, $structureId, limit: self::PAGE,
                continuation: $continuation, includeArchived: true, countMode: StorageWorkbenchCountMode::Skip), $actor);
            foreach ($page->items as $record) {
                if (is_string($record->values['title'] ?? null)) {
                    $titles[$record->values['title']] = $record;
                }
            }
            $continuation = $page->continuation;
        } while ($continuation !== null && $page->items !== []);

        return $titles;
    }

    /** @return array<string, mixed> */
    private function sectionField(): array
    {
        return ['key' => 'section', 'label' => 'Раздел', 'position' => 25, 'type' => 'record', 'type_version' => 1,
            'required' => false, 'visibility' => 'admin', 'constraints' => ['target_structure_id' => self::SECTIONS_ID]];
    }

    /**
     * The «Разделы каталога» storage and its sections, created once; title => record id.
     *
     * @return array<string, string>
     */
    private function sections(string $actor, string $scopeRef): array
    {
        if ($this->structure($actor, $scopeRef, self::SECTIONS_ID) === null) {
            $this->storage->createStructure($scopeRef, ['structure_id' => self::SECTIONS_ID, 'label' => 'Разделы каталога', 'fields' => [
                ['key' => 'title', 'label' => 'Название', 'position' => 10, 'type' => 'string', 'type_version' => 2, 'required' => true,
                    'visibility' => 'admin', 'constraints' => ['min_length' => 1, 'max_length' => 120]],
                ['key' => 'summary', 'label' => 'Описание', 'position' => 20, 'type' => 'text', 'type_version' => 1, 'required' => false,
                    'visibility' => 'admin', 'constraints' => ['max_length' => 2000]],
            ]], $actor);
        }
        $existing = $this->existingRecords($actor, $scopeRef, self::SECTIONS_ID);
        $ids = [];
        foreach (self::SECTIONS as $title => $summary) {
            $ids[$title] = isset($existing[$title])
                ? $existing[$title]->recordId
                : $this->storage->createRecord($scopeRef, self::SECTIONS_ID, ['title' => $title, 'summary' => $summary], $actor)->recordId;
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function linkedSolutionField(): array
    {
        return ['key' => 'linked_solution', 'label' => 'Связанное решение', 'position' => 27, 'type' => 'record', 'type_version' => 1,
            'required' => false, 'visibility' => 'admin', 'constraints' => ['target_structure_id' => self::STRUCTURE_ID]];
    }

    /**
     * Every third solution names another one, chosen far from it so the chosen record is usually on another
     * page of the picker. Records that already name one keep it.
     */
    private function linkSolutions(string $actor, string $scopeRef): int
    {
        $records = array_filter($this->existingRecords($actor, $scopeRef, self::STRUCTURE_ID),
            static fn (StorageWorkbenchRecord $record): bool => $record->state !== 'archived');
        ksort($records);
        $titles = array_keys($records);
        $count = count($titles);
        $linked = 0;
        foreach ($titles as $position => $title) {
            $record = $records[$title];
            if ($position % 3 !== 0 || isset($record->values['linked_solution']) || $count < 2) {
                continue;
            }
            $target = $records[$titles[($position * 37 + 101) % $count]]->recordId;
            if ($target === $record->recordId) {
                $target = $records[$titles[($position + 1) % $count]]->recordId;
            }
            $this->storage->updateRecord($scopeRef, self::STRUCTURE_ID, $record->recordId, $record->revision,
                $record->values + ['linked_solution' => $target], $actor);
            $linked++;
        }

        return $linked;
    }

    /**
     * An existing catalog gains an optional field (a safe, additive change).
     *
     * @param array<string, mixed> $field
     */
    private function addField(string $actor, string $scopeRef, StorageWorkbenchStructure $structure, array $field): StorageWorkbenchStructure
    {
        if (in_array($field['key'], array_column($structure->fields, 'key'), true)) {
            return $structure;
        }

        return $this->storage->updateStructure($scopeRef, self::STRUCTURE_ID, $structure->version,
            ['structure_id' => self::STRUCTURE_ID, 'label' => $structure->label, 'fields' => [...$structure->fields, $field]], $actor);
    }
}
