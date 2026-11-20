<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The receipts of the document port (simai.document-port 1.0.0) on a record's `composition@1` field: what a record
 * version and the publication log cannot hold.
 *
 * - A write that carried a client_key, with the fingerprint of its input: a repeat answers what it made, other input
 *   is an idempotency conflict. A record version keeps its correlation id hashed, so the key could not be read back.
 * - The revision a restore came from.
 * - A publication: its id, the record version it published (`revision`, a row of larena_storage_record_versions),
 *   its languages and the publication a rollback repeats. Each language's head stays in the publication lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('larena_storage_document_port_receipts')) {
            return;
        }
        Schema::create('larena_storage_document_port_receipts', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('schema_id', 120);
            $table->string('record_id', 39);
            $table->string('field_key', 64);
            $table->string('intent', 24);
            $table->string('client_key', 100)->nullable();
            $table->char('params_hash', 64)->nullable();
            $table->unsignedBigInteger('revision')->nullable();
            $table->unsignedBigInteger('restored_from')->nullable();
            $table->string('publication_id', 100)->nullable();
            $table->string('publication_from', 100)->nullable();
            $table->text('languages')->nullable();
            $table->string('actor_id', 191);
            $table->timestamp('created_at');
            $table->unique(['schema_id', 'record_id', 'field_key', 'client_key'], 'storage_doc_port_key_uq');
            $table->index(['schema_id', 'record_id', 'field_key', 'publication_id'], 'storage_doc_port_publication_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_document_port_receipts');
    }
};
