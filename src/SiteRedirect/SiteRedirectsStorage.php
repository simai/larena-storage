<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\Exceptions\StructureRoleRejected;
use Larena\Storage\Runtime\StarterStructureRoles;

/**
 * Each site's «Переадресации» / "Redirects" (larena-specs docs/architecture/2026-10-29-sites-work-plan.md, step 2.6): an
 * ordinary storage, `workbench.site_redirects`, in the site's workbench scope (its data scope: `scope:minimal-cms` for
 * Root's main site), bound to site_redirect@v1 in the site. A new installation gets it once its first run has
 * committed, after the site pages (SiteFirstRunContributor); an existing one with larena:storage:site-redirects.
 *
 * create() is idempotent per site: a site whose rules have a storage already is `already_present`; a storage of the
 * id left unbound (a run stopped between the two writes) is bound (`bound`); otherwise it is made and bound
 * (`created`). A site that cannot get one is `refused` with the reason, and the other sites go on. plan() is the dry
 * run: what create() would do, nothing written.
 *
 * The fields, in the run's language: from_path and to_path (string, required), status_code (choice 301 | 302),
 * match (choice exact | prefix), origin (choice manual | auto), all required, and note (text, optional).
 */
final readonly class SiteRedirectsStorage
{
    public const STRUCTURE_ID = 'workbench.site_redirects';

    public const LABEL_KEY = 'larena-storage::admin.first_run.site_redirects';

    public const DEFAULT_LABEL = 'Redirects';

    public const CREATED = 'created';

    public const BOUND = 'bound';

    public const ALREADY_PRESENT = 'already_present';

    public const PLANNED = 'planned';

    public const REFUSED = 'refused';

    private const FIELD_LABEL_KEY = 'larena-storage::admin.first_run.site_redirect_fields.';

    /** The words when no translator answers, by key. */
    private const DEFAULT_WORDS = [
        'from_path' => 'From', 'to_path' => 'To', 'status_code' => 'Code', 'match' => 'Match', 'origin' => 'Origin', 'note' => 'Note',
        'status_code_301' => '301 — moved permanently', 'status_code_302' => '302 — moved temporarily',
        'match_exact' => 'This address', 'match_prefix' => 'Addresses starting with it',
        'origin_manual' => 'Manual', 'origin_auto' => 'Automatic',
    ];

    /**
     * @param Closure(): list<string> $sites the sites, by scope ref
     * @param Closure(string, string): string $scopeOfSite a site's workbench scope, for the person
     */
    public function __construct(
        private ConnectionInterface $database,
        private StorageWorkbench $workbench,
        private StructureRoleRegistry $roles,
        private StarterStructureRoles $starterRoles,
        private SiteRedirectRules $rules,
        private Closure $sites,
        private Closure $scopeOfSite,
        private ?Translator $translator = null,
    ) {
    }

    /**
     * What create() would do, without writing anything.
     *
     * @return array{sites: list<array<string, mixed>>, writes: array{structures: int, bindings: int}}
     */
    public function plan(string $actor, ?string $siteRef = null, ?string $locale = null): array
    {
        return $this->each($actor, $siteRef, function (string $site, string $scope, ?array $present): array {
            return ['state' => self::PLANNED, 'action' => $present === null ? 'create' : 'bind'];
        }, $locale);
    }

    /**
     * Gives each site (or the one named) its redirects storage, as the person.
     *
     * @return array{sites: list<array<string, mixed>>, writes: array{structures: int, bindings: int}}
     */
    public function create(string $actor, ?string $siteRef = null, ?string $locale = null, ?string $correlationId = null): array
    {
        $installed = false;

        return $this->each($actor, $siteRef, function (string $site, string $scope, ?array $present) use ($actor, $locale, $correlationId, &$installed): array {
            if (!$installed) {
                $this->starterRoles->install($actor, $correlationId);
                $installed = true;
            }
            $structure = $present === null
                ? $this->workbench->createStructure($scope, $this->descriptor($locale), $actor, $correlationId)
                : $this->workbench->readStructure($scope, self::STRUCTURE_ID, $actor);
            $this->roles->bindStructure(StarterStructureRoles::SITE_REDIRECT_REF, $structure->schema->schemaId, $site,
                array_map(static fn (array $field): array => ['key' => (string) $field['key'], 'type' => (string) $field['type']], $structure->fields),
                $actor, [], RoleLifecycle::Publishable->value, $correlationId);

            return ['state' => $present === null ? self::CREATED : self::BOUND, 'schema_id' => $structure->schema->schemaId];
        }, $locale);
    }

    /**
     * The workbench descriptor of the storage, labelled in the locale.
     *
     * @return array{structure_id: string, label: string, fields: list<array<string, mixed>>}
     */
    public function descriptor(?string $locale = null): array
    {
        $field = fn (string $key, int $position, string $type, int $version, bool $required, array $constraints): array => [
            'key' => $key, 'label' => $this->word($key, $locale), 'position' => $position, 'type' => $type, 'type_version' => $version,
            'required' => $required, 'visibility' => 'admin', 'constraints' => $constraints,
        ];
        $options = fn (string $key, array $values): array => ['options' => array_map(
            fn (string $value): array => ['value' => $value, 'label' => $this->word($key . '_' . $value, $locale)], $values)];

        return ['structure_id' => self::STRUCTURE_ID, 'label' => $this->label($locale), 'fields' => [
            $field('from_path', 10, 'string', 2, true, ['min_length' => 1, 'max_length' => SiteRedirectPath::MAX_LENGTH]),
            $field('to_path', 20, 'string', 2, true, ['min_length' => 1, 'max_length' => SiteRedirectPath::MAX_LENGTH]),
            $field('status_code', 30, 'choice', 1, true, $options('status_code', ['301', '302'])),
            $field('match', 40, 'choice', 1, true, $options('match', [SiteRedirectPath::MATCH_EXACT, SiteRedirectPath::MATCH_PREFIX])),
            $field('origin', 50, 'choice', 1, true, $options('origin', [SiteRedirectRule::ORIGIN_MANUAL, SiteRedirectRule::ORIGIN_AUTO])),
            $field('note', 60, 'text', 1, false, ['max_length' => 2000]),
        ]];
    }

    /**
     * Runs $step for each site without a storage of its rules; the others are `already_present`.
     *
     * @param Closure(string, string, ?array<string, mixed>): array<string, mixed> $step
     * @return array{sites: list<array<string, mixed>>, writes: array{structures: int, bindings: int}}
     */
    private function each(string $actor, ?string $siteRef, Closure $step, ?string $locale): array
    {
        $siteRef = $siteRef === null ? '' : trim($siteRef);
        $sites = $siteRef !== '' ? [$siteRef] : ($this->sites)();
        $receipts = [];
        $writes = ['structures' => 0, 'bindings' => 0];
        foreach ($sites as $site) {
            $receipt = ['site_ref' => $site, 'structure_id' => self::STRUCTURE_ID];
            $storages = $this->rules->storages($site);
            if ($storages !== []) {
                $receipts[] = ['state' => self::ALREADY_PRESENT, 'scope_ref' => $storages[0]['scope_ref'], 'structure_id' => $storages[0]['structure_id'],
                    'schema_id' => $storages[0]['schema_id']] + $receipt;

                continue;
            }
            try {
                $scope = ($this->scopeOfSite)($site, $actor);
                $present = $this->database->table('larena_storage_workbench_structures')
                    ->where('scope_ref', $scope)->where('structure_id', self::STRUCTURE_ID)->first(['storage_schema_id']);
                $present = $present === null ? null : (array) $present;
                $done = $step($site, $scope, $present);
            } catch (StorageRejected $rejected) {
                $receipts[] = ['state' => self::REFUSED, 'reason_code' => $rejected->reasonCode] + $receipt;

                continue;
            } catch (StructureRoleRejected $rejected) {
                $receipts[] = ['state' => self::REFUSED, 'reason_code' => $rejected->reasonCode] + $receipt;

                continue;
            } catch (InvalidArgumentException $invalid) {
                $receipts[] = ['state' => self::REFUSED, 'reason_code' => $invalid->getMessage()] + $receipt;

                continue;
            }
            $action = $done['action'] ?? $done['state'];
            $writes['structures'] += $action === 'create' || $action === self::CREATED ? 1 : 0;
            $writes['bindings']++;
            $receipts[] = $done + ['scope_ref' => $scope, 'label' => $this->label($locale)] + $receipt;
        }

        return ['sites' => $receipts, 'writes' => $writes];
    }

    private function label(?string $locale): string
    {
        $label = $this->translator?->get(self::LABEL_KEY, [], $locale);

        return is_string($label) && $label !== '' && $label !== self::LABEL_KEY ? $label : self::DEFAULT_LABEL;
    }

    private function word(string $key, ?string $locale): string
    {
        $word = $this->translator?->get(self::FIELD_LABEL_KEY . $key, [], $locale);

        return is_string($word) && $word !== '' && $word !== self::FIELD_LABEL_KEY . $key ? $word : self::DEFAULT_WORDS[$key];
    }
}
