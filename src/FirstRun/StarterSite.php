<?php

declare(strict_types=1);

namespace Larena\Storage\FirstRun;

use Illuminate\Database\Connection;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\Starter\ScopeBaselineInstaller;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Contracts\VersionedStorage;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\StarterStructureRoles;
use Larena\Storage\Contracts\StorageSchemaVersionRef;

/**
 * The site a fresh installation starts with: one Home page.
 *
 * It is what the first run created when larena/content owned it — one page, in the
 * installer's locale, with a welcome text naming the site — now written as a
 * site_node record through Storage's own record and role APIs, left a draft. A
 * retirement changes who owns a behaviour, not the behaviour.
 *
 * Nothing here seeds through SitePack, because no SitePack runtime exists in the
 * selected closure. plan() is the dry run: it says what apply() would write and
 * writes nothing.
 */
final readonly class StarterSite
{
    public const SCHEMA_ID = 'site.pages';

    public const ROLE_REF = 'site_node@v1';

    public const RECORD_OWNER_REF = 'site.pages:home';

    public const HOME_SLUG = 'home';

    public const OWNER_PACKAGE = 'larena/storage';

    public function __construct(
        private Connection $connection,
        private VersionedStorage $storage,
        private StructureRoleRegistry $roles,
        private StarterStructureRoles $starterRoles,
        private ScopeBaselineInstaller $scopes,
    ) {
    }

    /**
     * What apply() would write, without writing it.
     *
     * @return array<string, mixed>
     */
    public function plan(string $siteName, string $locale): array
    {
        return [
            'scope_ref' => $this->scopeRef(),
            'schema_id' => self::SCHEMA_ID,
            'role_ref' => self::ROLE_REF,
            'record_owner_ref' => self::RECORD_OWNER_REF,
            'values' => $this->values($siteName, $locale),
            'publication' => 'none: the page starts as a draft, as it always has',
            'state' => $this->state(),
            'writes' => $this->state() === FirstRunContributor::STATE_EMPTY,
        ];
    }

    public function state(): string
    {
        $schema = $this->connection->getSchemaBuilder();
        foreach (['larena_storage_schemas', 'larena_storage_records'] as $table) {
            if (!$schema->hasTable($table)) {
                return FirstRunContributor::STATE_PARTIAL;
            }
        }

        $hasSchema = $this->connection->table('larena_storage_schemas')->where('schema_id', self::SCHEMA_ID)->exists();
        $record = $this->connection->table('larena_storage_records')
            ->where('schema_id', self::SCHEMA_ID)
            ->where('owner_ref', self::RECORD_OWNER_REF)
            ->value('record_id');

        if (!$hasSchema && $record === null) {
            return FirstRunContributor::STATE_EMPTY;
        }

        if ($hasSchema && $record !== null) {
            return FirstRunContributor::STATE_INITIALIZED;
        }

        return FirstRunContributor::STATE_PARTIAL;
    }

    /**
     * Writes the Home page as a draft. Returns its record id.
     *
     * It is not published. The Content version left it a draft too, and the
     * welcome text says so: the administrator edits it, then publishes it.
     *
     * Refuses anything but an empty starting state, exactly as the Content version
     * did: a half-written starter site is repaired by an operator, not overwritten.
     */
    public function apply(string $actor, string $siteName, string $locale): string
    {
        if ($this->state() !== FirstRunContributor::STATE_EMPTY) {
            throw new StorageRejected('starter_site_already_applied');
        }

        $this->scopes->apply();
        $this->starterRoles->install($actor);

        $schema = $this->storage->registerSchemaVersion($this->schemaDefinition(), null, $actor);
        $this->roles->bindStructure(
            self::ROLE_REF,
            self::SCHEMA_ID,
            $this->scopeRef(),
            $this->roleFields(),
            $actor,
            ['site_tree_parent'],
            'publishable',
        );

        $written = $this->storage->create(
            self::RECORD_OWNER_REF,
            new StorageSchemaVersionRef(self::SCHEMA_ID, $schema->ref->version),
            $this->values($siteName, $locale),
            $actor,
        );

        return $written->version->ref->recordId;
    }

    private function scopeRef(): string
    {
        return $this->scopes->defaultSiteRef()->toString();
    }

    /**
     * @return array<string, int|string>
     */
    private function values(string $siteName, string $locale): array
    {
        $name = trim($siteName);
        $russian = $locale === 'ru';

        return [
            'slug' => self::HOME_SLUG,
            'title' => $russian ? 'Главная' : 'Home',
            'order_index' => 0,
            'target' => '/',
            'description' => $russian
                ? 'Добро пожаловать на сайт «' . $name . '». Отредактируйте и опубликуйте эту страницу.'
                : 'Welcome to ' . $name . '. Edit and publish this page.',
        ];
    }

    /** @return array<string, mixed> */
    private function schemaDefinition(): array
    {
        $string = static fn (string $key, bool $required, int $max): array => [
            'key' => $key, 'type' => 'string', 'type_version' => 2, 'required' => $required,
            'visibility' => 'public', 'constraints' => ['max_length' => $max],
        ];

        return [
            'schema_id' => self::SCHEMA_ID,
            'owner_package' => self::OWNER_PACKAGE,
            'fields' => [
                $string('slug', true, 120),
                $string('title', true, 191),
                ['key' => 'order_index', 'type' => 'integer', 'type_version' => 1, 'required' => true, 'visibility' => 'public', 'constraints' => []],
                $string('target', true, 512),
                $string('description', false, 2000),
            ],
        ];
    }

    /** @return list<array{key: string, type: string}> */
    private function roleFields(): array
    {
        return [
            ['key' => 'slug', 'type' => 'string'],
            ['key' => 'title', 'type' => 'string'],
            ['key' => 'order_index', 'type' => 'integer'],
            ['key' => 'target', 'type' => 'string'],
            ['key' => 'description', 'type' => 'string'],
        ];
    }
}
