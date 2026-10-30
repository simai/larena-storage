<?php

declare(strict_types=1);

namespace Larena\Storage\FirstRun;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;

/**
 * The site pages as an ordinary storage (larena-specs docs/architecture/2026-10-29-sites-work-plan.md, step 1.4;
 * owner decision 11): the starter site's schema adopted in place by the workbench, «Страницы сайта» in the storage
 * section of the scope the administrator works in. Its schema id, record ids, owner refs (site.pages:<slug>),
 * revisions, translations and publications stay as they are.
 *
 * A new installation adopts it once its first run has committed (SiteFirstRunContributor); an existing one with
 * larena:storage:adopt-site-pages. plan() is the dry run and writes nothing; adopt() is idempotent. Either way the
 * pages already adopted are reported as such without asking anyone's rights, since nothing is written.
 *
 * A page's content lives in its record (step 1.5, owner decision q-4ede0beabe): adopt() gives the storage the three
 * optional fields of CONTENT_FIELDS when it lacks them, as one more structure version (the workbench's update: an
 * optional field is a safe addition, no record is rewritten). A new installation gets them with its adoption, an
 * installation adopted before gets them on the next run of the command. The Home page keeps its values: without
 * the fields it reads as `text` with no body (contentMode()).
 *
 * A page's address and content form are per language (sites plan, step 4.3): `slug` and `content_mode` are localized,
 * and adopt() makes them so on every storage bound to the pages role, each with one more structure version
 * (localizeAddressFields()). No record is rewritten; a language with no translation keeps showing the shared slug.
 *
 * - `content_mode`: choice@1 `text` | `blocks`, optional, localized;
 * - `body`: html@1, optional, localized, public;
 * - `blocks`: content@1, optional, localized, public.
 */
final readonly class SitePagesStorage
{
    public const STRUCTURE_ID = 'workbench.site_pages';

    public const OWNER_REF_PREFIX = StarterSite::SCHEMA_ID . ':';

    /** A new page's owner ref ends with its slug, as the starter site's Home page does (site.pages:home). */
    public const OWNER_REF_FIELD = 'slug';

    public const LABEL_KEY = 'larena-storage::admin.first_run.site_pages';

    public const DEFAULT_LABEL = 'Site pages';

    /** The page content fields, in the order they are added. */
    public const CONTENT_FIELDS = ['content_mode', 'body', 'blocks'];

    /** «Выдаёт записи»: the storage whose records have their addresses under this page (sites plan, step 5.7). */
    public const RECORDS_FIELD = 'records_storage';

    /** The fields that became per language after a storage may have been made (sites plan, step 4.3). */
    public const LOCALIZED_FIELDS = ['slug', 'content_mode'];

    /** The fields nobody types: a page's write fills them (SitePageRecordValidator), so a form does not ask for them. */
    public const COMPUTED_FIELDS = ['target', 'order_index'];

    public const CONTENT_MODE_TEXT = 'text';

    public const CONTENT_MODE_BLOCKS = 'blocks';

    private const FIELD_LABEL_KEY = 'larena-storage::admin.first_run.site_page_fields.';

    /** The words when no translator answers, by key. */
    private const DEFAULT_FIELD_LABELS = [
        'slug' => 'Address', 'title' => 'Title', 'order_index' => 'Order', 'target' => 'Target', 'description' => 'Description',
        'content_mode' => 'Content form', 'body' => 'Text', 'blocks' => 'Blocks',
        'content_mode_text' => 'Text', 'content_mode_blocks' => 'Blocks', 'records_storage' => 'Gives out records of',
    ];

    /** @param Closure(string): string $scopeForActor The scope the person works in, the one the storage section lists. */
    public function __construct(
        private Connection $connection,
        private DatabaseStorageWorkbench $workbench,
        private Closure $scopeForActor,
        private ?Translator $translator = null,
    ) {
    }

    /**
     * What adopt() would do, without writing anything.
     *
     * @return array<string, mixed>
     */
    public function plan(string $actor, ?string $scopeRef = null, ?string $locale = null): array
    {
        $plan = $this->adopted() ?? $this->workbench->planAdoption($this->scope($actor, $scopeRef), self::STRUCTURE_ID,
            StarterSite::SCHEMA_ID, $this->label($locale), self::OWNER_REF_PREFIX, $actor, self::OWNER_REF_FIELD);

        return self::withContentFields($plan, $this->missingContentFields()) + ['localized_fields' => $this->localizeAddressFields($actor, null, false)];
    }

    /**
     * Adopts the site pages as the person, into the scope they work in (or the one named), and gives them the
     * content fields they lack. Returns the plan it followed, its `state` `adopted`, or `already_adopted` when the
     * pages were adopted before, and `content_fields`, the fields it added (none when nothing was written).
     *
     * @return array<string, mixed>
     */
    public function adopt(string $actor, ?string $scopeRef = null, ?string $locale = null, ?string $correlationId = null): array
    {
        $receipt = $this->adopted();
        if ($receipt === null) {
            $result = $this->workbench->adoptSchema($this->scope($actor, $scopeRef), self::STRUCTURE_ID, StarterSite::SCHEMA_ID,
                $this->label($locale), self::OWNER_REF_PREFIX, $actor, self::OWNER_REF_FIELD, $correlationId);
            $receipt = ['state' => $result['state']] + $result['plan'];
        }
        $missing = $this->missingContentFields();
        if ($missing !== []) {
            $this->addContentFields((string) $receipt['scope_ref'], $missing, $actor, $locale, $correlationId);
        }

        return self::withContentFields($receipt, $missing) + ['localized_fields' => $this->localizeAddressFields($actor, $correlationId)];
    }

    /**
     * Every storage bound to the pages role with `slug` and `content_mode` localized, and `target` and `order_index`
     * out of the forms (COMPUTED_FIELDS), as a structure version where one is needed. Each storage reports `localized`, `already` or, where the person may not change it, `refused`: a
     * refusal stops nothing, and the command run by someone who may finishes the rest. Without $write it only tells.
     *
     * @return list<array{schema_id: string, state: string, reason_code?: string}>
     */
    public function localizeAddressFields(string $actor, ?string $correlationId = null, bool $write = true): array
    {
        $report = [];
        foreach ($this->pageStorages() as $storage) {
            try {
                $structure = $this->workbench->structureForUpgrade($storage['scope_ref'], $storage['structure_id']);
                $changed = false;
                $fields = [];
                $present = array_map('strval', array_column($structure->fields, 'key'));
                $last = 0;
                foreach ($structure->fields as $field) {
                    $last = max($last, is_int($field['position'] ?? null) ? $field['position'] : 0);
                }
                // What a storage of pages made before them lacks: their optional fields, a safe addition.
                $added = [];
                foreach ([...$this->contentFields($last), $this->recordsField($last + 40)] as $field) {
                    if (!in_array($field['key'], $present, true)) {
                        $added[] = $field;
                        $changed = true;
                    }
                }
                foreach ($structure->fields as $field) {
                    if (in_array($field['key'] ?? null, self::LOCALIZED_FIELDS, true) && ($field['localized'] ?? false) !== true) {
                        $field['localized'] = true;
                        $changed = true;
                    }
                    if (in_array($field['key'] ?? null, self::COMPUTED_FIELDS, true) && (($field['required'] ?? false) !== false || ($field['hidden'] ?? false) !== true)) {
                        $field['required'] = false;
                        $field['hidden'] = true;
                        $changed = true;
                    }
                    $fields[] = $field;
                }
                if ($changed && $write) {
                    $this->workbench->upgradeStructure($storage['scope_ref'], $storage['structure_id'], $structure->version,
                        ['structure_id' => $storage['structure_id'], 'label' => $structure->label, 'fields' => [...$fields, ...$added]], $actor, $correlationId);
                }
                $report[] = ['schema_id' => $storage['schema_id'], 'state' => $changed ? 'localized' : 'already'];
            } catch (StorageRejected|InvalidArgumentException $refused) {
                $report[] = ['schema_id' => $storage['schema_id'], 'state' => 'refused', 'reason_code' => $refused instanceof StorageRejected ? $refused->reasonCode : $refused->getMessage()];
            }
        }

        return $report;
    }

    /**
     * The workbench storages bound to the pages role, on any site.
     *
     * @return list<array{scope_ref: string, structure_id: string, schema_id: string}>
     */
    private function pageStorages(): array
    {
        $storages = [];
        $schemas = $this->connection->table(DatabaseStructureRoleRegistry::BINDINGS_TABLE)
            ->where('role_ref', StarterSite::ROLE_REF)->where('status', 'active')->pluck('schema_id')->all();
        foreach ($this->connection->table('larena_storage_workbench_structures')
            ->whereIn('storage_schema_id', array_values(array_unique($schemas)))->whereNull('archived_at')->whereNull('import_run_ref')
            ->orderBy('created_at')->orderBy('structure_id')
            ->get(['scope_ref', 'structure_id', 'storage_schema_id']) as $row) {
            $storages[] = ['scope_ref' => (string) $row->scope_ref, 'structure_id' => (string) $row->structure_id, 'schema_id' => (string) $row->storage_schema_id];
        }

        return $storages;
    }

    /**
     * How a page shows its content: `content_mode` as stored; without one, `text` when `body` has a value, otherwise
     * `blocks` when `blocks` has items, otherwise `text`. $values are one language's values of a page record.
     *
     * @param array<string, mixed> $values
     */
    public static function contentMode(array $values): string
    {
        $mode = $values['content_mode'] ?? null;
        if ($mode === self::CONTENT_MODE_TEXT || $mode === self::CONTENT_MODE_BLOCKS) {
            return $mode;
        }
        $body = $values['body'] ?? null;
        if (is_string($body) && trim($body) !== '') {
            return self::CONTENT_MODE_TEXT;
        }
        $blocks = $values['blocks'] ?? null;

        return is_array($blocks) && $blocks !== [] ? self::CONTENT_MODE_BLOCKS : self::CONTENT_MODE_TEXT;
    }

    /**
     * The workbench field descriptors of the content fields, as they are added: after the storage's last field, the
     * labels and content_mode's option labels in the locale.
     *
     * @return list<array<string, mixed>>
     */
    public function contentFields(int $afterPosition, ?string $locale = null): array
    {
        $fields = [];
        foreach (self::CONTENT_FIELDS as $index => $key) {
            $fields[] = [
                'key' => $key,
                'label' => $this->word($key, $locale),
                'position' => $afterPosition + ($index + 1) * 10,
                'required' => false,
                'visibility' => 'public',
            ] + match ($key) {
                'content_mode' => ['type' => 'choice', 'type_version' => 1, 'localized' => true, 'constraints' => ['options' => [
                    ['value' => self::CONTENT_MODE_TEXT, 'label' => $this->word('content_mode_text', $locale)],
                    ['value' => self::CONTENT_MODE_BLOCKS, 'label' => $this->word('content_mode_blocks', $locale)],
                ]]],
                'body' => ['type' => 'html', 'type_version' => 1, 'localized' => true, 'constraints' => []],
                default => ['type' => 'content', 'type_version' => 1, 'localized' => true, 'constraints' => []],
            };
        }

        return $fields;
    }

    /**
     * The field that makes a page a section giving out records. The card offers it as a choice of the site's storages, so
     * the storage's own forms do not show it; it is not a text of the site's.
     *
     * @return array<string, mixed>
     */
    private function recordsField(int $position, ?string $locale = null): array
    {
        return ['key' => self::RECORDS_FIELD, 'label' => $this->word(self::RECORDS_FIELD, $locale), 'position' => $position, 'type' => 'string', 'type_version' => 2,
            'required' => false, 'visibility' => 'protected', 'hidden' => true, 'constraints' => ['max_length' => 120]];
    }

    /**
     * The workbench descriptor of a new site's pages (NewSiteStorages): an ordinary storage with the fields the
     * site page role asks for, as the starter site's schema has them, and the content fields.
     *
     * @return array{structure_id: string, label: string, fields: list<array<string, mixed>>}
     */
    public function descriptor(?string $locale = null): array
    {
        $string = fn (string $key, int $position, bool $required, int $max, bool $localized): array => [
            'key' => $key, 'label' => $this->word($key, $locale), 'position' => $position, 'type' => 'string', 'type_version' => 2,
            'required' => $required, 'visibility' => 'public', 'localized' => $localized, 'constraints' => ['max_length' => $max],
        ];

        return ['structure_id' => self::STRUCTURE_ID, 'label' => $this->label($locale), 'fields' => [
            $string('slug', 10, true, 120, true),
            $string('title', 20, true, 191, true),
            ['key' => 'order_index', 'label' => $this->word('order_index', $locale), 'position' => 30, 'type' => 'integer', 'type_version' => 1,
                'required' => false, 'hidden' => true, 'visibility' => 'public', 'constraints' => []],
            ['hidden' => true] + $string('target', 40, false, 512, false),
            $string('description', 50, false, 2000, true),
            ...$this->contentFields(50, $locale),
            $this->recordsField(90, $locale),
        ]];
    }

    /**
     * The content fields the site pages' schema lacks, in CONTENT_FIELDS order. Refuses a schema that has one of
     * them as another type: the page content contract is not the storage's to bend.
     *
     * @return list<string>
     */
    private function missingContentFields(): array
    {
        $types = ['content_mode' => 'choice', 'body' => 'html', 'blocks' => 'content'];
        $version = $this->connection->table('larena_storage_schemas')->where('schema_id', StarterSite::SCHEMA_ID)->value('current_version');
        $definition = json_decode((string) $this->connection->table('larena_storage_schema_versions')
            ->where('schema_id', StarterSite::SCHEMA_ID)->where('version', (int) $version)->value('definition'), true);
        $present = [];
        foreach (is_array($definition) && is_array($definition['fields'] ?? null) ? $definition['fields'] : [] as $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;
            if (is_string($key) && isset($types[$key])) {
                if (($field['type'] ?? null) !== $types[$key] || ($field['type_version'] ?? null) !== 1) {
                    throw new StorageRejected('storage_site_pages_content_field_conflict');
                }
                $present[$key] = true;
            }
        }

        return array_values(array_filter(self::CONTENT_FIELDS, static fn (string $key): bool => !isset($present[$key])));
    }

    /**
     * The adopted structure with the missing content fields, through the workbench's update: optional fields are a
     * safe addition, so no record is rewritten and the published revisions stay as they are.
     *
     * @param list<string> $missing
     */
    private function addContentFields(string $scopeRef, array $missing, string $actor, ?string $locale, ?string $correlationId): void
    {
        $structure = $this->workbench->readStructure($scopeRef, self::STRUCTURE_ID, $actor);
        $last = 0;
        foreach ($structure->fields as $field) {
            $last = max($last, is_int($field['position'] ?? null) ? $field['position'] : 0);
        }
        $added = array_values(array_filter($this->contentFields($last, $locale),
            static fn (array $field): bool => in_array($field['key'], $missing, true)));
        $this->workbench->updateStructure($scopeRef, self::STRUCTURE_ID, $structure->version, [
            'structure_id' => self::STRUCTURE_ID,
            'label' => $structure->label,
            'fields' => [...$structure->fields, ...$added],
        ], $actor, $correlationId);
    }

    /**
     * A receipt with the content fields it adds: `content_fields`, and one more schema version among its writes.
     *
     * @param array<string, mixed> $receipt
     * @param list<string> $missing
     * @return array<string, mixed>
     */
    private static function withContentFields(array $receipt, array $missing): array
    {
        if ($missing !== [] && is_array($receipt['writes'] ?? null) && is_int($receipt['writes']['schema_versions'] ?? null)) {
            $receipt['writes']['schema_versions']++;
        }

        return $receipt + ['content_fields' => $missing];
    }

    /**
     * The adopted structure as plan() reports it, or null while the pages are not adopted. Refuses when the
     * installation has no site pages.
     *
     * @return array<string, mixed>|null
     */
    private function adopted(): ?array
    {
        $schema = $this->connection->getSchemaBuilder();
        if (!$schema->hasTable('larena_storage_schemas')
            || !$this->connection->table('larena_storage_schemas')->where('schema_id', StarterSite::SCHEMA_ID)->exists()) {
            throw new StorageRejected('storage_site_pages_missing');
        }
        if (!$schema->hasColumn('larena_storage_workbench_structures', 'adopted_schema_id')) {
            throw new StorageRejected('storage_site_pages_migration_missing');
        }
        $head = $this->connection->table('larena_storage_workbench_structures')
            ->where('adopted_schema_id', StarterSite::SCHEMA_ID)
            ->first(['scope_ref', 'structure_id', 'current_schema_version']);
        if ($head === null) {
            return null;
        }

        return [
            'state' => DatabaseStorageWorkbench::ALREADY_ADOPTED,
            'scope_ref' => (string) $head->scope_ref,
            'structure_id' => (string) $head->structure_id,
            'schema_id' => StarterSite::SCHEMA_ID,
            'schema_version' => (int) $head->current_schema_version,
            'writes' => ['schema_versions' => 0, 'structures' => 0, 'record_versions' => 0],
        ];
    }

    private function scope(string $actor, ?string $scopeRef): string
    {
        $scopeRef = $scopeRef === null ? '' : trim($scopeRef);

        return $scopeRef !== '' ? $scopeRef : ($this->scopeForActor)($actor);
    }

    private function label(?string $locale): string
    {
        $label = $this->translator?->get(self::LABEL_KEY, [], $locale);

        return is_string($label) && $label !== '' && $label !== self::LABEL_KEY ? $label : self::DEFAULT_LABEL;
    }

    private function word(string $key, ?string $locale): string
    {
        $word = $this->translator?->get(self::FIELD_LABEL_KEY . $key, [], $locale);

        return is_string($word) && $word !== '' && $word !== self::FIELD_LABEL_KEY . $key ? $word : self::DEFAULT_FIELD_LABELS[$key];
    }
}
