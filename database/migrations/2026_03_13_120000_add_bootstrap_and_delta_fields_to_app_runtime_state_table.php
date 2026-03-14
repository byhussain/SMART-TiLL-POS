<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_runtime_state', function (Blueprint $table): void {
            if (! Schema::hasColumn('app_runtime_state', 'bootstrap_status')) {
                $table->string('bootstrap_status')->default('not_started')->after('last_synced_at');
            }

            if (! Schema::hasColumn('app_runtime_state', 'bootstrap_progress_percent')) {
                $table->unsignedInteger('bootstrap_progress_percent')->default(0)->after('bootstrap_status');
            }

            if (! Schema::hasColumn('app_runtime_state', 'bootstrap_progress_label')) {
                $table->string('bootstrap_progress_label')->nullable()->after('bootstrap_progress_percent');
            }

            if (! Schema::hasColumn('app_runtime_state', 'bootstrap_generation')) {
                $table->string('bootstrap_generation')->nullable()->after('bootstrap_progress_label');
            }

            if (! Schema::hasColumn('app_runtime_state', 'last_delta_pull_at')) {
                $table->timestamp('last_delta_pull_at')->nullable()->after('bootstrap_generation');
            }

            if (! Schema::hasColumn('app_runtime_state', 'last_delta_push_at')) {
                $table->timestamp('last_delta_push_at')->nullable()->after('last_delta_pull_at');
            }

            if (! Schema::hasColumn('app_runtime_state', 'store_sync_states')) {
                $table->longText('store_sync_states')->nullable()->after('last_delta_push_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_runtime_state', function (Blueprint $table): void {
            foreach ([
                'store_sync_states',
                'last_delta_push_at',
                'last_delta_pull_at',
                'bootstrap_generation',
                'bootstrap_progress_label',
                'bootstrap_progress_percent',
                'bootstrap_status',
            ] as $column) {
                if (Schema::hasColumn('app_runtime_state', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
