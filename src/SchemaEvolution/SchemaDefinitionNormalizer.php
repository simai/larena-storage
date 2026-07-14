<?php

declare(strict_types=1);

namespace Larena\Storage\SchemaEvolution;

use Larena\Property\Contracts\PropertyTypeRegistry;
use Larena\Storage\Contracts\StorageSchemaVersion;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

final readonly class SchemaDefinitionNormalizer
{
    public function __construct(private PropertyTypeRegistry $propertyTypes)
    {
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{schema_id: string, owner_package: string, fields: list<array<string, mixed>>}
     */
    public function normalize(array $definition): array
    {
        $definitionKeys = array_keys($definition);
        sort($definitionKeys);
        if ($definitionKeys !== ['fields', 'owner_package', 'schema_id']) {
            throw new StorageRejected('storage_schema_definition_unknown_key');
        }
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
            $unknownFieldKeys = array_diff($fieldKeys, ['key', 'type', 'type_version', 'required', 'visibility', 'constraints']);
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
                || !in_array($visibility, ['public', 'admin'], true)
                || !is_array($constraints)
                || ($constraints !== [] && array_is_list($constraints))
                || $this->propertyTypes->resolve($type, $typeVersion) === null) {
                throw new StorageRejected('storage_schema_field_invalid');
            }
            foreach ($constraints as $constraintKey => $constraintValue) {
                if (!is_string($constraintKey) || !is_scalar($constraintValue)) {
                    throw new StorageRejected('storage_schema_constraint_invalid');
                }
            }
            $seen[$key] = true;
            $normalizedFields[] = [
                'key' => $key,
                'type' => $type,
                'type_version' => $typeVersion,
                'required' => $required,
                'visibility' => $visibility,
                'constraints' => $this->canonicalize($constraints),
            ];
        }

        return ['schema_id' => $schemaId, 'owner_package' => $ownerPackage, 'fields' => $normalizedFields];
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
            $normalized[$key] = $result->normalizedValue;
        }

        return $normalized;
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
