<?php

declare(strict_types=1);

namespace Larena\Storage\SitePage;

use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\Contracts\StorageWorkbenchRecord;
use Larena\Storage\Contracts\StructureRoleRecordValidator;
use Larena\Storage\Contracts\StructureRoleRecordWrite;
use Larena\Storage\Exceptions\StorageRejected;

/** Every write of a record with an address through the workbench: its slug is a slug, and this record's alone in the storage. */
final readonly class SiteRecordValidator implements StructureRoleRecordValidator
{
    public function __construct(private SiteRecordAddresses $records)
    {
    }

    public function roleRef(): string
    {
        return SiteRecordAddresses::ROLE;
    }

    public function validate(StructureRoleRecordWrite $write): array
    {
        $seo = SitePageSeo::whyNot($write->values);
        if ($seo !== null) {
            throw new StorageRejected($seo);
        }
        $slug = $write->values[SitePagePath::SLUG_FIELD] ?? null;
        if (is_string($slug)) {
            $why = $this->records->whyNotSlug($write->schemaId, $write->recordId, $slug);
            if ($why !== null) {
                throw new StorageRejected($why);
            }
        }

        return $write->values;
    }

    public function written(StructureRoleRecordWrite $write, StorageWorkbenchRecord $record, StorageWorkbench $workbench): void
    {
    }
}
