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
            if (! Schema::hasColumn('stores', 'email')) {
                $table->string('email')->nullable()->after('name');
            }

            if (! Schema::hasColumn('stores', 'phone')) {
                $table->string('phone', 50)->nullable()->after('email');
            }

            if (! Schema::hasColumn('stores', 'address')) {
                $table->string('address')->nullable()->after('phone');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            if (Schema::hasColumn('stores', 'address')) {
                $table->dropColumn('address');
            }

            if (Schema::hasColumn('stores', 'phone')) {
                $table->dropColumn('phone');
            }

            if (Schema::hasColumn('stores', 'email')) {
                $table->dropColumn('email');
            }
        });
    }
};
