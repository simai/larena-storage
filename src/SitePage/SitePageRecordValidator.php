<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StructureRoleRecordValidator;
use Larena\Storage\Contracts\StructureRoleRecordWrite;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * Every write of a site page through the workbench: its slug is checked (SitePageRules) and its `target` field, kept for
 * what reads it from before addresses followed the tree, is filled with where the page stands by the current slugs.
 * That field is a note of the moment of the write: a move of a page above does not rewrite it; the address itself is
 * always read from the tree (SitePageAddresses).
 */
final readonly class SitePageRecordValidator implements StructureRoleRecordValidator
{
    public function __construct(
        private SitePageRules $rules,
        private SitePageTree $tree,
        private SitePageAddresses $addresses,
        private SitePagePlacement $placement,
    ) {
    }

    public function roleRef(): string
    {
        return SitePagePath::ROLE;
    }

    public function validate(StructureRoleRecordWrite $write): array
    {
        $slug = $write->values[SitePagePath::SLUG_FIELD] ?? null;
        // A value of the wrong kind is the field types' to refuse, with their own reason.
        if (!is_string($slug)) {
            return $write->values;
        }
        $parent = $write->recordId === null ? $this->placement->parent() : $this->tree->parentOf($write->schemaId, $write->recordId);
        $why = $this->rules->whyNotWrite($write->schemaId, $write->recordId, $parent, $slug, $write->locale);
        if ($why !== null) {
            throw new StorageRejected($why);
        }
        // A language's own slug leaves the shared `target` alone: that is the shared values' note.
        if ($write->locale !== null) {
            return $write->values;
        }
        $target = $this->addresses->currentAddress($write->schemaId, $parent, $slug);

        return $target === null ? $write->values : ['target' => $target] + $write->values;
    }

    public function written(StructureRoleRecordWrite $write, StorageWorkbenchRecord $record, StorageWorkbench $workbench): void
    {
    }
}
