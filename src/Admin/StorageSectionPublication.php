<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Larena\Core\Contracts\SiteLanguages;
use Larena\Admin\Http\Controllers\Site\SitePagesTreePortController;
use Larena\Admin\Settings\AdminSiteSettings;
use Larena\Storage\Contracts\PublicationLifecycle;
use Larena\Storage\Contracts\PublicationState;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Enums\RoleBindingStatus;
use Larena\Storage\Runtime\DatabaseStructureRoleRegistry;

/**
 * What the «Хранилища» section knows of a storage bound to a publishable role in a site («Переадресации», later
 * «Материалы сайта»): the site it is bound in, each record's publication per language in the words and badges of the
 * site pages tree, and which of «Опубликовать» and «Снять с публикации» a record allows. A storage without such a
 * binding has no publication here: its list and card stay as they are.
 */
final readonly class StorageSectionPublication
{
    /** The list's column of the badges; a key no field can take, so it never covers a field of the storage. */
    public const COLUMN = 'larena_publication';

    public const PUBLISH = 'publish';

    public const UNPUBLISH = 'unpublish';

    public function __construct(
        private Connection $database,
        private StructureRoleRegistry $roles,
        private PublicationLifecycle $publication,
        private ConfigRepository $config,
        private Translator $translator,
        private ?SiteLanguages $languages = null,
    ) {
    }

    /**
     * The site a schema is bound to a publishable role in, or null when it is not.
     *
     * @phpstan-impure
     */
    public function siteOf(string $schemaId): ?string
    {
        if (!$this->database->getSchemaBuilder()->hasTable(DatabaseStructureRoleRegistry::BINDINGS_TABLE)) {
            return null;
        }
        foreach ($this->database->table(DatabaseStructureRoleRegistry::BINDINGS_TABLE)
            ->where('schema_id', $schemaId)
            ->where('status', RoleBindingStatus::Active->value)
            ->orderBy('scope_ref')
            ->orderBy('role_ref')
            ->get(['role_ref', 'scope_ref']) as $binding) {
            if ($this->roles->read((string) $binding->role_ref)?->isPublishable() === true) {
                return (string) $binding->scope_ref;
            }
        }

        return null;
    }

    /**
     * @return list<string> the languages the site's records are published in (sites plan, step 4.2), as on the site pages
     *                      tree; with no site named, the application's
     *
     * @phpstan-impure
     */
    public function locales(?string $site = null): array
    {
        $locales = $site !== null && $this->languages !== null ? $this->languages->languages($site) : $this->config->get('larena-admin.structure_editors.locales', ['ru', 'en']);

        return array_values(array_filter(is_array($locales) ? $locales : [],
            static fn (mixed $locale): bool => is_string($locale) && preg_match('/^[a-z]{2,3}$/', $locale) === 1));
    }

    /**
     * One record's publication, a badge per language.
     *
     * @return list<array{value: string, text: string}>
     *
     * @phpstan-impure
     */
    public function badges(string $schemaId, string $recordId, string $site): array
    {
        $badges = [];
        foreach ($this->locales($site) as $locale) {
            $badges[] = SitePagesTreePortController::publicationBadge(
                $locale,
                $this->publication->head($schemaId, $recordId, $site, $locale),
                fn (string $key, array $replace): string => $this->word('larena-admin::'.$key, $replace),
                static fn (string $utc): string => Container::getInstance()->make(AdminSiteSettings::class)->moment($utc),
            );
        }

        return $badges;
    }

    /**
     * One record's publication as a line of the record card: «ru · Опубликовано, en · Черновик».
     *
     * @phpstan-impure
     */
    public function line(string $schemaId, string $recordId, string $site): string
    {
        return implode(', ', array_column($this->badges($schemaId, $recordId, $site), 'text'));
    }

    /**
     * What the person may do with the record: `publish:<locale>` for a record not yet out or a published one with a
     * newer revision, `unpublish:<locale>` for one out or scheduled.
     *
     * @param array<string, bool> $can by verb
     * @return list<string>
     *
     * @phpstan-impure
     */
    public function actions(string $schemaId, string $recordId, string $site, int $revision, array $can): array
    {
        $actions = [];
        foreach ($this->locales($site) as $locale) {
            $head = $this->publication->head($schemaId, $recordId, $site, $locale);
            foreach ([self::PUBLISH, self::UNPUBLISH] as $verb) {
                if (($can[$verb] ?? false) === true && self::allows($verb, $head, $revision)) {
                    $actions[] = $verb.':'.$locale;
                }
            }
        }

        return $actions;
    }

    /** Whether the verb means anything for a record at this revision in this state. */
    public static function allows(string $verb, ?PublicationState $head, int $revision): bool
    {
        $state = $head?->state->value ?? 'draft';
        if ($verb === self::UNPUBLISH) {
            return in_array($state, ['published', 'scheduled'], true);
        }
        $newer = $state === 'published' && $head !== null && $head->publishedRevision !== null && $head->publishedRevision < $revision;

        return $verb === self::PUBLISH && ($newer || !in_array($state, ['published', 'archived'], true));
    }

    /** The head of a record in a language, for a decision about one write. */
    public function head(string $schemaId, string $recordId, string $site, string $locale): ?PublicationState
    {
        return $this->publication->head($schemaId, $recordId, $site, $locale);
    }

    /**
     * The verb and language of a row action (`publish:ru`) or a bulk action (`publish_ru`: a bulk action's id takes no
     * colon), or null for any other action.
     *
     * @return array{0: string, 1: string}|null
     */
    public function parse(mixed $action, ?string $site = null): ?array
    {
        if (!is_string($action) || preg_match('/\A(publish|unpublish)[:_]([a-z]{2,3})\z/D', $action, $parts) !== 1
            || !in_array($parts[2], $this->locales($site), true)) {
            return null;
        }

        return [$parts[1], $parts[2]];
    }

    /** @return array<string, mixed> the list's column of the badges */
    public function column(): array
    {
        $states = ['published' => 'success', 'scheduled' => 'warning', 'draft' => 'neutral', 'archived' => 'neutral'];

        return ['key' => self::COLUMN, 'label' => $this->word('larena-admin::structure_editors.publication'), 'sortable' => false,
            'property_type' => 'choices', 'variant' => 'badges', 'visible' => true,
            'options' => array_map(fn (string $state, string $scheme): array => ['value' => $state,
                'label' => $this->word('larena-admin::structure_editors.states.'.$state), 'scheme' => $scheme], array_keys($states), $states)];
    }

    /**
     * The names of the row actions: a group per verb with its languages, as on the site pages tree.
     *
     * @return list<array<string, string>>
     */
    public function rowActions(?string $site = null): array
    {
        $actions = [];
        foreach ($this->locales($site) as $locale) {
            foreach ([self::PUBLISH, self::UNPUBLISH] as $verb) {
                $actions[] = ['id' => $verb.':'.$locale, 'label' => $locale, 'group' => $verb,
                    'group_label' => $this->word('larena-admin::structure_editors.actions.'.$verb)];
            }
        }

        return $actions;
    }

    /**
     * The list's actions for checked rows, by id.
     *
     * @param array<string, bool> $can by verb
     * @return array<string, string>
     */
    public function bulkActions(array $can, ?string $site = null): array
    {
        $actions = [];
        foreach ($this->locales($site) as $locale) {
            foreach ([self::PUBLISH, self::UNPUBLISH] as $verb) {
                if (($can[$verb] ?? false) === true) {
                    $actions[$verb.'_'.$locale] = $this->word('larena-admin::structure_editors.actions.'.$verb).' · '.$locale;
                }
            }
        }

        return $actions;
    }

    /** @param array<string, string> $replace */
    public function word(string $key, array $replace = []): string
    {
        $word = $this->translator->get($key, $replace);

        return is_string($word) ? $word : $key;
    }
}
