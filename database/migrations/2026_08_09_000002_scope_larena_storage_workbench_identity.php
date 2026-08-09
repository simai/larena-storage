<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Larena\Storage\Runtime\ScopedStorageWorkbenchSchemaIdentity;

return new class extends Migration
{
    private const HEADS = 'larena_storage_workbench_structures';
    private const VERSIONS = 'larena_storage_workbench_structure_versions';
    private const NEXT_HEADS = 'larena_storage_workbench_structures_scoped_v1';
    private const NEXT_VERSIONS = 'larena_storage_workbench_structure_versions_scoped_v1';

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
        if ($headsScoped || $versionsScoped || Schema::hasTable(self::NEXT_HEADS) || Schema::hasTable(self::NEXT_VERSIONS)) {
            throw new RuntimeException('storage_workbench_identity_partial_state');
        }

        $database = Schema::getConnection();
        /** @var list<object> $heads */
        $heads = $database->table(self::HEADS)->orderBy('structure_id')->get()->all();
        /** @var list<object> $versions */
        $versions = $database->table(self::VERSIONS)->orderBy('structure_id')->orderBy('version')->get()->all();
        $mapped = [];
        foreach ($heads as $head) {
            $structureId = (string) $head->structure_id;
            $scopeRef = (string) $head->scope_ref;
            $storageSchemaId = ScopedStorageWorkbenchSchemaIdentity::derive($scopeRef, $structureId);
            if (isset($mapped[$storageSchemaId])) {
                throw new RuntimeException('storage_workbench_identity_collision');
            }
            $mapped[$storageSchemaId] = ['structure_id' => $structureId, 'scope_ref' => $scopeRef, 'head' => $head];
            $this->assertLegacySchemaEligible($structureId, $scopeRef, $storageSchemaId);
        }
        foreach ($versions as $version) {
            $storageSchemaId = ScopedStorageWorkbenchSchemaIdentity::derive(
                (string) $version->scope_ref,
                (string) $version->structure_id,
            );
            if (!isset($mapped[$storageSchemaId])) {
                throw new RuntimeException('storage_workbench_identity_history_orphaned');
            }
        }

        $upgrade = function () use ($database, $heads, $versions, $mapped): void {
            foreach ($mapped as $storageSchemaId => $identity) {
                $this->cloneLegacySchema(
                    (string) $identity['structure_id'],
                    (string) $identity['scope_ref'],
                    $storageSchemaId,
                );
            }

            $this->createScopedTables();
            foreach ($heads as $head) {
                $storageSchemaId = ScopedStorageWorkbenchSchemaIdentity::derive((string) $head->scope_ref, (string) $head->structure_id);
                $database->table(self::NEXT_HEADS)->insert([
                    'scope_ref' => (string) $head->scope_ref,
                    'structure_id' => (string) $head->structure_id,
                    'storage_schema_id' => $storageSchemaId,
                    'current_version' => (int) $head->current_version,
                    'current_schema_version' => (int) $head->current_schema_version,
                    'current_hash' => (string) $head->current_hash,
                    'created_at' => (string) $head->created_at,
                    'updated_at' => (string) $head->updated_at,
                ]);
            }
            foreach ($versions as $version) {
                $database->table(self::NEXT_VERSIONS)->insert([
                    'structure_id' => (string) $version->structure_id,
                    'version' => (int) $version->version,
                    'scope_ref' => (string) $version->scope_ref,
                    'storage_schema_id' => ScopedStorageWorkbenchSchemaIdentity::derive((string) $version->scope_ref, (string) $version->structure_id),
                    'label' => (string) $version->label,
                    'fields_json' => (string) $version->fields_json,
                    'schema_version' => (int) $version->schema_version,
                    'descriptor_hash' => (string) $version->descriptor_hash,
                    'created_by' => (string) $version->created_by,
                    'correlation_id' => $version->correlation_id === null ? null : (string) $version->correlation_id,
                    'created_at' => (string) $version->created_at,
                ]);
            }

            Schema::drop(self::VERSIONS);
            Schema::drop(self::HEADS);
            $this->finalizeScopedIndexNames();
            Schema::rename(self::NEXT_HEADS, self::HEADS);
            Schema::rename(self::NEXT_VERSIONS, self::VERSIONS);
        };

        // MySQL and MariaDB implicitly commit DDL statements. Wrapping this
        // sequence in a PDO transaction therefore makes Laravel attempt to
        // commit a transaction the server has already closed. SQLite supports
        // transactional DDL and keeps the stronger atomic boundary.
        if ($database->getDriverName() === 'mysql') {
            $upgrade();

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

    private function assertLegacySchemaEligible(string $legacySchemaId, string $scopeRef, string $storageSchemaId): void
    {
        $database = Schema::getConnection();
        if ($database->table('larena_storage_schemas')->where('schema_id', $storageSchemaId)->exists()
            || $database->table('larena_storage_schema_versions')->where('schema_id', $storageSchemaId)->exists()
            || $database->table('larena_storage_records')->where('schema_id', $storageSchemaId)->exists()
            || $database->table('larena_storage_record_versions')->where('schema_id', $storageSchemaId)->exists()) {
            throw new RuntimeException('storage_workbench_identity_target_occupied');
        }
        $head = $database->table('larena_storage_schemas')->where('schema_id', $legacySchemaId)->first();
        $versions = $database->table('larena_storage_schema_versions')->where('schema_id', $legacySchemaId)->get()->all();
        if ($head === null || $versions === []) {
            throw new RuntimeException('storage_workbench_identity_schema_missing');
        }
        foreach ($versions as $version) {
            if ((string) $version->owner_package !== 'larena/storage') {
                throw new RuntimeException('storage_workbench_identity_schema_owner_invalid');
            }
        }
        foreach ($database->table('larena_storage_record_versions')->where('schema_id', $legacySchemaId)->get()->all() as $record) {
            $values = json_decode((string) $record->values_json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($values) || ($values['larena_scope_ref'] ?? null) !== $scopeRef) {
                throw new RuntimeException('storage_workbench_identity_record_scope_invalid');
            }
        }
    }

    private function cloneLegacySchema(string $legacySchemaId, string $scopeRef, string $storageSchemaId): void
    {
        $database = Schema::getConnection();
        $legacyHead = $database->table('larena_storage_schemas')->where('schema_id', $legacySchemaId)->first();
        if ($legacyHead === null) {
            throw new RuntimeException('storage_workbench_identity_schema_missing');
        }
        $currentHash = null;
        foreach ($database->table('larena_storage_schema_versions')->where('schema_id', $legacySchemaId)->orderBy('version')->get()->all() as $version) {
            $definition = json_decode((string) $version->definition, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($definition) || ($definition['schema_id'] ?? null) !== $legacySchemaId) {
                throw new RuntimeException('storage_workbench_identity_schema_corrupt');
            }
            $definition['schema_id'] = $storageSchemaId;
            $definitionJson = $this->canonicalJson($definition);
            $definitionHash = hash('sha256', $definitionJson);
            $database->table('larena_storage_schema_versions')->insert([
                'schema_id' => $storageSchemaId,
                'version' => (int) $version->version,
                'definition' => $definitionJson,
                'definition_hash' => $definitionHash,
                'owner_package' => (string) $version->owner_package,
                'created_by' => (string) $version->created_by,
                'correlation_id' => $version->correlation_id === null ? null : (string) $version->correlation_id,
                'created_at' => (string) $version->created_at,
            ]);
            if ((int) $version->version === (int) $legacyHead->current_version) {
                $currentHash = $definitionHash;
            }
        }
        if (!is_string($currentHash)) {
            throw new RuntimeException('storage_workbench_identity_schema_head_corrupt');
        }
        $database->table('larena_storage_schemas')->insert([
            'schema_id' => $storageSchemaId,
            'current_version' => (int) $legacyHead->current_version,
            'current_hash' => $currentHash,
            'created_at' => (string) $legacyHead->created_at,
            'updated_at' => (string) $legacyHead->updated_at,
        ]);
        $database->table('larena_storage_record_versions')->where('schema_id', $legacySchemaId)->update(['schema_id' => $storageSchemaId]);
        $database->table('larena_storage_records')->where('schema_id', $legacySchemaId)->update(['schema_id' => $storageSchemaId]);
    }

    private function createScopedTables(): void
    {
        Schema::create(self::NEXT_HEADS, static function (Blueprint $table): void {
            $table->string('scope_ref', 191);
            $table->string('structure_id', 120);
            $table->string('storage_schema_id', 120);
            $table->unsignedBigInteger('current_version');
            $table->unsignedBigInteger('current_schema_version');
            $table->char('current_hash', 64);
            $table->timestamps();
            $table->primary(['scope_ref', 'structure_id'], 'storage_workbench_structure_primary');
            $table->unique('storage_schema_id', 'storage_workbench_storage_schema_unique_scoped_v1_tmp');
            $table->index(['scope_ref', 'structure_id'], 'storage_workbench_structure_scope_index_scoped_v1_tmp');
        });
        Schema::create(self::NEXT_VERSIONS, static function (Blueprint $table): void {
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
            $table->unique(['scope_ref', 'structure_id', 'version'], 'storage_workbench_structure_version_unique_scoped_v1_tmp');
            $table->unique(['storage_schema_id', 'version'], 'storage_workbench_storage_schema_version_unique_scoped_v1_tmp');
            $table->index(['scope_ref', 'structure_id', 'created_at'], 'storage_workbench_structure_history_index_scoped_v1_tmp');
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
};
