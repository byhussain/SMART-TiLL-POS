<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_runtime_state', function (Blueprint $table): void {
            $table->id();
            $table->boolean('has_completed_onboarding')->default(false);
            $table->string('mode')->nullable();
            $table->boolean('cloud_token_present')->default(false);
            $table->unsignedBigInteger('active_store_id')->nullable();
            $table->unsignedBigInteger('cloud_user_id')->nullable();
            $table->text('cloud_token')->nullable();
            $table->string('cloud_base_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('local_id')->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->string('operation');
            $table->longText('payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'status']);
            $table->index('server_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_outbox');
        Schema::dropIfExists('app_runtime_state');
    }
};
