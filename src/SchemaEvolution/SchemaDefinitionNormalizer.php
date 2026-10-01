<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Larena\Property\Contracts\PropertyConstraintValidator;
use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

final readonly class SchemaDefinitionNormalizer
{
    /**
     * Property types whose `options` constraint is a declared list of option objects.
     * Every other constraint stays scalar-only.
     */
    private const OPTION_LIST_TYPES = ['choice', 'choices'];

    public function __construct(private PropertyTypeRegistry $propertyTypes)
    {
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{schema_id: string, owner_package: string, fields: list<array<string, mixed>>, partial_locales?: true, relations?: list<array<string, string>>}
     */
    public function normalize(array $definition, bool $validateConstraints = true): array
    {
        $definitionKeys = array_keys($definition);
        sort($definitionKeys);
        // `partial_locales` is optional and kept only when true, so every definition
        // stored before it existed normalizes, and hashes, exactly as before.
        if (array_values(array_diff($definitionKeys, ['partial_locales', 'relations'])) !== ['fields', 'owner_package', 'schema_id']
            || (array_key_exists('partial_locales', $definition) && !is_bool($definition['partial_locales']))) {
            throw new StorageRejected('storage_schema_definition_unknown_key');
        }
        $partialLocales = ($definition['partial_locales'] ?? false) === true;
        // The relations a schema takes part in, with their delete policy, are part of
        // the versioned schema. Kept only when declared, so older definitions hash as
        // before.
        $relations = self::normalizeRelations($definition['relations'] ?? []);
        $schemaId = is_string($definition['schema_id'] ?? null) ? trim($definition['schema_id']) : '';
        $ownerPackage = is_string($definition['owner_package'] ?? null) ? trim($definition['owner_package']) : '';
        $fields = $definition['fields'] ?? null;
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $schemaId) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]*\/[a-z][a-z0-9_.-]*$/', $ownerPackage) !== 1
            || !is_array($fields)
            || !array_is_list($fields)
            || $fields === []) {
            throw new StorageRejected('storage_schema_definition_invalid');
        }

        $normalizedFields = [];
        $seen = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new StorageRejected('storage_schema_field_invalid');
            }
            $fieldKeys = array_keys($field);
            $unknownFieldKeys = array_diff($fieldKeys, ['key', 'type', 'type_version', 'required', 'visibility', 'constraints', 'localized']);
            if ($unknownFieldKeys !== []
                || !array_key_exists('key', $field)
                || !array_key_exists('type', $field)
                || !array_key_exists('visibility', $field)) {
                throw new StorageRejected('storage_schema_field_unknown_key');
            }
            $key = is_string($field['key'] ?? null) ? trim($field['key']) : '';
            $type = is_string($field['type'] ?? null) ? trim($field['type']) : '';
            $typeVersion = $field['type_version'] ?? 1;
            $required = $field['required'] ?? false;
            $visibility = $field['visibility'] ?? null;
            $constraints = $field['constraints'] ?? [];
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1
                || isset($seen[$key])
                || !is_int($typeVersion)
                || $typeVersion < 1
                || !is_bool($required)
                || !is_string($visibility)
                || !in_array($visibility, ['public', 'protected', 'admin'], true)
                || !is_array($constraints)
                || ($constraints !== [] && array_is_list($constraints))
                || (array_key_exists('localized', $field) && !is_bool($field['localized']))
                || $this->propertyTypes->resolve($type, $typeVersion) === null) {
                throw new StorageRejected('storage_schema_field_invalid');
            }
            foreach ($constraints as $constraintKey => $constraintValue) {
                if (!is_string($constraintKey)) {
                    throw new StorageRejected('storage_schema_constraint_invalid');
                }
                if ($constraintKey === 'options' && in_array($type, self::OPTION_LIST_TYPES, true)) {
                    if (!self::isScalarObjectList($constraintValue)) {
                        throw new StorageRejected('storage_schema_constraint_invalid');
                    }
                    continue;
                }
                if (!is_scalar($constraintValue)) {
                    throw new StorageRejected('storage_schema_constraint_invalid');
                }
            }
            if ($validateConstraints) {
                $this->assertConstraintsValid($type, $typeVersion, $constraints);
            }
            if ($type === 'datetime') {
                $constraints = self::canonicalDateTimeBounds($constraints);
            }
            $seen[$key] = true;
            $normalizedFields[] = [
                'key' => $key,
                'type' => $type,
                'type_version' => $typeVersion,
                'required' => $required,
                'visibility' => $visibility,
                'constraints' => $this->canonicalize($constraints),
            // A field is localized only when it says so; the key is kept only when
            // true, so a field that says nothing hashes as it always did.
            ] + (($field['localized'] ?? false) === true ? ['localized' => true] : []);
        }

        return ['schema_id' => $schemaId, 'owner_package' => $ownerPackage, 'fields' => $normalizedFields]
            + ($partialLocales ? ['partial_locales' => true] : [])
            + ($relations !== [] ? ['relations' => $relations] : []);
    }

    /**
     * @return list<array{relation_key: string, kind: string, delete_policy: string, target_schema_id?: string, target_role_code?: string}>
     */
    private static function normalizeRelations(mixed $relations): array
    {
        if (!is_array($relations) || !array_is_list($relations)) {
            throw new StorageRejected('storage_schema_relation_invalid');
        }
        $normalized = [];
        $seen = [];
        foreach ($relations as $relation) {
            if (!is_array($relation)
                || array_diff(array_keys($relation), ['relation_key', 'kind', 'delete_policy', 'target_schema_id', 'target_role_code']) !== []
                || !is_string($relation['relation_key'] ?? null)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $relation['relation_key']) !== 1
                || isset($seen[$relation['relation_key']])
                || !in_array($relation['kind'] ?? null, ['reference', 'tree_parent'], true)
                || !in_array($relation['delete_policy'] ?? null, ['restrict', 'cascade', 'detach'], true)) {
                throw new StorageRejected('storage_schema_relation_invalid');
            }
            $entry = ['relation_key' => $relation['relation_key'], 'kind' => $relation['kind'], 'delete_policy' => $relation['delete_policy']];
            foreach (['target_schema_id', 'target_role_code'] as $target) {
                if (array_key_exists($target, $relation)) {
                    if (!is_string($relation[$target]) || preg_match('/^[a-z][a-z0-9_.:-]{0,119}$/', $relation[$target]) !== 1) {
                        throw new StorageRejected('storage_schema_relation_invalid');
                    }
                    $entry[$target] = $relation[$target];
                }
            }
            $seen[$relation['relation_key']] = true;
            $normalized[] = $entry;
        }
        usort($normalized, static fn (array $left, array $right): int => $left['relation_key'] <=> $right['relation_key']);

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    public function normalizeValues(StorageSchemaVersion $schema, array $values): array
    {
        if ($values !== [] && array_is_list($values)) {
            throw new StorageRejected('storage_record_values_invalid');
        }
        $fields = [];
        foreach ($schema->fields as $field) {
            $constraints = is_array($field['constraints'] ?? null) ? $field['constraints'] : [];
            $this->assertConstraintsValid(
                (string) ($field['type'] ?? ''),
                (int) ($field['type_version'] ?? 0),
                $constraints,
            );
            $fields[(string) $field['key']] = $field;
        }
        foreach ($values as $key => $_value) {
            if (!is_string($key) || !isset($fields[$key])) {
                throw new StorageRejected('storage_record_unknown_field');
            }
        }

        $normalized = [];
        foreach ($fields as $key => $field) {
            if (!array_key_exists($key, $values)) {
                if (($field['required'] ?? false) === true) {
                    throw new StorageRejected('storage_record_required_field_missing');
                }
                continue;
            }
            $result = $this->propertyTypes->normalizeAndValidate(
                (string) $field['type'],
                (int) $field['type_version'],
                $values[$key],
                is_array($field['constraints'] ?? null) ? $field['constraints'] : [],
            );
            if (!$result->canBePersistedByOwner()) {
                throw new StorageRejected('storage_record_field_invalid');
            }
            if ($result->normalizedValue === [] && ($field['required'] ?? false) === true) {
                throw new StorageRejected('storage_record_required_field_missing');
            }
            $normalized[$key] = $result->normalizedValue;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $constraints */
    private function assertConstraintsValid(string $type, int $version, array $constraints): void
    {
        if (!$this->propertyTypes instanceof PropertyConstraintValidator
            || !$this->propertyTypes->validateConstraints($type, $version, $constraints)->canBePersistedByOwner()) {
            throw new StorageRejected('storage_schema_constraint_invalid');
        }
    }

    /**
     * Stores datetime `min`/`max` bounds in the canonical `YYYY-MM-DDTHH:MM:SS` form so the
     * schema definition and its digest do not depend on the accepted input format.
     *
     * @param array<string, mixed> $constraints
     * @return array<string, mixed>
     */
    private static function canonicalDateTimeBounds(array $constraints): array
    {
        foreach (['min', 'max'] as $bound) {
            $value = $constraints[$bound] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value) === 1) {
                $constraints[$bound] = $value . ':00';
            }
        }

        return $constraints;
    }

    private static function isScalarObjectList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_array($item) || $item === [] || array_is_list($item)) {
                return false;
            }
            foreach ($item as $itemKey => $itemValue) {
                if (!is_string($itemKey) || !is_scalar($itemValue)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /** @return array<string, mixed> */
    public function decodeObject(string $json, string $reason): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new StorageRejected($reason);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new StorageRejected($reason);
        }

        return $decoded;
    }

    public function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
