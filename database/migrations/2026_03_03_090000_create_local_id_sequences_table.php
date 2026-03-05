<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('local_id_sequences')) {
            return;
        }

        Schema::create('local_id_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('prefix', 16);
            $table->unsignedBigInteger('store_id')->default(0);
            $table->string('entity_type', 64)->default('*');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'store_id', 'entity_type'], 'local_id_sequences_prefix_store_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_id_sequences');
    }
};
