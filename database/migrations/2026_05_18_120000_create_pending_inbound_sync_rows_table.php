<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Holds pulled-from-cloud rows whose foreign keys could not yet be
     * resolved locally (e.g. a sale_variation arrived before its parent
     * variation existed in this database). The cloud sync re-tries these
     * on every subsequent /delta cycle until they apply cleanly, so a
     * temporarily missing dependency no longer drops the row forever.
     */
    public function up(): void
    {
        Schema::create('pending_inbound_sync_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('resource', 64)->index();
            $table->unsignedBigInteger('store_id')->index();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_inbound_sync_rows');
    }
};
