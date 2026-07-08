<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;
use Larena\Storage\Contracts\StorageMutation;
use Larena\Storage\Contracts\StoragePersistenceAdapter;
use Larena\Storage\Contracts\StoragePersistenceProfile;
use Larena\Storage\Contracts\StorageQuery;
use Larena\Storage\Contracts\StorageRecord;
use Larena\Storage\Contracts\StorageSchema;
use Larena\Storage\Contracts\StorageValidationReport;
use Larena\Storage\Enums\FieldVisibility;
use Larena\Storage\Enums\MutationType;
use Larena\Storage\Enums\StorageDecisionStatus;

final class PdoLocalDevStorageAdapter implements StoragePersistenceAdapter
{
    public const PROFILE_ID = 'local_dev_disposable_sqlite_pdo';

    /** @var array<string, StorageSchema> */
    private array $schemas = [];

    private ?string $nextMutationFailure = null;

    public function __construct(private readonly PDO $pdo, private readonly string $environment = 'testing')
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function inMemorySqlite(string $environment = 'testing'): self
    {
        return new self(new PDO('sqlite::memory:'), $environment);
    }

    public static function localDevProfile(): StoragePersistenceProfile
    {
        return new readonly class implements StoragePersistenceProfile {
            public function id(): string
            {
                return PdoLocalDevStorageAdapter::PROFILE_ID;
            }

            public function driver(): string
            {
                return 'sqlite';
            }

            public function isBaseline(): bool
            {
                return false;
            }

            public function options(): array
            {
                return [
                    'disposable' => true,
                    'database' => ':memory:',
                    'filesystem_storage_write' => false,
                    'migration_required' => false,
                    'production_safe' => false,
                ];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function preflight(): array
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isDisposableSqlite = $driver === 'sqlite'
            && in_array($this->environment, ['local', 'testing'], true);

        return [
            'status' => $isDisposableSqlite ? 'passed' : 'blocked',
            'driver' => $driver,
            'database' => ':memory:',
            'environment' => $this->environment,
            'local_or_testing_environment' => in_array($this->environment, ['local', 'testing'], true),
            'disposable_database' => $isDisposableSqlite,
            'filesystem_storage_write_attempted' => false,
            'migration_required' => false,
            'production_live_shared_connection' => false,
            'write_allowed_after_preflight' => $isDisposableSqlite,
        ];
    }

    public function supports(StoragePersistenceProfile $profile): bool
    {
        return $profile->id() === self::PROFILE_ID
            && $profile->driver() === 'sqlite'
            && ($profile->options()['disposable'] ?? false) === true;
    }

    public function registerSchema(StorageSchema $schema): StorageValidationReport
    {
        $validation = $this->validateSchema($schema);
        if (!$validation->isValid()) {
            return $validation;
        }

        $this->assertPreflightAllowsWrite();
        $this->schemas[$schema->id()] = $schema;
        $this->ensureTable();

        return StorageValidationResult::valid([
            'schema_id' => $schema->id(),
            'schema_version' => $schema->version(),
            'field_count' => count($schema->fields()),
            'persistence_profile' => $schema->persistenceProfile(),
            'disposable_database' => true,
        ]);
    }

    /**
     * @return list<StorageRecord>
     */
    public function records(StorageQuery $query): array
    {
        if (!$this->decideQuery($query)->permitsDataAccess()) {
            return [];
        }

        $this->ensureTable();
        $statement = $this->pdo->prepare(
            'select record_id, schema_id, schema_version, correlation_id, projection_json from larena_storage_records where schema_id = :schema_id order by record_id asc'
        );
        $statement->execute(['schema_id' => $query->schemaId()]);

        $records = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projection = $this->decodeProjection((string) ($row['projection_json'] ?? '{}'));
            $record = new ArrayStorageRecord(
                (string) ($row['record_id'] ?? ''),
                (string) ($row['schema_id'] ?? ''),
                (string) ($row['schema_version'] ?? ''),
                (string) ($row['correlation_id'] ?? ''),
                $projection,
            );

            if ($this->matchesFilters($record, $query->filters())) {
                $records[] = $record;
            }
        }

        return $records;
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
        if ($this->nextMutationFailure !== null) {
            $message = $this->nextMutationFailure;
            $this->nextMutationFailure = null;

            throw new RuntimeException($message);
        }

        $validation = $this->validateMutation($mutation);
        $decision = $this->decideMutation($mutation, $validation);
        if (!$decision->permitsDataAccess()) {
            return $decision;
        }

        $this->assertPreflightAllowsWrite();
        $this->ensureTable();

        return match ($mutation->type()) {
            MutationType::Create => $this->createRecord($mutation),
            MutationType::Update => $this->updateRecord($mutation),
            MutationType::Delete => $this->deleteRecord($mutation),
            default => StorageDecisionStatus::CapabilityLimited,
        };
    }

    public function beginTransaction(): void
    {
        $this->assertPreflightAllowsWrite();
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function failNextMutation(string $message = 'local_dev_storage_adapter_failure_simulated'): void
    {
        $this->nextMutationFailure = $message;
    }

    public function recordCount(string $schemaId): int
    {
        $this->ensureTable();
        $statement = $this->pdo->prepare('select count(*) from larena_storage_records where schema_id = :schema_id');
        $statement->execute(['schema_id' => $schemaId]);

        return (int) $statement->fetchColumn();
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

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'create table if not exists larena_storage_records (
                record_id text not null,
                schema_id text not null,
                schema_version text not null,
                correlation_id text not null,
                projection_json text not null,
                created_at text not null,
                updated_at text not null,
                primary key (schema_id, record_id)
            )'
        );
    }

    private function createRecord(StorageMutation $mutation): StorageDecisionStatus
    {
        $schema = $this->schemas[$mutation->schemaId()] ?? null;
        if ($schema === null) {
            return StorageDecisionStatus::MissingSchema;
        }

        $recordId = $mutation->recordId() ?: sprintf('record-%d', $this->recordCount($schema->id()) + 1);
        $now = (new DateTimeImmutable())->format(DATE_ATOM);
        $statement = $this->pdo->prepare(
            'insert into larena_storage_records (record_id, schema_id, schema_version, correlation_id, projection_json, created_at, updated_at)
             values (:record_id, :schema_id, :schema_version, :correlation_id, :projection_json, :created_at, :updated_at)'
        );
        $statement->execute([
            'record_id' => $recordId,
            'schema_id' => $schema->id(),
            'schema_version' => $schema->version(),
            'correlation_id' => sprintf('storage:%s:%s', $schema->id(), $recordId),
            'projection_json' => $this->projectionJson($schema, $mutation->payload()),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return StorageDecisionStatus::Allowed;
    }

    private function updateRecord(StorageMutation $mutation): StorageDecisionStatus
    {
        $schema = $this->schemas[$mutation->schemaId()] ?? null;
        $recordId = (string) $mutation->recordId();
        if ($schema === null) {
            return StorageDecisionStatus::MissingSchema;
        }

        if (!$this->recordExists($schema->id(), $recordId)) {
            return StorageDecisionStatus::Denied;
        }

        $statement = $this->pdo->prepare(
            'update larena_storage_records set projection_json = :projection_json, updated_at = :updated_at where schema_id = :schema_id and record_id = :record_id'
        );
        $statement->execute([
            'projection_json' => $this->projectionJson($schema, $mutation->payload()),
            'updated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'schema_id' => $schema->id(),
            'record_id' => $recordId,
        ]);

        return StorageDecisionStatus::Allowed;
    }

    private function deleteRecord(StorageMutation $mutation): StorageDecisionStatus
    {
        $schemaId = $mutation->schemaId();
        $recordId = (string) $mutation->recordId();
        if (!$this->hasSchema($schemaId)) {
            return StorageDecisionStatus::MissingSchema;
        }

        if (!$this->recordExists($schemaId, $recordId)) {
            return StorageDecisionStatus::Denied;
        }

        $statement = $this->pdo->prepare('delete from larena_storage_records where schema_id = :schema_id and record_id = :record_id');
        $statement->execute([
            'schema_id' => $schemaId,
            'record_id' => $recordId,
        ]);

        return StorageDecisionStatus::Allowed;
    }

    private function recordExists(string $schemaId, string $recordId): bool
    {
        $statement = $this->pdo->prepare('select 1 from larena_storage_records where schema_id = :schema_id and record_id = :record_id limit 1');
        $statement->execute([
            'schema_id' => $schemaId,
            'record_id' => $recordId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    private function hasSchema(string $schemaId): bool
    {
        return trim($schemaId) !== '' && isset($this->schemas[$schemaId]);
    }

    private function validateSchema(StorageSchema $schema): StorageValidationReport
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
        }

        if ($errors !== []) {
            return StorageValidationResult::invalid($errors, [
                'schema_id' => $schema->id(),
                'field_count' => count($schema->fields()),
            ]);
        }

        return StorageValidationResult::valid([
            'schema_id' => $schema->id(),
            'field_count' => count($schema->fields()),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function projectionJson(StorageSchema $schema, array $payload): string
    {
        try {
            return json_encode($this->safeProjection($schema, $payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('storage_projection_json_encode_failed', 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeProjection(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
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
    private function matchesFilters(ArrayStorageRecord $record, array $filters): bool
    {
        $projection = $record->projection();

        foreach ($filters as $key => $value) {
            if (($projection[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function assertPreflightAllowsWrite(): void
    {
        $preflight = $this->preflight();
        if (($preflight['write_allowed_after_preflight'] ?? false) !== true) {
            throw new RuntimeException('local_dev_disposable_database_preflight_failed');
        }
    }
}
