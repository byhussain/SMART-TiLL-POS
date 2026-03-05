<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'stores',
        'store_settings',
        'brands',
        'categories',
        'attributes',
        'products',
        'product_attributes',
        'variations',
        'stocks',
        'images',
        'customers',
        'suppliers',
        'purchase_orders',
        'purchase_order_products',
        'sales',
        'sale_variation',
        'sale_preparable_items',
        'payments',
        'transactions',
        'units',
        'unit_dimensions',
        'model_activities',
        'roles',
        'permissions',
        'role_has_permissions',
        'user_role',
        'invitations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'server_id')) {
                    $blueprint->unsignedBigInteger('server_id')->nullable()->index();
                }

                if (! Schema::hasColumn($table, 'sync_state')) {
                    $blueprint->string('sync_state')->default('pending')->index();
                }

                if (! Schema::hasColumn($table, 'synced_at')) {
                    $blueprint->timestamp('synced_at')->nullable();
                }

                if (! Schema::hasColumn($table, 'sync_error')) {
                    $blueprint->text('sync_error')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'sync_error')) {
                    $blueprint->dropColumn('sync_error');
                }

                if (Schema::hasColumn($table, 'synced_at')) {
                    $blueprint->dropColumn('synced_at');
                }

                if (Schema::hasColumn($table, 'sync_state')) {
                    $blueprint->dropColumn('sync_state');
                }

                if (Schema::hasColumn($table, 'server_id')) {
                    $blueprint->dropColumn('server_id');
                }
            });
        }
    }
};
