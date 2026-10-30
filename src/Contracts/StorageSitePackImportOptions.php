<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use Larena\Storage\Exceptions\StorageRejected;

/**
 * What the person chose for one SitePack import (larena-specs docs/architecture/2026-10-22-storage-sitepack.md).
 *
 * - `targetStructureId`: the structure id in the target scope; null keeps the pack's id when it is free there, and
 *   proposes `<id>_2`, `<id>_3` … otherwise. A given id that is taken is a plan error.
 * - `targetLabel`: the structure's label; null keeps the pack's.
 * - `publication`: `draft` (default: every record arrives unpublished) or `keep` (the pack's publication states,
 *   through the publication lifecycle; needs the publish and schedule rights).
 * - `referenceTargets`: external structure id (a record@1 target in the pack) → local structure id. A target that is
 *   not mapped maps to the structure of the same id in the target scope, when there is one.
 * - `copy`: remap every record id (and import a pack this site already imported again).
 * - `declaredLocales`: the locales the target site declares; null asks the application (the site's locales).
 * - `transferDomains`: «Перенести домены»: add the domains the pack carries to the target site. Off by default,
 *   because a new place usually has other domains.
 */
final readonly class StorageSitePackImportOptions
{
    public const string PUBLICATION_DRAFT = 'draft';
    public const string PUBLICATION_KEEP = 'keep';

    /** @var array<string, string> */
    public array $referenceTargets;

    /** @var list<string>|null */
    public ?array $declaredLocales;

    /**
     * Checks what it is given: the options come from a person (a form, a command line).
     *
     * @param array<mixed> $referenceTargets
     * @param array<mixed>|null $declaredLocales
     */
    public function __construct(
        public ?string $targetStructureId = null,
        public ?string $targetLabel = null,
        public string $publication = self::PUBLICATION_DRAFT,
        array $referenceTargets = [],
        public bool $copy = false,
        ?array $declaredLocales = null,
        public bool $transferDomains = false,
    ) {
        $structure = '/\Aworkbench\.[a-z][a-z0-9_.:-]{0,109}\z/D';
        if (($targetStructureId !== null && preg_match($structure, $targetStructureId) !== 1)
            || ($targetLabel !== null && (trim($targetLabel) === '' || mb_strlen($targetLabel) > 120))
            || !in_array($publication, [self::PUBLICATION_DRAFT, self::PUBLICATION_KEEP], true)
            || count($referenceTargets) > 100) {
            throw new StorageRejected('storage_sitepack_import_options_invalid');
        }
        foreach ($referenceTargets as $external => $local) {
            if (!is_string($external) || !is_string($local) || $external === '' || strlen($external) > 120
                || preg_match($structure, $local) !== 1) {
                throw new StorageRejected('storage_sitepack_import_options_invalid');
            }
        }
        $locales = null;
        if ($declaredLocales !== null) {
            if (!array_is_list($declaredLocales) || count($declaredLocales) > 100) {
                throw new StorageRejected('storage_sitepack_import_options_invalid');
            }
            $locales = [];
            foreach ($declaredLocales as $locale) {
                if (!is_string($locale) || preg_match('/\A[a-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?\z/D', $locale) !== 1) {
                    throw new StorageRejected('storage_sitepack_import_options_invalid');
                }
                $locales[] = $locale;
            }
        }
        $targets = [];
        foreach ($referenceTargets as $external => $local) {
            $targets[(string) $external] = (string) $local;
        }
        $this->referenceTargets = $targets;
        $this->declaredLocales = $locales;
    }

    public function keepsPublication(): bool
    {
        return $this->publication === self::PUBLICATION_KEEP;
    }

    /** The same options with the locales the application declares, when the person named none. @param list<string> $locales */
    public function withDeclaredLocales(array $locales): self
    {
        return new self($this->targetStructureId, $this->targetLabel, $this->publication, $this->referenceTargets, $this->copy, array_values(array_unique($locales)), $this->transferDomains);
    }

    /** @return array{target_structure_id: ?string, target_label: ?string, publication: string, reference_targets: array<string, string>, copy: bool, declared_locales: ?list<string>, transfer_domains: bool} */
    public function toArray(): array
    {
        $targets = $this->referenceTargets;
        ksort($targets, SORT_STRING);
        $locales = $this->declaredLocales;
        if ($locales !== null) {
            sort($locales, SORT_STRING);
        }

        return [
            'target_structure_id' => $this->targetStructureId,
            'target_label' => $this->targetLabel,
            'publication' => $this->publication,
            'reference_targets' => $targets,
            'copy' => $this->copy,
            'declared_locales' => $locales,
            'transfer_domains' => $this->transferDomains,
        ];
    }

    /** @param array<array-key, mixed> $options */
    public static function fromArray(array $options): self
    {
        $targets = $options['reference_targets'] ?? [];
        $locales = $options['declared_locales'] ?? null;
        $structure = $options['target_structure_id'] ?? null;
        $label = $options['target_label'] ?? null;
        $publication = $options['publication'] ?? self::PUBLICATION_DRAFT;
        if (!is_array($targets) || ($locales !== null && !is_array($locales)) || ($structure !== null && !is_string($structure))
            || ($label !== null && !is_string($label)) || !is_string($publication) || !is_bool($options['copy'] ?? false) || !is_bool($options['transfer_domains'] ?? false)) {
            throw new StorageRejected('storage_sitepack_import_options_invalid');
        }
        return new self($structure, $label, $publication, $targets, (bool) ($options['copy'] ?? false), $locales, (bool) ($options['transfer_domains'] ?? false));
    }
}
