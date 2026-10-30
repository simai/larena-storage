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
 * always read from the tree (SitePageAddresses). Nobody types `target` or `order_index`: a write that leaves the order
 * out keeps the page's, and a new page goes after its siblings.
 */
final readonly class SitePageRecordValidator implements StructureRoleRecordValidator
{
    public function __construct(
        private SitePageRules $rules,
        private SitePageTree $tree,
        private SitePageAddresses $addresses,
        private SitePagePlacement $placement,
        private ?SiteRecordAddresses $records = null,
    ) {
    }

    public function roleRef(): string
    {
        return SitePagePath::ROLE;
    }

    public function validate(StructureRoleRecordWrite $write): array
    {
        // «Выдаёт записи»: a storage the site can give out, and no other page's.
        $storage = $write->values[SiteRecordAddresses::SECTION_FIELD] ?? null;
        if (is_string($storage) && $this->records !== null) {
            foreach ($this->tree->sitesOf($write->schemaId) as $site) {
                $why = $this->records->whyNotSection($write->schemaId, $write->recordId, $site, $storage);
                if ($why !== null) {
                    throw new StorageRejected($why);
                }
            }
        }
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
        $values = $write->values;
        if (!is_int($values[SitePagePath::ORDER_FIELD] ?? null)) {
            $values[SitePagePath::ORDER_FIELD] = $write->recordId === null
                ? $this->tree->nextOrder($write->schemaId, $parent)
                : ($this->tree->currentOrder($write->schemaId, $write->recordId) ?? $this->tree->nextOrder($write->schemaId, $parent));
        }
        $target = $this->addresses->currentAddress($write->schemaId, $parent, $slug);

        return $target === null ? $values : ['target' => $target] + $values;
    }

    public function written(StructureRoleRecordWrite $write, StorageWorkbenchRecord $record, StorageWorkbench $workbench): void
    {
    }
}
