<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments\Access;

use Larena\Access\Contracts\ActorOperationAuthorizer;
use Larena\Access\Contracts\QueryScopeProvider;
use Larena\Storage\BlockDocuments\BlockDocumentAuthorization;
use Larena\Storage\BlockDocuments\BlockDocumentRejected;
use Throwable;

final readonly class AccessBlockDocumentAuthorization implements BlockDocumentAuthorization
{
    private const RESOURCE_TYPE = 'content.item';
    private const SCOPE_OPERATION = 'content.item.list';
    private const GLOBAL_SCOPE = 'scope:global';

    public function __construct(
        private ActorOperationAuthorizer $operations,
        private QueryScopeProvider $scopes,
    ) {
    }

    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        if (!in_array($operation, [self::CREATE, self::UPDATE, self::READ], true)) {
            throw new BlockDocumentRejected('content_document_operation_unknown');
        }

        $this->operations->assertAllowed($actor, $operation);
        $this->scopeFor($actor, $scopeRef);
    }

    public function assertLogicalFileAllowed(string $actor, string $scopeRef, string $logicalFileRef): void
    {
        $this->scopeFor($actor, $scopeRef, $logicalFileRef);
    }

    private function scopeFor(string $actor, string $requestedScope, ?string $logicalFileRef = null): void
    {
        if (preg_match('/\Ascope:[a-z][a-z0-9_.:-]{1,119}\z/D', $requestedScope) !== 1) {
            throw new BlockDocumentRejected('content_document_scope_invalid');
        }

        try {
            if (!$this->scopes->supports(self::RESOURCE_TYPE, self::SCOPE_OPERATION)) {
                throw new BlockDocumentRejected('content_document_scope_unsupported');
            }
            $query = ['resource_type' => self::RESOURCE_TYPE];
            if ($logicalFileRef !== null) {
                $query['logical_file_ref'] = $logicalFileRef;
            }
            $scoped = $this->scopes->scope(
                $query,
                $actor,
                self::SCOPE_OPERATION,
                ['resource_type' => self::RESOURCE_TYPE],
            );

            if ($scoped === $query) {
                $decision = $this->scopes->explain(
                    self::RESOURCE_TYPE,
                    $actor,
                    self::SCOPE_OPERATION,
                    ['resource_type' => self::RESOURCE_TYPE],
                );
                if (!$decision->isAllowed()
                    || $decision->reasonCode !== 'persistent_global_role_scope_allowed'
                    || $decision->target !== self::RESOURCE_TYPE.':all'
                    || ($decision->explain['scope_mode'] ?? null) !== 'global_role'
                    || ($decision->explain['query_transformation'] ?? null) !== 'identity'
                    || ($decision->explain['resource_type'] ?? null) !== self::RESOURCE_TYPE
                    || $requestedScope !== self::GLOBAL_SCOPE) {
                    throw new BlockDocumentRejected('content_document_scope_denied');
                }

                return;
            }

            $expected = $logicalFileRef === null
                ? ['resource_type', 'scope_ref']
                : ['logical_file_authorized', 'logical_file_ref', 'resource_type', 'scope_ref'];
            $keys = array_keys($scoped);
            sort($keys, SORT_STRING);
            sort($expected, SORT_STRING);
            if ($keys !== $expected
                || ($scoped['resource_type'] ?? null) !== self::RESOURCE_TYPE
                || ($scoped['scope_ref'] ?? null) !== $requestedScope
                || ($logicalFileRef !== null && (
                    ($scoped['logical_file_ref'] ?? null) !== $logicalFileRef
                    || ($scoped['logical_file_authorized'] ?? null) !== true
                ))) {
                throw new BlockDocumentRejected('content_document_scope_denied');
            }
        } catch (BlockDocumentRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlockDocumentRejected('content_document_scope_denied');
        }
    }
}
