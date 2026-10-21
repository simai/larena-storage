<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The registry of site витрины: the published read model of public lists.
 *
 * - `larena_storage_site_views`: one row per published storage, with its table, the
 *   layout of its public fields, the hash of those fields (a change of them silences
 *   the витрина until it is rebuilt) and its state.
 * - `larena_storage_site_view_slots`: one row per slot, a slot being one storage,
 *   scope and locale; its state and the locale chain it was built along. A new
 *   scope or locale is a row here, never DDL inside a publication.
 *
 * The site tables themselves (`larena_storage_site_<24 hex>`, with `_m` and `_s`)
 * are created per storage at runtime; rolling this migration back drops every one.
 *
 * The publication states gain the index the site rebuild and the slot reads walk:
 * (schema_id, scope_ref, locale, state, record_id), built online on MySQL.
 */
return new class extends Migration
{
    private const STATES = 'larena_storage_publication_states';
    private const STATES_INDEX = 'storage_pub_schema_scope_locale_state_record_idx';

    public function up(): void
    {
        if (!Schema::hasTable('larena_storage_site_views')) {
            Schema::create('larena_storage_site_views', static function (Blueprint $table): void {
                $table->string('schema_id', 120)->primary();
                $table->string('table_name', 64)->unique('storage_site_views_table_name_uq');
                $table->longText('layout_json');
                $table->char('layout_hash', 64);
                $table->char('public_fields_hash', 64);
                $table->string('state', 16);
                $table->unsignedInteger('built_schema_version')->nullable();
                $table->timestamps();

                $table->index(['state', 'schema_id'], 'storage_site_views_state_idx');
            });
        }

        if (!Schema::hasTable('larena_storage_site_view_slots')) {
            Schema::create('larena_storage_site_view_slots', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('schema_id', 120);
                $table->string('scope_ref', 80);
                $table->string('locale', 16);
                $table->string('locale_chain', 255)->nullable();
                $table->string('state', 16);
                $table->string('build_cursor', 64)->nullable();
                $table->string('build_run_ref', 64)->nullable();
                $table->timestamp('built_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(['schema_id', 'scope_ref', 'locale'], 'storage_site_view_slots_slot_uq');
                $table->index(['state', 'schema_id'], 'storage_site_view_slots_state_idx');
            });
        }

        if (Schema::hasTable(self::STATES) && !Schema::hasIndex(self::STATES, self::STATES_INDEX)) {
            $connection = Schema::getConnection();
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                // Built in place while publications keep being written.
                $connection->statement('CREATE INDEX `' . self::STATES_INDEX . '` ON `' . self::STATES
                    . '` (`schema_id`, `scope_ref`, `locale`, `state`, `record_id`) ALGORITHM=INPLACE LOCK=NONE');
            } else {
                Schema::table(self::STATES, static function (Blueprint $table): void {
                    $table->index(['schema_id', 'scope_ref', 'locale', 'state', 'record_id'], self::STATES_INDEX);
                });
            }
        }
    }

    public function down(): void
    {
        $names = [];
        if (Schema::hasTable('larena_storage_site_views')) {
            foreach (Schema::getConnection()->table('larena_storage_site_views')->pluck('table_name') as $name) {
                $names[] = (string) $name;
            }
        }
        // Tables whose registration is gone (emptied, or left by an interrupted build) go too.
        foreach (Schema::getTables() as $table) {
            $names[] = (string) ($table['name'] ?? '');
        }
        foreach (array_unique($names) as $name) {
            if (preg_match('/\Alarena_storage_site_[0-9a-f]{24}\z/D', $name) === 1) {
                Schema::dropIfExists($name . '_s');
                Schema::dropIfExists($name . '_m');
                Schema::dropIfExists($name);
            }
        }
        Schema::dropIfExists('larena_storage_site_view_slots');
        Schema::dropIfExists('larena_storage_site_views');
        if (Schema::hasTable(self::STATES) && Schema::hasIndex(self::STATES, self::STATES_INDEX)) {
            Schema::table(self::STATES, static function (Blueprint $table): void {
                $table->dropIndex(self::STATES_INDEX);
            });
        }
    }
};
