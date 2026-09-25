<?php

declare(strict_types=1);

/**
 * Resolves a Composer autoloader that can see larena/storage, larena/core and the
 * YAML parser, then builds the frozen structure role schema on an in-memory
 * connection. The package vendor tree does not symlink larena/core, so the entry
 * application is tried first, the same way larena/access does for its own
 * cross-package tests.
 */
(static function (): void {
    if (class_exists(\Larena\Core\Registry\DeclaredOperationRegistry::class, true)
        && class_exists(\Larena\Storage\Runtime\DatabaseStructureRoleRegistry::class, true)
        && class_exists(\Symfony\Component\Yaml\Yaml::class, true)) {
        return;
    }

    $packageRoot = dirname(__DIR__, 2);
    $entryAppRoot = getenv('LARENA_ENTRY_APP_ROOT');
    $candidates = [
        dirname($packageRoot, 3) . '/larena/vendor/autoload.php',
        dirname($packageRoot, 2) . '/app/vendor/autoload.php',
        dirname($packageRoot, 2) . '/vendor/autoload.php',
        $packageRoot . '/vendor/autoload.php',
    ];
    if (is_string($entryAppRoot) && $entryAppRoot !== '') {
        array_unshift($candidates, rtrim($entryAppRoot, '/') . '/vendor/autoload.php');
    }

    foreach (array_unique($candidates) as $autoload) {
        if (!is_file($autoload)) {
            continue;
        }

        require_once $autoload;
        if (class_exists(\Larena\Core\Registry\DeclaredOperationRegistry::class, true)
            && class_exists(\Larena\Storage\Runtime\DatabaseStructureRoleRegistry::class, true)) {
            return;
        }
    }

    fwrite(STDERR, "Structure role tests need an autoloader that sees larena/storage and larena/core.\n");
    fwrite(STDERR, "Run composer install in the entry application, or set LARENA_ENTRY_APP_ROOT.\n");
    exit(1);
})();

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * The frozen structure role shapes, mirroring the migration one to one.
 */
function larena_storage_structure_role_connection(): Connection
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();
    $schema = $connection->getSchemaBuilder();

    $schema->create('larena_storage_structure_roles', static function (Blueprint $table): void {
        $table->string('role_ref', 140)->primary();
        $table->string('role_code', 120);
        $table->unsignedInteger('role_version')->default(1);
        $table->string('title', 191);
        $table->json('required_fields');
        $table->json('optional_fields');
        $table->json('required_relations');
        $table->string('lifecycle', 24);
        $table->string('owner_package', 120);
        $table->string('status', 16)->default('active');
        $table->string('created_by', 191);
        $table->string('correlation_id', 191)->nullable();
        $table->timestamps();
        $table->unique(['role_code', 'role_version'], 'storage_roles_code_version_uq');
        $table->index(['owner_package'], 'storage_roles_owner_idx');
        $table->index(['status'], 'storage_roles_status_idx');
    });

    $schema->create('larena_storage_structure_role_bindings', static function (Blueprint $table): void {
        $table->string('binding_id', 190)->primary();
        $table->string('role_ref', 140);
        $table->string('schema_id', 120);
        $table->string('scope_ref', 80);
        $table->string('status', 16)->default('active');
        $table->timestamp('conformance_checked_at')->nullable();
        $table->string('bound_by', 191);
        $table->string('correlation_id', 191)->nullable();
        $table->timestamps();
        $table->unique(['role_ref', 'schema_id', 'scope_ref'], 'storage_role_bindings_role_schema_scope_uq');
        $table->index(['scope_ref', 'role_ref'], 'storage_role_bindings_scope_role_idx');
        $table->index(['schema_id'], 'storage_role_bindings_schema_idx');
    });

    return $connection;
}

function larena_storage_role_assert(bool $condition, string $message = 'structure role assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * The field set of a structure that satisfies the site_node role.
 *
 * @return list<array{key: string, type: string}>
 */
function larena_storage_site_node_fields(): array
{
    return [
        ['key' => 'slug', 'type' => 'string'],
        ['key' => 'title', 'type' => 'string'],
        ['key' => 'order_index', 'type' => 'integer'],
        ['key' => 'target', 'type' => 'string'],
    ];
}
