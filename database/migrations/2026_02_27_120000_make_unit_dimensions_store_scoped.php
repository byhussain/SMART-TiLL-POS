<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('unit_dimensions')) {
            return;
        }

        if (! Schema::hasColumn('unit_dimensions', 'store_id')) {
            Schema::table('unit_dimensions', function (Blueprint $table): void {
                $table->unsignedBigInteger('store_id')->nullable()->after('id')->index();
            });
        }

        // Drop global unique(name) and replace with unique(store_id, name).
        try {
            DB::statement('DROP INDEX unit_dimensions_name_unique');
        } catch (Throwable) {
            // Ignore if index doesn't exist for current driver/schema state.
        }

        Schema::table('unit_dimensions', function (Blueprint $table): void {
            $table->unique(['store_id', 'name'], 'unit_dimensions_store_id_name_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('unit_dimensions')) {
            return;
        }

        try {
            DB::statement('DROP INDEX unit_dimensions_store_id_name_unique');
        } catch (Throwable) {
            // Ignore if index doesn't exist.
        }

        Schema::table('unit_dimensions', function (Blueprint $table): void {
            if (! Schema::hasColumn('unit_dimensions', 'store_id')) {
                return;
            }

            $table->dropColumn('store_id');
        });

        Schema::table('unit_dimensions', function (Blueprint $table): void {
            $table->unique('name', 'unit_dimensions_name_unique');
        });
    }
};
