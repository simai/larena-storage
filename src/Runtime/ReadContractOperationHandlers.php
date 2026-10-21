<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Storage\Contracts\PublishedListQuery;
use Larena\Storage\Contracts\PublishedListReads;
use Larena\Storage\Contracts\PublishedReadVisibility;
use Larena\Storage\Contracts\ReadContracts;
use Larena\Storage\Exceptions\ReadContractRejected;

/**
 * The four read operations.
 *
 * All three are reads, so none implements the proposal port: a read is its own preview,
 * and offering to "propose" one would be a second name for the same thing.
 *
 * Each read passes the caller's visibility filter, so an operation never returns a
 * record the caller could not read on the site.
 */
final readonly class ReadContractOperationHandlers implements OperationHandler
{
    public function __construct(
        private ReadContracts $readContracts,
        private PublishedReadVisibility $visibility = new PublishedRecordsArePublic(),
        // The public lists; the read contracts themselves when they offer them.
        private ?PublishedListReads $lists = null,
    ) {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $public = 'storage.read.public';

        $descriptors = [
            // The three public reads carry no access scope. They return only the
            // published head's public fields, which is what an anonymous visitor of
            // the site sees anyway, and the REST public site boundary cannot name
            // an Access check an anonymous caller could pass. Which records each
            // caller may read is still filtered, by PublishedReadVisibility.
            new OperationDescriptor(
                name: 'storage.read.resolve_key',
                executionMode: OperationExecutionMode::Sync,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.read.published_projection',
                executionMode: OperationExecutionMode::Sync,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.read.published_list',
                executionMode: OperationExecutionMode::Sync,
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
        $target = $this->string($context, 'target');
        $scopeRef = $this->string($context, 'scope_ref');
        $filter = $this->visibility->filterFor($context->actorId, $target, $scopeRef);

        return match ($descriptor->name) {
            'storage.read.resolve_key' => ['resolved' => $this->readContracts->resolveKey(
                $target,
                $scopeRef,
                $this->string($context, 'locale'),
                $this->string($context, 'key_field'),
                $this->string($context, 'key_value'),
                $filter,
            )?->toArray()],
            'storage.read.published_projection' => $this->readContracts->publishedProjection(
                $target,
                $scopeRef,
                $this->string($context, 'locale'),
                $this->optionalInt($context, 'budget'),
                $filter,
            )->toArray(),
            'storage.read.published_list' => $this->lists()->publishedList(PublishedListQuery::fromInput($context->metadata), $filter)->toArray(),
            'storage.read.projection_explain' => $this->readContracts->projectionExplain(
                $target,
                $scopeRef,
                $this->string($context, 'locale'),
                $filter,
            ),
            default => throw new ReadContractRejected(
                'unknown_operation',
                sprintf('Operation "%s" is not a read contract operation.', $descriptor->name),
            ),
        };
    }

    private function lists(): PublishedListReads
    {
        $lists = $this->lists ?? ($this->readContracts instanceof PublishedListReads ? $this->readContracts : null);
        if ($lists === null) {
            throw new ReadContractRejected('published_list_unavailable', 'No public list reader is composed.');
        }

        return $lists;
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
