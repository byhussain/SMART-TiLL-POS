<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            if (! Schema::hasColumn('stores', 'country_id')) {
                $table->foreignId('country_id')->nullable()->after('name')->constrained('countries')->nullOnDelete();
            }

            if (! Schema::hasColumn('stores', 'currency_id')) {
                $table->foreignId('currency_id')->nullable()->after('country_id')->constrained('currencies')->nullOnDelete();
            }

            if (! Schema::hasColumn('stores', 'timezone_id')) {
                $table->foreignId('timezone_id')->nullable()->after('currency_id')->constrained('timezones')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            if (Schema::hasColumn('stores', 'timezone_id')) {
                $table->dropConstrainedForeignId('timezone_id');
            }

            if (Schema::hasColumn('stores', 'currency_id')) {
                $table->dropConstrainedForeignId('currency_id');
            }

            if (Schema::hasColumn('stores', 'country_id')) {
                $table->dropConstrainedForeignId('country_id');
            }
        });
    }
};
