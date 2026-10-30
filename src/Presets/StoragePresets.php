<?php

declare(strict_types=1);

namespace Larena\Storage\Presets;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchStructure;
use Larena\Storage\Contracts\StructureRoleRegistry;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Exceptions\StorageRejected;
use Larena\Storage\FirstRun\SitePagesStorage;
use Larena\Storage\FirstRun\StarterSite;
use Larena\Storage\Runtime\StarterStructureRoles;

/**
 * What a new storage can start from (sites plan, step 5.6). A preset leaves only ordinary fields and, where it says so, a
 * binding to a role: there is no stored kind of storage, and the fields stay editable like any other. `plain` is the empty
 * storage; `materials` holds records with addresses a page of the site gives out; `pages` is a further tree of pages,
 * which does not replace the one the site's setting chooses.
 */
final readonly class StoragePresets
{
    public const PLAIN = 'plain';

    public const MATERIALS = 'materials';

    public const PAGES = 'pages';

    public const KEYS = [self::PLAIN, self::MATERIALS, self::PAGES];

    /** @param Closure(string): ?string $siteOfScope the site whose data lives in a workbench scope */
    public function __construct(
        private StorageWorkbench $workbench,
        private StructureRoleRegistry $roles,
        private StarterStructureRoles $starterRoles,
        private SitePagesStorage $pages,
        private Closure $siteOfScope,
        private ?Translator $translator = null,
    ) {
    }

    /** @return list<array{value: string, label: string}> */
    public function options(?string $locale = null): array
    {
        return array_map(fn (string $key): array => ['value' => $key, 'label' => $this->word('presets.' . $key, ucfirst($key), $locale)], self::KEYS);
    }

    /** The new storage with the preset's fields, bound to its role in the site of the scope. */
    public function create(string $scopeRef, string $structureId, string $label, string $preset, string $actor, ?string $locale = null, ?string $correlationId = null): StorageWorkbenchStructure
    {
        $fields = match ($preset) {
            self::MATERIALS => $this->materialsFields($locale),
            self::PAGES => $this->pages->descriptor($locale)['fields'],
            default => throw new StorageRejected('storage_preset_unknown'),
        };
        $site = ($this->siteOfScope)($scopeRef);
        if (!is_string($site)) {
            throw new StorageRejected('storage_preset_site_unknown');
        }
        $this->starterRoles->install($actor, $correlationId);
        $structure = $this->workbench->createStructure($scopeRef, ['structure_id' => $structureId, 'label' => $label, 'fields' => $fields], $actor, $correlationId);
        $this->roles->bindStructure(
            $preset === self::MATERIALS ? StarterStructureRoles::SITE_RECORD_REF : StarterSite::ROLE_REF,
            $structure->schema->schemaId,
            $site,
            array_map(static fn (array $field): array => ['key' => (string) $field['key'], 'type' => (string) $field['type']], $structure->fields),
            $actor,
            $preset === self::PAGES ? ['site_tree_parent'] : [],
            RoleLifecycle::Publishable->value,
            $correlationId,
        );

        return $structure;
    }

    /** @return list<array<string, mixed>> */
    private function materialsFields(?string $locale): array
    {
        $string = fn (string $key, int $position, bool $required, int $max, bool $localized): array => [
            'key' => $key, 'label' => $this->word('material_fields.' . $key, ucfirst($key), $locale), 'position' => $position, 'type' => 'string', 'type_version' => 2,
            'required' => $required, 'visibility' => 'public', 'localized' => $localized, 'constraints' => ['max_length' => $max],
        ];

        return [
            $string('slug', 10, true, 120, true),
            $string('title', 20, true, 191, true),
            $string('description', 30, false, 2000, true),
            ...$this->pages->contentFields(30, $locale),
            ...$this->pages->seoFields(60, $locale),
        ];
    }

    private function word(string $key, string $fallback, ?string $locale): string
    {
        $word = $this->translator?->get('larena-storage::admin.' . $key, [], $locale);

        return is_string($word) && !str_starts_with($word, 'larena-storage::') ? $word : $fallback;
    }
}
