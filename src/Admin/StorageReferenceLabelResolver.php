<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Larena\Access\Runtime\ScopeMembershipQueryScopeProvider;
use Larena\Access\Administration\AdministratorLabelReader;
use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Filesystem\Contracts\PersistentLogicalFileInspector;
use Larena\Property\Contracts\PropertyFieldDefinition;
use Larena\Property\Contracts\PropertyReferenceLabelResolver;
use Larena\Property\Contracts\PropertyRenderContext;

/** Resolve Storage workbench labels only through the current actor and scope. */
final readonly class StorageReferenceLabelResolver implements PropertyReferenceLabelResolver
{
    public function __construct(
        private ScopeMembershipQueryScopeProvider $scopes,
        private ActorOperationAuthorizer $authorizer,
        private AdministratorLabelReader $administrators,
        private PersistentLogicalFileInspector $files,
        private StorageRecordReferences $records,
    ) {}

    public function resolveLabel(PropertyFieldDefinition $field, int $version, string $reference, PropertyRenderContext $context): ?string
    {
        if ($context->actorRef === null || $context->scopeRef === null) {
            return null;
        }
        $this->scopes->assertActorScope($context->actorRef, $context->scopeRef);

        return match ($field->typeKey) {
            'user' => $this->administrators->label($reference, $context->actorRef),
            'file' => $this->fileLabel($reference, $context),
            'record' => is_string($field->constraints['target_structure_id'] ?? null)
                ? $this->records->label($context->actorRef, $context->scopeRef, $field->constraints['target_structure_id'], $reference)
                : null,
            // relation@1 is a legacy Content reference. With Content absent from
            // this composition, its raw identifier must never become a label.
            default => null,
        };
    }

    private function fileLabel(string $reference, PropertyRenderContext $context): ?string
    {
        $this->authorizer->assertAllowed($context->actorRef, 'filesystem.file.read');
        if (!$this->scopes->fileBelongsToScope($reference, $context->scopeRef)) {
            return null;
        }
        $inspection = $this->files->inspect($reference);
        $label = $inspection->safeMetadata['display_name'] ?? null;

        return $inspection->isContentAttachable() && is_string($label) ? $label : null;
    }
}
