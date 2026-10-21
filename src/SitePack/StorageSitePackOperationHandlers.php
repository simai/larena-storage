<?php

declare(strict_types=1);

namespace Larena\Storage\SitePack;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Storage\Contracts\StorageSitePack;
use Larena\Storage\Exceptions\StorageRejected;

/**
 * `storage.sitepack.export` as a registry operation: starts the export of one structure, which runs as the
 * `storage.sitepack.export` queue job. It reads every record, so it is a bulk operation and asks first; it changes no
 * data.
 */
final readonly class StorageSitePackOperationHandlers implements OperationProposalHandler
{
    public function __construct(private StorageSitePack $sitePack)
    {
    }

    /** @return array<string, OperationDescriptor> */
    public static function descriptors(): array
    {
        return [
            'storage.sitepack.export' => new OperationDescriptor(
                name: 'storage.sitepack.export',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.sitepack.export',
                auditEvent: 'storage.sitepack.exported',
                riskClass: OperationRiskClass::Bulk,
                reversible: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        if ($descriptor->name !== 'storage.sitepack.export') {
            throw new StorageRejected('unknown_operation');
        }
        $run = $this->sitePack->startExport(
            $this->string($context, 'scope_ref'),
            $this->string($context, 'structure_id'),
            $context->actorId,
            $context->correlationId,
        );

        return ['run' => ['run_ref' => $run->runRef, 'state' => $run->state, 'phase' => $run->phase]];
    }

    /** @return array<string, mixed> */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return [
            'kind' => 'export_storage',
            'scope_ref' => $this->string($context, 'scope_ref'),
            'structure_id' => $this->string($context, 'structure_id'),
        ];
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new StorageRejected('invalid_input');
        }

        return $value;
    }
}
