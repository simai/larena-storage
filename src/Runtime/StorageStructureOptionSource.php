<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Illuminate\Database\ConnectionInterface;
use Larena\Property\Contracts\PropertyOptionSource;
use Larena\Property\Contracts\PropertyScopedOptionSource;
use Larena\Property\Runtime\PropertyOptionSourceRegistry;
use Larena\Property\Runtime\PropertyTypeSettingsCatalog;
use Larena\Storage\Contracts\StorageWorkbench;
use Throwable;

/**
 * The storages (workbench structures) as a Property option source, `storage.structures`: what a «Storage record»
 * field points to and a lookup field over storages chooses from.
 *
 * Without a scope the source lists nothing (a list belongs to a scope and a person) and answers contains() and
 * label() over every active storage, for value validation. withinScope() lists the active storages of one scope as
 * the person may see them (StorageWorkbench::listStructures), searched by name and id.
 */
final readonly class StorageStructureOptionSource implements PropertyScopedOptionSource
{
    public const KEY = 'storage.structures';

    private const HEADS = 'larena_storage_workbench_structures';
    private const VERSIONS = 'larena_storage_workbench_structure_versions';

    public function __construct(
        private ConnectionInterface $database,
        private ?StorageWorkbench $workbench = null,
        private ?string $scopeRef = null,
        private ?string $actor = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(?string $locale = null): string
    {
        return $locale !== null && str_starts_with($locale, 'ru') ? 'Хранилища' : 'Storages';
    }

    public function options(?string $search, int $limit, ?string $locale = null): array
    {
        if ($this->workbench === null || $this->scopeRef === null || $this->actor === null) {
            return [];
        }
        return PropertyOptionSourceRegistry::page($this->scoped(), $search, $limit);
    }

    public function contains(string $value): bool
    {
        if (preg_match(PropertyTypeSettingsCatalog::STRUCTURE_PATTERN, $value) !== 1) {
            return false;
        }
        if ($this->workbench !== null && $this->scopeRef !== null && $this->actor !== null) {
            return in_array($value, array_column($this->scoped(), 'value'), true);
        }

        return $this->database->table(self::HEADS)->where('structure_id', $value)->whereNull('archived_at')->exists();
    }

    public function label(string $value, ?string $locale = null): ?string
    {
        if (preg_match(PropertyTypeSettingsCatalog::STRUCTURE_PATTERN, $value) !== 1) {
            return null;
        }
        try {
            $label = $this->database->table(self::HEADS.' as heads')
                ->join(self::VERSIONS.' as versions', static function ($join): void {
                    $join->on('versions.structure_id', '=', 'heads.structure_id')
                        ->on('versions.scope_ref', '=', 'heads.scope_ref')
                        ->on('versions.version', '=', 'heads.current_version');
                })
                ->where('heads.structure_id', $value)
                ->when($this->scopeRef !== null, fn ($query) => $query->where('heads.scope_ref', $this->scopeRef))
                ->value('versions.label');
        } catch (Throwable) {
            return null;
        }

        return is_string($label) && trim($label) !== '' ? $label : null;
    }

    public function withinScope(string $scopeRef, string $actor): PropertyOptionSource
    {
        return new self($this->database, $this->workbench, $scopeRef, $actor);
    }

    /** @return list<array{value: string, label: string}> */
    private function scoped(): array
    {
        $options = [];
        foreach ($this->workbench?->listStructures((string) $this->scopeRef, (string) $this->actor) ?? [] as $structure) {
            $label = trim($structure->label);
            $options[] = ['value' => $structure->structureId, 'label' => $label !== '' ? $label : $structure->structureId];
        }

        return $options;
    }
}
