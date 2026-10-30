<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Admin\Runtime\StructureEditorOperations;
use Larena\Storage\Contracts\StorageWorkbench;
use Larena\Storage\SitePage\SitePageRules;
use Throwable;

/**
 * «Опубликовать» and «Снять с публикации» of one record of a publishable storage, wherever the section offers them:
 * through the declared storage.publication.* operations with the site of the role's binding, so the rights, the audit
 * and the guards are the ones the site pages tree goes through.
 */
final readonly class StorageSectionPublicationActions
{
    public const CHANGED = 'storage_publication_record_changed';

    public const NOT_ACTIVE = 'storage_publication_record_not_active';

    public function __construct(
        private StorageSectionPublication $publication,
        private StorageWorkbench $workbench,
        private StructureEditorOperations $operations,
        private ?SitePageRules $pages = null,
    ) {
    }

    public function publication(): StorageSectionPublication
    {
        return $this->publication;
    }

    /**
     * The storage's schema and the site it is bound in, or null when it has no publishable binding.
     *
     * @return array{schema: string, site: string}|null
     */
    public function binding(string $actor, string $scope, string $structure): ?array
    {
        try {
            $schema = $this->workbench->readStructure($scope, $structure, $actor)->schema->schemaId;
            $site = $this->publication->siteOf($schema);
        } catch (Throwable) {
            return null;
        }

        return $site === null ? null : ['schema' => $schema, 'site' => $site];
    }

    /** @return array<string, bool> by verb, what the person may do */
    public function can(string $actor): array
    {
        return [
            StorageSectionPublication::PUBLISH => $this->operations->allowed($actor, 'storage.publication.publish'),
            StorageSectionPublication::UNPUBLISH => $this->operations->allowed($actor, 'storage.publication.unpublish'),
        ];
    }

    /**
     * Puts one record out, or takes it back, in one language. A record that moved on since the person saw it, or that
     * is in the archive, is refused here before any write.
     *
     * @param array{schema: string, site: string} $binding
     * @return string|null why it was refused, null when done
     */
    public function write(string $actor, string $scope, string $structure, array $binding, string $recordId, string $verb, string $locale, int $seenRevision): ?string
    {
        try {
            $record = $this->workbench->readRecord($scope, $structure, $recordId, $actor);
        } catch (Throwable) {
            return 'record_unknown';
        }
        if ($record->state !== 'active') {
            return self::NOT_ACTIVE;
        }
        if ($record->revision !== $seenRevision) {
            return self::CHANGED;
        }
        // The operation would keep a page's refusal to itself: asked first, so the person is told why.
        if ($verb === StorageSectionPublication::PUBLISH) {
            $why = $this->pages?->whyNotPublish($binding['schema'], $recordId, $binding['site'], $locale, $record->revision);
            if ($why !== null) {
                return $why;
            }
        }
        $result = $this->operations->execute($actor, 'storage.publication.'.$verb, ['schema_id' => $binding['schema'], 'record_id' => $recordId,
            'scope_ref' => $binding['site'], 'locale' => $locale] + ($verb === StorageSectionPublication::PUBLISH ? ['revision' => $record->revision] : []));

        return $result->successful() ? null : $result->decision->reasonCode;
    }
}
