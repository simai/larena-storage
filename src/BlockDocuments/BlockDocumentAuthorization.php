<?php

declare(strict_types=1);

namespace Larena\Storage\BlockDocuments;

interface BlockDocumentAuthorization
{
    public const CREATE = 'content.item.create';
    public const UPDATE = 'content.item.update';
    public const READ = 'content.item.read';
    public const PROJECT = 'content.item.read';

    public function assertAllowed(string $actor, string $operation, string $scopeRef): void;

    public function assertLogicalFileAllowed(string $actor, string $scopeRef, string $logicalFileRef): void;
}
