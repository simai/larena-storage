<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Storage\Runtime\ScopedStorageWorkbenchSchemaIdentity;
use Larena\Storage\SchemaEvolution\SchemaDefinitionNormalizer;

return new class extends Migration
{
    private const HEADS = 'larena_storage_workbench_structures';
    private const VERSIONS = 'larena_storage_workbench_structure_versions';
    private const NEXT_HEADS = 'larena_storage_workbench_structures_scoped_v1';
    private const NEXT_VERSIONS = 'larena_storage_workbench_structure_versions_scoped_v1';
    private const PREVIOUS_HEADS = 'larena_storage_workbench_structures_unscoped_v1';
    private const PREVIOUS_VERSIONS = 'larena_storage_workbench_structure_versions_unscoped_v1';

    public function up(): void
    {
        if (!Schema::hasTable(self::HEADS) || !Schema::hasTable(self::VERSIONS)) {
            throw new RuntimeException('storage_workbench_identity_tables_missing');
        }
        $headsScoped = Schema::hasColumn(self::HEADS, 'storage_schema_id');
        $versionsScoped = Schema::hasColumn(self::VERSIONS, 'storage_schema_id');
        if ($headsScoped && $versionsScoped) {
            return;
        }
        if ($headsScoped || $versionsScoped
            || Schema::hasTable(self::NEXT_HEADS)
            || Schema::hasTable(self::NEXT_VERSIONS)
            || Schema::hasTable(self::PREVIOUS_HEADS)
            || Schema::hasTable(self::PREVIOUS_VERSIONS)) {
            throw new RuntimeException('storage_workbench_identity_partial_state');
        }

        $database = Schema::getConnection();
        // Build and validate the complete migration plan before the first
        // durable mutation. This is mandatory for MariaDB/MySQL because its
        // DDL statements implicitly commit.
        $plan = $this->buildMigrationPlan();
        $upgrade = function () use ($plan): void {
            $this->applyMigrationPlan($plan);
        };

        // MySQL and MariaDB implicitly commit DDL statements. Wrapping this
        // sequence in a PDO transaction therefore makes Laravel attempt to
        // commit a transaction the server has already closed. SQLite supports
        // transactional DDL and keeps the stronger atomic boundary.
        if ($database->getDriverName() === 'mysql') {
            try {
                $upgrade();
            } catch (Throwable $failure) {
                try {
                    $this->restoreMySqlPreMigrationState($plan);
                } catch (Throwable) {
                    throw new RuntimeException('storage_workbench_identity_compensation_failed');
                }

                throw $failure;
            }

            return;
        }

        $database->transaction($upgrade);
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::HEADS) || !Schema::hasTable(self::VERSIONS)) {
            return;
        }
        if (!Schema::hasColumn(self::HEADS, 'storage_schema_id') || !Schema::hasColumn(self::VERSIONS, 'storage_schema_id')) {
            return;
        }
        $database = Schema::getConnection();
        if ($database->table(self::HEADS)->exists() || $database->table(self::VERSIONS)->exists()) {
            throw new RuntimeException('storage_workbench_scoped_identity_rollback_would_lose_data');
        }

        $database->transaction(function (): void {
            Schema::drop(self::VERSIONS);
            Schema::drop(self::HEADS);
            (require __DIR__ . '/2026_08_09_000001_create_larena_storage_workbench_tables.php')->up();
        });
    }

    /** @return array<string, mixed> */
    private function buildMigrationPlan(): array
    {
        $database = Schema::getConnection();
        $heads = array_map(
            static fn (object $row): array => (array) $row,
            $database->table(self::HEADS)->orderBy('structure_id')->get()->all(),
        );
        $versions = array_map(
            static fn (object $row): array => (array) $row,
            $database->table(self::VERSIONS)->orderBy('structure_id')->orderBy('version')->get()->all(),
        );
        $identities = [];
        $scopedHeads = [];
        foreach ($heads as $head) {
            $structureId = (string) ($head['structure_id'] ?? '');
            $scopeRef = (string) ($head['scope_ref'] ?? '');
            $this->assertLegacyIdentity($structureId, $scopeRef);
            $storageSchemaId = ScopedStorageWorkbenchSchemaIdentity::derive($scopeRef, $structureId);
            if (isset($identities[$storageSchemaId])) {
                throw new RuntimeException('storage_workbench_identity_collision');
            }
            $identity = $this->buildLegacySchemaPlan($structureId, $scopeRef, $storageSchemaId);
            $identity['workbench_versions'] = [];
            $identities[$storageSchemaId] = $identity;
            $scopedHeads[] = [
                'scope_ref' => $scopeRef,
                'structure_id' => $structureId,
                'storage_schema_id' => $storageSchemaId,
                'current_version' => (int) ($head['current_version'] ?? 0),
                'current_schema_version' => (int) ($head['current_schema_version'] ?? 0),
                'current_hash' => (string) ($head['current_hash'] ?? ''),
                'created_at' => (string) ($head['created_at'] ?? ''),
                'updated_at' => (string) ($head['updated_at'] ?? ''),
            ];
        }

        $scopedVersions = [];
        foreach ($versions as $version) {
            $structureId = (string) ($version['structure_id'] ?? '');
            $scopeRef = (string) ($version['scope_ref'] ?? '');
            $storageSchemaId = ScopedStorageWorkbenchSchemaIdentity::derive($scopeRef, $structureId);
            if (!isset($identities[$storageSchemaId])) {
                throw new RuntimeException('storage_workbench_identity_history_orphaned');
            }
            $fields = $this->decodeList((string) ($version['fields_json'] ?? ''), 'storage_workbench_identity_history_corrupt');
            $versionNumber = (int) ($version['version'] ?? 0);
            $schemaVersion = (int) ($version['schema_version'] ?? 0);
            $descriptorHash = $this->descriptorHash(
                $structureId,
                $scopeRef,
                $versionNumber,
                $schemaVersion,
                (string) ($version['label'] ?? ''),
                $fields,
            );
            if ($versionNumber < 1
                || !isset($identities[$storageSchemaId]['schema_versions'][$schemaVersion])
                || !$this->validHash((string) ($version['descriptor_hash'] ?? ''))
                || !hash_equals((string) $version['descriptor_hash'], $descriptorHash)) {
                throw new RuntimeException('storage_workbench_identity_history_corrupt');
            }
            $scoped = [
                'id' => (int) ($version['id'] ?? 0),
                'structure_id' => $structureId,
                'version' => $versionNumber,
                'scope_ref' => $scopeRef,
                'storage_schema_id' => $storageSchemaId,
                'label' => (string) ($version['label'] ?? ''),
                'fields_json' => $this->canonicalJson($fields),
                'schema_version' => $schemaVersion,
                'descriptor_hash' => $descriptorHash,
                'created_by' => (string) ($version['created_by'] ?? ''),
                'correlation_id' => $version['correlation_id'] ?? null,
                'created_at' => (string) ($version['created_at'] ?? ''),
            ];
            $identities[$storageSchemaId]['workbench_versions'][$versionNumber] = $scoped;
            $scopedVersions[] = $scoped;
        }

        foreach ($scopedHeads as $head) {
            $storageSchemaId = (string) $head['storage_schema_id'];
            $history = $identities[$storageSchemaId]['workbench_versions'];
            $currentVersion = (int) $head['current_version'];
            if (array_keys($history) !== range(1, $currentVersion)
                || !isset($history[$currentVersion])
                || (int) $history[$currentVersion]['schema_version'] !== (int) $head['current_schema_version']
                || !hash_equals((string) $history[$currentVersion]['descriptor_hash'], (string) $head['current_hash'])) {
                throw new RuntimeException('storage_workbench_identity_head_corrupt');
            }
        }

        return [
            'legacy_heads' => $heads,
            'legacy_versions' => $versions,
            'scoped_heads' => $scopedHeads,
            'scoped_versions' => $scopedVersions,
            'identities' => array_values($identities),
            'schema_versions_auto_increment' => $this->mysqlAutoIncrement('larena_storage_schema_versions'),
            'workbench_versions_auto_increment' => $this->mysqlAutoIncrement(self::VERSIONS),
        ];
    }

    /** @return array<string, mixed> */
    private function buildLegacySchemaPlan(string $legacySchemaId, string $scopeRef, string $storageSchemaId): array
    {
        $database = Schema::getConnection();
        if ($database->table('larena_storage_schemas')->where('schema_id', $storageSchemaId)->exists()
            || $database->table('larena_storage_schema_versions')->where('schema_id', $storageSchemaId)->exists()
            || $database->table('larena_storage_records')->where('schema_id', $storageSchemaId)->exists()
            || $database->table('larena_storage_record_versions')->where('schema_id', $storageSchemaId)->exists()) {
            throw new RuntimeException('storage_workbench_identity_target_occupied');
        }
        $headObject = $database->table('larena_storage_schemas')->where('schema_id', $legacySchemaId)->first();
        $versionObjects = $database->table('larena_storage_schema_versions')
            ->where('schema_id', $legacySchemaId)
            ->orderBy('version')
            ->get()
            ->all();
        if (!is_object($headObject) || $versionObjects === []) {
            throw new RuntimeException('storage_workbench_identity_schema_missing');
        }
        $head = (array) $headObject;
        $normalizer = new SchemaDefinitionNormalizer(PropertyTypeRegistry::builtIns());
        $schemaVersions = [];
        $targetVersions = [];
        foreach ($versionObjects as $versionObject) {
            $version = (array) $versionObject;
            $versionNumber = (int) ($version['version'] ?? 0);
            try {
                $definition = json_decode((string) ($version['definition'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($definition) || array_is_list($definition)) {
                    throw new RuntimeException('invalid');
                }
                $normalized = $normalizer->normalize($definition);
            } catch (Throwable) {
                throw new RuntimeException('storage_workbench_identity_schema_corrupt');
            }
            $definitionJson = $this->canonicalJson($normalized);
            $definitionHash = hash('sha256', $definitionJson);
            if ($versionNumber < 1
                || isset($schemaVersions[$versionNumber])
                || ($normalized['schema_id'] ?? null) !== $legacySchemaId
                || ($normalized['owner_package'] ?? null) !== 'larena/storage'
                || (string) ($version['owner_package'] ?? '') !== 'larena/storage'
                || !$this->validHash((string) ($version['definition_hash'] ?? ''))
                || !hash_equals((string) $version['definition_hash'], $definitionHash)) {
                throw new RuntimeException('storage_workbench_identity_schema_corrupt');
            }
            $schemaVersions[$versionNumber] = $definitionHash;
            $normalized['schema_id'] = $storageSchemaId;
            $targetJson = $this->canonicalJson($normalized);
            $targetVersions[] = [
                'schema_id' => $storageSchemaId,
                'version' => $versionNumber,
                'definition' => $targetJson,
                'definition_hash' => hash('sha256', $targetJson),
                'owner_package' => 'larena/storage',
                'created_by' => (string) ($version['created_by'] ?? ''),
                'correlation_id' => $version['correlation_id'] ?? null,
                'created_at' => (string) ($version['created_at'] ?? ''),
            ];
        }
        $currentVersion = (int) ($head['current_version'] ?? 0);
        if (array_keys($schemaVersions) !== range(1, $currentVersion)
            || !isset($schemaVersions[$currentVersion])
            || !$this->validHash((string) ($head['current_hash'] ?? ''))
            || !hash_equals($schemaVersions[$currentVersion], (string) $head['current_hash'])) {
            throw new RuntimeException('storage_workbench_identity_schema_head_corrupt');
        }

        $recordHeads = [];
        foreach ($database->table('larena_storage_records')->where('schema_id', $legacySchemaId)->orderBy('record_id')->get()->all() as $recordHead) {
            $recordHeads[(string) $recordHead->record_id] = (array) $recordHead;
        }
        $recordHistory = [];
        foreach ($database->table('larena_storage_record_versions')
            ->where('schema_id', $legacySchemaId)
            ->orderBy('record_id')
            ->orderBy('revision')
            ->get()
            ->all() as $recordObject) {
            $record = (array) $recordObject;
            $recordId = (string) ($record['record_id'] ?? '');
            $revision = (int) ($record['revision'] ?? 0);
            $values = $this->decodeObject((string) ($record['values_json'] ?? ''), 'storage_workbench_identity_record_corrupt');
            $contentHash = hash('sha256', $this->canonicalJson($values));
            if (!isset($recordHeads[$recordId])
                || $revision < 1
                || isset($recordHistory[$recordId][$revision])
                || !isset($schemaVersions[(int) ($record['schema_version'] ?? 0)])
                || ($values['larena_scope_ref'] ?? null) !== $scopeRef
                || !$this->validHash((string) ($record['content_hash'] ?? ''))
                || !hash_equals((string) $record['content_hash'], $contentHash)) {
                throw new RuntimeException('storage_workbench_identity_record_corrupt');
            }
            $recordHistory[$recordId][$revision] = $record;
        }
        foreach ($recordHeads as $recordId => $recordHead) {
            $currentRevision = (int) ($recordHead['current_revision'] ?? 0);
            $history = $recordHistory[$recordId] ?? [];
            $current = $history[$currentRevision] ?? null;
            if (array_keys($history) !== range(1, $currentRevision)
                || !is_array($current)
                || (string) ($recordHead['owner_ref'] ?? '') !== (string) ($current['owner_ref'] ?? '')
                || (int) ($recordHead['current_schema_version'] ?? 0) !== (int) ($current['schema_version'] ?? 0)
                || !hash_equals((string) ($recordHead['current_hash'] ?? ''), (string) ($current['content_hash'] ?? ''))) {
                throw new RuntimeException('storage_workbench_identity_record_head_corrupt');
            }
        }

        $targetCurrentHash = null;
        foreach ($targetVersions as $targetVersion) {
            if ((int) $targetVersion['version'] === $currentVersion) {
                $targetCurrentHash = (string) $targetVersion['definition_hash'];
            }
        }
        if (!is_string($targetCurrentHash)) {
            throw new RuntimeException('storage_workbench_identity_schema_head_corrupt');
        }

        return [
            'legacy_schema_id' => $legacySchemaId,
            'scope_ref' => $scopeRef,
            'storage_schema_id' => $storageSchemaId,
            'schema_versions' => $schemaVersions,
            'target_versions' => $targetVersions,
            'target_head' => [
                'schema_id' => $storageSchemaId,
                'current_version' => $currentVersion,
                'current_hash' => $targetCurrentHash,
                'created_at' => (string) ($head['created_at'] ?? ''),
                'updated_at' => (string) ($head['updated_at'] ?? ''),
            ],
        ];
    }

    /** @param array<string, mixed> $plan */
    private function applyMigrationPlan(array $plan): void
    {
        $database = Schema::getConnection();
        foreach ($plan['identities'] as $identity) {
            foreach ($identity['target_versions'] as $version) {
                $database->table('larena_storage_schema_versions')->insert($version);
            }
            $database->table('larena_storage_schemas')->insert($identity['target_head']);
            $database->table('larena_storage_record_versions')
                ->where('schema_id', $identity['legacy_schema_id'])
                ->update(['schema_id' => $identity['storage_schema_id']]);
            $database->table('larena_storage_records')
                ->where('schema_id', $identity['legacy_schema_id'])
                ->update(['schema_id' => $identity['storage_schema_id']]);
        }

        $mysql = $database->getDriverName() === 'mysql';
        $this->createScopedTables($mysql);
        foreach ($plan['scoped_heads'] as $head) {
            $database->table(self::NEXT_HEADS)->insert($head);
        }
        foreach ($plan['scoped_versions'] as $version) {
            $database->table(self::NEXT_VERSIONS)->insert($version);
        }

        if ($mysql) {
            $this->restoreMySqlAutoIncrement(self::NEXT_VERSIONS, $plan['workbench_versions_auto_increment']);
            $database->statement(sprintf(
                'RENAME TABLE `%s` TO `%s`, `%s` TO `%s`, `%s` TO `%s`, `%s` TO `%s`',
                self::HEADS,
                self::PREVIOUS_HEADS,
                self::VERSIONS,
                self::PREVIOUS_VERSIONS,
                self::NEXT_HEADS,
                self::HEADS,
                self::NEXT_VERSIONS,
                self::VERSIONS,
            ));
            $database->statement(sprintf('DROP TABLE `%s`, `%s`', self::PREVIOUS_VERSIONS, self::PREVIOUS_HEADS));

            return;
        }

        Schema::drop(self::VERSIONS);
        Schema::drop(self::HEADS);
        $this->finalizeScopedIndexNames();
        Schema::rename(self::NEXT_HEADS, self::HEADS);
        Schema::rename(self::NEXT_VERSIONS, self::VERSIONS);
    }

    /** @param array<string, mixed> $plan */
    private function restoreMySqlPreMigrationState(array $plan): void
    {
        $database = Schema::getConnection();
        foreach ($plan['identities'] as $identity) {
            $database->table('larena_storage_record_versions')
                ->where('schema_id', $identity['storage_schema_id'])
                ->update(['schema_id' => $identity['legacy_schema_id']]);
            $database->table('larena_storage_records')
                ->where('schema_id', $identity['storage_schema_id'])
                ->update(['schema_id' => $identity['legacy_schema_id']]);
            $database->table('larena_storage_schemas')->where('schema_id', $identity['storage_schema_id'])->delete();
            $database->table('larena_storage_schema_versions')->where('schema_id', $identity['storage_schema_id'])->delete();
        }

        $database->statement(sprintf(
            'DROP TABLE IF EXISTS `%s`, `%s`, `%s`, `%s`, `%s`, `%s`',
            self::NEXT_VERSIONS,
            self::NEXT_HEADS,
            self::PREVIOUS_VERSIONS,
            self::PREVIOUS_HEADS,
            self::VERSIONS,
            self::HEADS,
        ));
        (require __DIR__ . '/2026_08_09_000001_create_larena_storage_workbench_tables.php')->up();
        foreach ($plan['legacy_heads'] as $head) {
            $database->table(self::HEADS)->insert($head);
        }
        foreach ($plan['legacy_versions'] as $version) {
            $database->table(self::VERSIONS)->insert($version);
        }
        $this->restoreMySqlAutoIncrement('larena_storage_schema_versions', $plan['schema_versions_auto_increment']);
        $this->restoreMySqlAutoIncrement(self::VERSIONS, $plan['workbench_versions_auto_increment']);
    }

    private function createScopedTables(bool $finalIndexNames): void
    {
        Schema::create(self::NEXT_HEADS, static function (Blueprint $table) use ($finalIndexNames): void {
            $table->string('scope_ref', 191);
            $table->string('structure_id', 120);
            $table->string('storage_schema_id', 120);
            $table->unsignedBigInteger('current_version');
            $table->unsignedBigInteger('current_schema_version');
            $table->char('current_hash', 64);
            $table->timestamps();
            $table->primary(['scope_ref', 'structure_id'], 'storage_workbench_structure_primary');
            $table->unique('storage_schema_id', $finalIndexNames
                ? 'storage_workbench_storage_schema_unique'
                : 'storage_workbench_storage_schema_unique_scoped_v1_tmp');
            $table->index(['scope_ref', 'structure_id'], $finalIndexNames
                ? 'storage_workbench_structure_scope_index'
                : 'storage_workbench_structure_scope_index_scoped_v1_tmp');
        });
        Schema::create(self::NEXT_VERSIONS, static function (Blueprint $table) use ($finalIndexNames): void {
            $table->bigIncrements('id');
            $table->string('structure_id', 120);
            $table->unsignedBigInteger('version');
            $table->string('scope_ref', 191);
            $table->string('storage_schema_id', 120);
            $table->string('label', 120);
            $table->json('fields_json');
            $table->unsignedBigInteger('schema_version');
            $table->char('descriptor_hash', 64);
            $table->string('created_by', 191);
            $table->string('correlation_id', 191)->nullable();
            $table->timestamp('created_at');
            $table->unique(['scope_ref', 'structure_id', 'version'], $finalIndexNames
                ? 'storage_workbench_structure_version_unique'
                : 'storage_workbench_structure_version_unique_scoped_v1_tmp');
            $table->unique(['storage_schema_id', 'version'], $finalIndexNames
                ? 'storage_workbench_storage_schema_version_unique'
                : 'storage_workbench_storage_schema_version_unique_scoped_v1_tmp');
            $table->index(['scope_ref', 'structure_id', 'created_at'], $finalIndexNames
                ? 'storage_workbench_structure_history_index'
                : 'storage_workbench_structure_history_index_scoped_v1_tmp');
        });
    }

    private function finalizeScopedIndexNames(): void
    {
        Schema::table(self::NEXT_HEADS, static function (Blueprint $table): void {
            $table->dropUnique('storage_workbench_storage_schema_unique_scoped_v1_tmp');
            $table->dropIndex('storage_workbench_structure_scope_index_scoped_v1_tmp');
            $table->unique('storage_schema_id', 'storage_workbench_storage_schema_unique');
            $table->index(['scope_ref', 'structure_id'], 'storage_workbench_structure_scope_index');
        });
        Schema::table(self::NEXT_VERSIONS, static function (Blueprint $table): void {
            $table->dropUnique('storage_workbench_structure_version_unique_scoped_v1_tmp');
            $table->dropUnique('storage_workbench_storage_schema_version_unique_scoped_v1_tmp');
            $table->dropIndex('storage_workbench_structure_history_index_scoped_v1_tmp');
            $table->unique(['scope_ref', 'structure_id', 'version'], 'storage_workbench_structure_version_unique');
            $table->unique(['storage_schema_id', 'version'], 'storage_workbench_storage_schema_version_unique');
            $table->index(['scope_ref', 'structure_id', 'created_at'], 'storage_workbench_structure_history_index');
        });
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function canonicalize(mixed $value): mixed
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

    private function assertLegacyIdentity(string $structureId, string $scopeRef): void
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $structureId) !== 1
            || $scopeRef === ''
            || strlen($scopeRef) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $scopeRef) === 1) {
            throw new RuntimeException('storage_workbench_identity_invalid');
        }
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json, string $reason): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException($reason);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($reason);
        }

        return $decoded;
    }

    /** @return list<mixed> */
    private function decodeList(string $json, string $reason): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException($reason);
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException($reason);
        }

        return $decoded;
    }

    /** @param list<mixed> $fields */
    private function descriptorHash(
        string $structureId,
        string $scopeRef,
        int $version,
        int $schemaVersion,
        string $label,
        array $fields,
    ): string {
        return hash('sha256', $this->canonicalJson([
            'structure_id' => $structureId,
            'scope_ref' => $scopeRef,
            'version' => $version,
            'schema_version' => $schemaVersion,
            'label' => $label,
            'fields' => $fields,
        ]));
    }

    private function validHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
    }

    private function mysqlAutoIncrement(string $table): ?int
    {
        $database = Schema::getConnection();
        if ($database->getDriverName() !== 'mysql') {
            return null;
        }
        try {
            if ((string) $database->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                return null;
            }
            $row = $database->selectOne(
                'SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table],
            );
        } catch (Throwable) {
            throw new RuntimeException('storage_workbench_identity_preflight_incomplete');
        }
        if (!is_object($row) || !isset($row->next_id) || !is_numeric($row->next_id)) {
            throw new RuntimeException('storage_workbench_identity_preflight_incomplete');
        }

        return (int) $row->next_id;
    }

    private function restoreMySqlAutoIncrement(string $table, mixed $nextId): void
    {
        if (!is_int($nextId) || $nextId < 1) {
            return;
        }
        Schema::getConnection()->statement(sprintf('ALTER TABLE `%s` AUTO_INCREMENT = %d', $table, $nextId));
    }
};
