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
        'purchase_order_products',
        'sale_variation',
        'sale_preparable_items',
        'transactions',
        'units',
        'unit_dimensions',
        'model_activities',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'reference')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('reference')->nullable()->after('local_id');

                if (Schema::hasColumn($table, 'store_id')) {
                    $blueprint->index(['store_id', 'reference'], "{$table}_store_reference_index");
                } else {
                    $blueprint->index('reference', "{$table}_reference_index");
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'reference')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'store_id')) {
                    $blueprint->dropIndex("{$table}_store_reference_index");
                } else {
                    $blueprint->dropIndex("{$table}_reference_index");
                }

                $blueprint->dropColumn('reference');
            });
        }
    }
};
