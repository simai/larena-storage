<?php

declare(strict_types=1);

namespace Larena\Storage\FirstRun;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Runtime\DatabaseStorageWorkbench;

/**
 * The site pages as an ordinary storage (larena-specs docs/architecture/2026-10-29-sites-work-plan.md, step 1.4;
 * owner decision 11): the starter site's schema adopted in place by the workbench, «Страницы сайта» in the storage
 * section of the scope the administrator works in. Its schema id, record ids, owner refs (site.pages:<slug>),
 * revisions, translations and publications stay as they are.
 *
 * A new installation adopts it once its first run has committed (SiteFirstRunContributor); an existing one with
 * larena:storage:adopt-site-pages. plan() is the dry run and writes nothing; adopt() is idempotent. Either way the
 * pages already adopted are reported as such without asking anyone's rights, since nothing is written.
 */
final readonly class SitePagesStorage
{
    public const STRUCTURE_ID = 'workbench.site_pages';

    public const OWNER_REF_PREFIX = StarterSite::SCHEMA_ID . ':';

    /** A new page's owner ref ends with its slug, as the starter site's Home page does (site.pages:home). */
    public const OWNER_REF_FIELD = 'slug';

    public const LABEL_KEY = 'larena-storage::admin.first_run.site_pages';

    public const DEFAULT_LABEL = 'Site pages';

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
        $adopted = $this->adopted();
        if ($adopted !== null) {
            return $adopted;
        }

        return $this->workbench->planAdoption($this->scope($actor, $scopeRef), self::STRUCTURE_ID, StarterSite::SCHEMA_ID,
            $this->label($locale), self::OWNER_REF_PREFIX, $actor, self::OWNER_REF_FIELD);
    }

    /**
     * Adopts the site pages as the person, into the scope they work in (or the one named). Returns the plan it
     * followed, its `state` `adopted`, or `already_adopted` when nothing was written.
     *
     * @return array<string, mixed>
     */
    public function adopt(string $actor, ?string $scopeRef = null, ?string $locale = null, ?string $correlationId = null): array
    {
        $adopted = $this->adopted();
        if ($adopted !== null) {
            return $adopted;
        }
        $result = $this->workbench->adoptSchema($this->scope($actor, $scopeRef), self::STRUCTURE_ID, StarterSite::SCHEMA_ID,
            $this->label($locale), self::OWNER_REF_PREFIX, $actor, self::OWNER_REF_FIELD, $correlationId);

        return ['state' => $result['state']] + $result['plan'];
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
}
