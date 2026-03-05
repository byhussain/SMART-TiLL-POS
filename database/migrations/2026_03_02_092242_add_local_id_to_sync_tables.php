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
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'local_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('local_id')->nullable();

                if (Schema::hasColumn($table, 'store_id')) {
                    $blueprint->unique(['store_id', 'local_id'], "{$table}_store_local_id_unique");
                } else {
                    $blueprint->index('local_id', "{$table}_local_id_index");
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'local_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'store_id')) {
                    $blueprint->dropUnique("{$table}_store_local_id_unique");
                } else {
                    $blueprint->dropIndex("{$table}_local_id_index");
                }

                $blueprint->dropColumn('local_id');
            });
        }
    }
};
