<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Throwable;

/**
 * The reader's rights from Access, by the operations the workbench and the publication lifecycle check: reading the
 * record (storage.workbench.record.read in its scope), saving it (storage.workbench.record.update) and publishing it
 * (storage.publication.publish, only for an element with a site). Every language may be written.
 */
final readonly class AccessDocumentPortRights implements DocumentPortRights
{
    private const RECORD_RESOURCE = 'storage.workbench.record';

    public function __construct(private QueryScopeProvider $scopes, private ActorOperationAuthorizer $authorizer)
    {
    }

    public function rights(DocumentPortBinding $binding, string $actor): ?array
    {
        if (!$this->inScope($actor, 'storage.workbench.record.read', $binding->scopeRef)) {
            return null;
        }

        return [
            'save' => $this->inScope($actor, 'storage.workbench.record.update', $binding->scopeRef),
            'publish' => $binding->siteScopeRef !== null && $this->allowed($actor, 'storage.publication.publish'),
        ];
    }

    /** The workbench's own check of an operation in a scope (DatabaseStorageWorkbench::assertScope), answered. */
    private function inScope(string $actor, string $operation, string $scopeRef): bool
    {
        try {
            if (!$this->scopes->supports(self::RECORD_RESOURCE, $operation)) {
                return false;
            }
            $context = ['requested_scope_ref' => $scopeRef, 'resource_type' => self::RECORD_RESOURCE];
            $decision = $this->scopes->explain(self::RECORD_RESOURCE, $actor, $operation, $context);
            if (!$decision->isAllowed() || $decision->actor !== $actor || $decision->operation !== $operation) {
                return false;
            }
            $scoped = $this->scopes->scope(['scope_ref' => $scopeRef], $actor, $operation, $context);
            if (array_keys($scoped) !== ['scope_ref'] || $scoped['scope_ref'] !== $scopeRef) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return $this->allowed($actor, $operation);
    }

    private function allowed(string $actor, string $operation): bool
    {
        try {
            $this->authorizer->assertAllowed($actor, $operation);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
