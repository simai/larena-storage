<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

final readonly class OptionalFieldCompatibilityAnalyzer
{
    public function __construct(private SchemaDefinitionNormalizer $normalizer)
    {
    }

    /**
     * @param array{schema_id: string, owner_package: string, fields: list<array<string, mixed>>} $source
     * @param array{schema_id: string, owner_package: string, fields: list<array<string, mixed>>} $target
     * @return array{compatible: bool, compatibility_class: string, added_optional_count: int, reason_codes: list<string>}
     */
    public function analyze(array $source, array $target): array
    {
        $reasons = [];
        if ($source['schema_id'] !== $target['schema_id'] || $source['owner_package'] !== $target['owner_package']) {
            $reasons[] = 'storage_schema_migration_identity_changed';
        }

        $sourceByKey = [];
        $sourceOrder = [];
        foreach ($source['fields'] as $field) {
            $key = (string) $field['key'];
            $sourceByKey[$key] = $field;
            $sourceOrder[] = $key;
        }
        $targetByKey = [];
        $targetOldOrder = [];
        $added = [];
        foreach ($target['fields'] as $field) {
            $key = (string) $field['key'];
            $targetByKey[$key] = $field;
            if (isset($sourceByKey[$key])) {
                $targetOldOrder[] = $key;
            } else {
                $added[] = $field;
            }
        }

        foreach ($sourceByKey as $key => $field) {
            if (!isset($targetByKey[$key])) {
                $reasons[] = 'storage_schema_migration_field_removed';
                continue;
            }
            if ($this->normalizer->canonicalJson($field) !== $this->normalizer->canonicalJson($targetByKey[$key])) {
                $reasons[] = 'storage_schema_migration_existing_field_changed';
            }
        }
        if ($targetOldOrder !== $sourceOrder) {
            $reasons[] = 'storage_schema_migration_field_order_changed';
        }
        foreach ($added as $field) {
            if (($field['required'] ?? false) !== false) {
                $reasons[] = 'storage_schema_migration_required_field_added';
            }
            if (($field['constraints'] ?? []) !== []) {
                $reasons[] = 'storage_schema_migration_added_field_constraints_unsupported';
            }
        }
        if ($added === [] && $reasons === []) {
            $reasons[] = 'storage_schema_migration_no_changes';
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'compatible' => $reasons === [],
            'compatibility_class' => $reasons === [] ? 'optional_additions' : 'incompatible',
            'added_optional_count' => count(array_filter($added, static fn (array $field): bool => ($field['required'] ?? false) === false)),
            'reason_codes' => $reasons,
        ];
    }
}
