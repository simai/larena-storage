<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Larena\Setting\Persistence\DatabaseViewSettingsStore;
use Larena\Setting\ViewConfig\ViewConfigStore;
use Larena\Storage\Admin\StorageSavedViewsMove;

// The Storage lists' saved views move into the one view settings store (larena_view_settings) as simai.view-config/1
// documents: a person's views as their personal views, a scope's views for everyone as its shared views
// (StorageSavedViewsMove). Additive and idempotent: the old rows in larena_setting_values (namespace larena_dataview,
// keys list_views, saved_views.v2, saved_views) are not changed or removed here and stay until a later cleanup; a view
// already in the store is left as it is. down() removes the rows the move wrote that nobody changed since.
// It runs on the migration's connection, as Setting's migration of the store does; without the settings package
// there is nothing to move.
return new class extends Migration
{
    public function up(): void
    {
        $move = $this->move();
        $report = $move?->up();
        if ($report !== null && $report['skipped'] !== [] && Container::getInstance()->bound('log')) {
            Container::getInstance()->make('log')->warning('larena_storage_saved_views_not_moved', $report);
        }
    }

    public function down(): void
    {
        $this->move()?->down();
    }

    private function move(): ?StorageSavedViewsMove
    {
        if (!class_exists(ViewConfigStore::class) || !class_exists(DatabaseViewSettingsStore::class)) {
            return null;
        }
        // The migration's connection, where Setting's own migration made larena_view_settings.
        return new StorageSavedViewsMove(Schema::getConnection());
    }
};
