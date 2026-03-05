<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('local_id_sequences')) {
            return;
        }

        if (! Schema::hasColumn('local_id_sequences', 'entity_type')) {
            Schema::table('local_id_sequences', function (Blueprint $table): void {
                $table->string('entity_type', 64)->default('*')->after('store_id');
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS local_id_sequences_prefix_store_unique');
        } else {
            try {
                Schema::table('local_id_sequences', function (Blueprint $table): void {
                    $table->dropUnique('local_id_sequences_prefix_store_unique');
                });
            } catch (\Throwable) {
            }
        }

        try {
            Schema::table('local_id_sequences', function (Blueprint $table): void {
                $table->unique(['prefix', 'store_id', 'entity_type'], 'local_id_sequences_prefix_store_entity_unique');
            });
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('local_id_sequences')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS local_id_sequences_prefix_store_entity_unique');
        } else {
            try {
                Schema::table('local_id_sequences', function (Blueprint $table): void {
                    $table->dropUnique('local_id_sequences_prefix_store_entity_unique');
                });
            } catch (\Throwable) {
            }
        }

        if (Schema::hasColumn('local_id_sequences', 'entity_type')) {
            Schema::table('local_id_sequences', function (Blueprint $table): void {
                $table->dropColumn('entity_type');
            });
        }

        try {
            Schema::table('local_id_sequences', function (Blueprint $table): void {
                $table->unique(['prefix', 'store_id'], 'local_id_sequences_prefix_store_unique');
            });
        } catch (\Throwable) {
        }
    }
};
