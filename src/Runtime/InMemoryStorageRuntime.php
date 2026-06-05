<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Larena\Storage\Contracts\StorageMutation;
use Larena\Storage\Contracts\StorageQuery;
use Larena\Storage\Contracts\StorageRecord;
use Larena\Storage\Contracts\StorageRuntime;
use Larena\Storage\Contracts\StorageSchema;
use Larena\Storage\Contracts\StorageValidationReport;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\MutationType;
use Larena\Storage\Enums\StorageDecisionStatus;

final class InMemoryStorageRuntime implements StorageRuntime
{
    /** @var array<string, StorageSchema> */
    private array $schemas = [];

    /** @var array<string, array<string, ArrayStorageRecord>> */
    private array $records = [];

    public function registerSchema(StorageSchema $schema): StorageValidationReport
    {
        $errors = [];

        if (trim($schema->id()) === '') {
            $errors[] = 'schema_id_missing';
        }

        if (trim($schema->version()) === '') {
            $errors[] = 'schema_version_missing';
        }

        if (trim($schema->accessPolicyRef()) === '') {
            $errors[] = 'access_policy_ref_missing';
        }

        if (trim($schema->persistenceProfile()) === '') {
            $errors[] = 'persistence_profile_missing';
        }

        foreach ($schema->fields() as $index => $field) {
            if (!is_string($field['name'] ?? null) || trim((string) $field['name']) === '') {
                $errors[] = "field_{$index}_name_missing";
            }

            if (isset($field['visibility']) && !is_string($field['visibility'])) {
                $errors[] = "field_{$index}_visibility_invalid";
            }
        }

        if ($errors !== []) {
            return StorageValidationResult::invalid($errors, [
                'schema_id' => $schema->id(),
                'field_count' => count($schema->fields()),
            ]);
        }

        $this->schemas[$schema->id()] = $schema;
        $this->records[$schema->id()] ??= [];

        return StorageValidationResult::valid([
            'schema_id' => $schema->id(),
            'schema_version' => $schema->version(),
            'field_count' => count($schema->fields()),
        ]);
    }

    /**
     * @return list<StorageSchema>
     */
    public function schemas(): array
    {
        return array_values($this->schemas);
    }

    public function decideQuery(StorageQuery $query): StorageDecisionStatus
    {
        if (!$this->hasSchema($query->schemaId())) {
            return StorageDecisionStatus::MissingSchema;
        }

        if (trim($query->accessScopeRef()) === '') {
            return StorageDecisionStatus::MissingAccessScope;
        }

        return StorageDecisionStatus::Allowed;
    }

    /**
     * @return list<StorageRecord>
     */
    public function records(StorageQuery $query): array
    {
        if (!$this->decideQuery($query)->permitsDataAccess()) {
            return [];
        }

        $records = array_values($this->records[$query->schemaId()] ?? []);

        return array_values(array_filter(
            $records,
            static fn (ArrayStorageRecord $record): bool => self::matchesFilters($record, $query->filters())
        ));
    }

    public function validateMutation(StorageMutation $mutation): StorageValidationReport
    {
        $errors = [];

        if (!$this->hasSchema($mutation->schemaId())) {
            $errors[] = 'schema_missing';
        }

        if (trim($mutation->accessScopeRef()) === '') {
            $errors[] = 'access_scope_missing';
        }

        if (in_array($mutation->type(), [MutationType::Create, MutationType::Update], true) && $mutation->payload() === []) {
            $errors[] = 'payload_missing';
        }

        if ($mutation->type() !== MutationType::Create && trim((string) $mutation->recordId()) === '') {
            $errors[] = 'record_id_missing';
        }

        $schema = $this->schemas[$mutation->schemaId()] ?? null;
        if ($schema !== null && in_array($mutation->type(), [MutationType::Create, MutationType::Update], true)) {
            foreach ($schema->fields() as $field) {
                $name = (string) ($field['name'] ?? '');
                $required = ($field['required'] ?? false) === true;
                if ($required && !array_key_exists($name, $mutation->payload())) {
                    $errors[] = "required_field_missing:{$name}";
                }
            }
        }

        if ($errors !== []) {
            return StorageValidationResult::invalid($errors, [
                'schema_id' => $mutation->schemaId(),
                'mutation_type' => $mutation->type()->value,
            ]);
        }

        return StorageValidationResult::valid([
            'schema_id' => $mutation->schemaId(),
            'mutation_type' => $mutation->type()->value,
        ]);
    }

    public function decideMutation(StorageMutation $mutation, StorageValidationReport $validation): StorageDecisionStatus
    {
        if (!$this->hasSchema($mutation->schemaId())) {
            return StorageDecisionStatus::MissingSchema;
        }

        if (trim($mutation->accessScopeRef()) === '') {
            return StorageDecisionStatus::MissingAccessScope;
        }

        if (!$validation->isValid() || $validation->blocksMutation()) {
            return StorageDecisionStatus::InvalidPayload;
        }

        return StorageDecisionStatus::Allowed;
    }

    public function mutate(StorageMutation $mutation): StorageDecisionStatus
    {
        $validation = $this->validateMutation($mutation);
        $decision = $this->decideMutation($mutation, $validation);

        if (!$decision->permitsDataAccess()) {
            return $decision;
        }

        $schema = $this->schemas[$mutation->schemaId()];

        if ($mutation->type() === MutationType::Create) {
            $recordId = $mutation->recordId() ?: sprintf('record-%d', count($this->records[$schema->id()] ?? []) + 1);
            $this->records[$schema->id()][$recordId] = $this->recordFromMutation($schema, $recordId, $mutation);

            return StorageDecisionStatus::Allowed;
        }

        $recordId = (string) $mutation->recordId();
        if (!isset($this->records[$schema->id()][$recordId])) {
            return StorageDecisionStatus::Denied;
        }

        if ($mutation->type() === MutationType::Delete) {
            unset($this->records[$schema->id()][$recordId]);

            return StorageDecisionStatus::Allowed;
        }

        if ($mutation->type() === MutationType::Update) {
            $this->records[$schema->id()][$recordId] = $this->recordFromMutation($schema, $recordId, $mutation);

            return StorageDecisionStatus::Allowed;
        }

        return StorageDecisionStatus::CapabilityLimited;
    }

    private function hasSchema(string $schemaId): bool
    {
        return trim($schemaId) !== '' && isset($this->schemas[$schemaId]);
    }

    private function recordFromMutation(StorageSchema $schema, string $recordId, StorageMutation $mutation): ArrayStorageRecord
    {
        return new ArrayStorageRecord(
            $recordId,
            $schema->id(),
            $schema->version(),
            sprintf('storage:%s:%s', $schema->id(), $recordId),
            $this->safeProjection($schema, $mutation->payload())
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function safeProjection(StorageSchema $schema, array $payload): array
    {
        $projection = [];

        foreach ($schema->fields() as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || !array_key_exists($name, $payload)) {
                continue;
            }

            $projection[$name] = $this->fieldRequiresRedaction($field)
                ? '[redacted]'
                : $payload[$name];
        }

        return $projection;
    }

    /**
     * @param array<string, scalar|null> $field
     */
    private function fieldRequiresRedaction(array $field): bool
    {
        $visibility = (string) ($field['visibility'] ?? FieldVisibility::Public->value);
        $fieldVisibility = FieldVisibility::tryFrom($visibility);

        return $fieldVisibility?->requiresProtectedProjection() ?? false;
    }

    /**
     * @param array<string, scalar|null> $filters
     */
    private static function matchesFilters(ArrayStorageRecord $record, array $filters): bool
    {
        $projection = $record->projection();

        foreach ($filters as $key => $value) {
            if (($projection[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
