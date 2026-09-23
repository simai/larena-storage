<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Storage\Audit\LocalizedValueAuditEventCatalog;
use Larena\Storage\Contracts\LocaleFallbackChain;
use Larena\Storage\Contracts\LocalizedValue;
use Larena\Storage\Contracts\LocalizedValues;
use Larena\Storage\Exceptions\LocalizedValueRejected;

/**
 * The five locale operations.
 *
 * A write is a change rather than a bulk operation: it touches one revision in one
 * locale, and an editor saving a translation should not be asked to confirm every
 * save. What makes it safe is that the revision is immutable, so a mistaken write
 * cannot overwrite an earlier translation.
 */
final readonly class LocaleOperationHandlers implements OperationProposalHandler
{
    public function __construct(private LocalizedValues $values)
    {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $read = 'storage.locale.read';

        $descriptors = [
            new OperationDescriptor(
                name: 'storage.locale.read',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.locale.write',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'storage.locale.write',
                auditEvent: LocalizedValueAuditEventCatalog::WRITTEN,
                idempotencyKey: 'content_hash',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.locale.fallback_resolve',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.locale.coverage',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'storage.locale.explain',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
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
            'storage.locale.read' => ['values' => $this->flatten($this->values->read(
                $this->string($context, 'schema_id'),
                $this->string($context, 'record_id'),
                $this->int($context, 'revision'),
                $this->string($context, 'locale'),
            ))],
            'storage.locale.write' => ['written' => $this->write($context)],
            'storage.locale.fallback_resolve' => ['values' => $this->flatten($this->values->resolve(
                $this->string($context, 'schema_id'),
                $this->string($context, 'record_id'),
                $this->int($context, 'revision'),
                new LocaleFallbackChain($this->stringList($context, 'locales')),
                $this->stringList($context, 'field_keys'),
            ))],
            'storage.locale.coverage' => $this->values->coverage(
                $this->string($context, 'schema_id'),
                $this->string($context, 'record_id'),
                $this->int($context, 'revision'),
                $this->stringList($context, 'declared_locales'),
                $this->stringList($context, 'localized_field_keys'),
            )->toArray(),
            'storage.locale.explain' => $this->values->explain(
                $this->string($context, 'schema_id'),
                $this->string($context, 'record_id'),
                $this->int($context, 'revision'),
            ),
            default => throw new LocalizedValueRejected(
                'unknown_operation',
                sprintf('Operation "%s" is not a locale operation.', $descriptor->name),
            ),
        };
    }

    /**
     * Describe the write without making it.
     *
     * The interesting question before writing a translation is what the record would
     * still be missing, so the proposal carries the coverage the write would produce
     * rather than only the values it would store.
     *
     * @return array<string, mixed>
     */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        if ($descriptor->name !== 'storage.locale.write') {
            throw new LocalizedValueRejected(
                'proposal_unsupported',
                sprintf('Operation "%s" has nothing to propose.', $descriptor->name),
            );
        }

        $localizedFields = $this->stringList($context, 'localized_field_keys');
        $locale = $this->string($context, 'locale');
        $incoming = $this->values_($context);

        $coverage = $this->values->coverage(
            $this->string($context, 'schema_id'),
            $this->string($context, 'record_id'),
            $this->int($context, 'revision'),
            [$locale],
            $localizedFields,
        );

        $present = $coverage->perLocale[$locale]['present'] ?? [];
        $wouldHave = array_values(array_unique([...$present, ...array_keys($incoming)]));
        sort($wouldHave);

        return [
            'kind' => 'write_locale',
            'locale' => $locale,
            'fields' => array_keys($incoming),
            'already_present' => $present,
            'would_still_be_missing' => array_values(array_diff($localizedFields, $wouldHave)),
        ];
    }

    /**
     * @return list<string>
     */
    private function write(OperationContext $context): array
    {
        return $this->values->write(
            $this->string($context, 'schema_id'),
            $this->string($context, 'record_id'),
            $this->int($context, 'revision'),
            $this->string($context, 'locale'),
            $this->values_($context),
            $this->stringList($context, 'localized_field_keys'),
            $context->actorId,
            $this->stringList($context, 'required_field_keys'),
            (bool) ($context->metadata['partial_locales_allowed'] ?? false),
            $context->correlationId,
        );
    }

    /**
     * @param array<string, LocalizedValue> $values
     * @return list<array<string, mixed>>
     */
    private function flatten(array $values): array
    {
        return array_values(array_map(
            static fn (LocalizedValue $value): array => $value->toArray(),
            $values,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function values_(OperationContext $context): array
    {
        $values = $context->metadata['values'] ?? null;
        if (!is_array($values) || array_is_list($values)) {
            throw new LocalizedValueRejected('invalid_input', '"values" must be a mapping of field key to value.');
        }

        $mapped = [];
        foreach ($values as $key => $value) {
            $mapped[(string) $key] = $value;
        }

        return $mapped;
    }

    /**
     * @return list<string>
     */
    private function stringList(OperationContext $context, string $key): array
    {
        $value = $context->metadata[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new LocalizedValueRejected('invalid_input', sprintf('"%s" must be a list.', $key));
        }

        $entries = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new LocalizedValueRejected('invalid_input', sprintf('Every member of "%s" must be a string.', $key));
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new LocalizedValueRejected('invalid_input', sprintf('Locale operations require a non-empty "%s".', $key));
        }

        return $value;
    }

    private function int(OperationContext $context, string $key): int
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_int($value)) {
            throw new LocalizedValueRejected('invalid_input', sprintf('Locale operations require an integer "%s".', $key));
        }

        return $value;
    }
}
