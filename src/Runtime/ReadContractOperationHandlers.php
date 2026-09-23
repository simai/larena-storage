<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Storage\Contracts\ReadContracts;
use Larena\Storage\Exceptions\ReadContractRejected;

/**
 * The three read operations.
 *
 * All three are reads, so none implements the proposal port: a read is its own preview,
 * and offering to "propose" one would be a second name for the same thing.
 */
final readonly class ReadContractOperationHandlers implements OperationHandler
{
    public function __construct(private ReadContracts $readContracts)
    {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $public = 'storage.read.public';

        $descriptors = [
            new OperationDescriptor(
                name: 'storage.read.resolve_key',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $public,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.read.published_projection',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $public,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.read.projection_explain',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $public,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
        ];

        $indexed = [];
        foreach ($descriptors as $descriptor) {
            $indexed[$descriptor->name] = $descriptor;
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'storage.read.resolve_key' => ['resolved' => $this->readContracts->resolveKey(
                $this->string($context, 'target'),
                $this->string($context, 'scope_ref'),
                $this->string($context, 'locale'),
                $this->string($context, 'key_field'),
                $this->string($context, 'key_value'),
            )?->toArray()],
            'storage.read.published_projection' => $this->readContracts->publishedProjection(
                $this->string($context, 'target'),
                $this->string($context, 'scope_ref'),
                $this->string($context, 'locale'),
                $this->optionalInt($context, 'budget'),
            )->toArray(),
            'storage.read.projection_explain' => $this->readContracts->projectionExplain(
                $this->string($context, 'target'),
                $this->string($context, 'scope_ref'),
                $this->string($context, 'locale'),
            ),
            default => throw new ReadContractRejected(
                'unknown_operation',
                sprintf('Operation "%s" is not a read contract operation.', $descriptor->name),
            ),
        };
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ReadContractRejected('invalid_input', sprintf('Read operations require a non-empty "%s".', $key));
        }

        return $value;
    }

    private function optionalInt(OperationContext $context, string $key): ?int
    {
        $value = $context->metadata[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
