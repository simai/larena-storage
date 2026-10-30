<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times a site's page addresses changed without a publication of the pages concerned (a page above them moved
 * or was renamed). A copy of a page that names its address, such as a search document, keeps a revision that must grow
 * when the address does, and the page's own publication version does not: the epoch is that part. No row is epoch 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('larena_storage_site_address_epochs')) {
            return;
        }
        Schema::create('larena_storage_site_address_epochs', static function (Blueprint $table): void {
            $table->string('site_ref', 120)->primary();
            $table->unsignedBigInteger('epoch');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('larena_storage_site_address_epochs');
    }
};
