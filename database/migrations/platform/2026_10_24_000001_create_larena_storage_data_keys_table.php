<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The data keys of storages with encrypted fields (StorageDataKeys): one random key a storage, stored only wrapped by
 * the key-encryption key of larena/secret (`wrapped_key`: kek id, algorithm, nonce and ciphertext as JSON; `kek_id`
 * repeated for the rewrap of a KEK rotation). `status` is active, retiring or retired; `active_slot` is 1 for the
 * active key and null otherwise, unique with the schema id, so a storage has at most one active key. A structure
 * purge deletes its keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('larena_storage_data_keys')) {
            return;
        }
        Schema::create('larena_storage_data_keys', static function (Blueprint $table): void {
            $table->string('key_id', 64)->primary();
            $table->string('schema_id', 120);
            $table->string('status', 16);
            $table->text('wrapped_key');
            $table->string('kek_id', 96);
            $table->string('algorithm', 16);
            $table->string('created_at', 32);
            $table->string('retired_at', 32)->nullable();
            $table->unsignedTinyInteger('active_slot')->nullable();

            $table->unique(['schema_id', 'active_slot'], 'storage_data_keys_active_uq');
            $table->index(['schema_id', 'status'], 'storage_data_keys_schema_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_data_keys');
    }
};
