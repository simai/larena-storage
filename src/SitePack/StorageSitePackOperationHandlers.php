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
 * The storage SitePack registry operations:
 *
 * - `storage.sitepack.export` starts the export of one structure, which runs as the `storage.sitepack.export` queue
 *   job. It reads every record, so it is a bulk operation and asks first; it changes no data.
 * - `storage.sitepack.import` applies an import's plan, bound by its digest, as the `storage.sitepack.import` queue
 *   job. It writes a whole storage into the site: bulk, asks first, and is not undone by itself (files it created
 *   stay).
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
            'storage.sitepack.import' => new OperationDescriptor(
                name: 'storage.sitepack.import',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.sitepack.import',
                auditEvent: 'storage.sitepack.imported',
                riskClass: OperationRiskClass::Bulk,
                reversible: false,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        if ($descriptor->name === 'storage.sitepack.import') {
            $run = $this->sitePack->apply(
                $this->string($context, 'run_ref'),
                $this->string($context, 'plan_digest'),
                $context->actorId,
                $context->correlationId,
            );

            return ['run' => ['run_ref' => $run->runRef, 'state' => $run->state, 'phase' => $run->phase]];
        }
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
        if ($descriptor->name === 'storage.sitepack.import') {
            $run = $this->sitePack->read($this->string($context, 'run_ref'), $context->actorId);

            return [
                'kind' => 'import_storage',
                'run_ref' => $run->runRef,
                'plan_digest' => $this->string($context, 'plan_digest'),
                'target_structure_id' => $run->structureId,
                'records' => (int) ($run->plan->records['total'] ?? 0),
            ];
        }

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
