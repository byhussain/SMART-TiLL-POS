<?php

use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('returns not syncing when no cloud jobs are queued', function (): void {
    $store = Store::query()->create([
        'name' => 'Store A',
        'server_id' => 101,
    ]);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'active_store_id' => $store->id,
    ]);

    $this->getJson(route('startup.cloud.sync-status'))
        ->assertOk()
        ->assertJson([
            'connected' => true,
            'is_syncing' => false,
        ]);
});

it('returns syncing status and running module map for active store', function (): void {
    $store = Store::query()->create([
        'name' => 'Store A',
        'server_id' => 101,
    ]);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'active_store_id' => $store->id,
    ]);

    $payload = json_encode([
        'displayName' => SyncCloudStoreData::class,
        'data' => [
            'commandName' => SyncCloudStoreData::class,
            'command' => serialize(new SyncCloudStoreData((int) $store->id, 'products')),
        ],
    ]);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => $payload,
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    $this->getJson(route('startup.cloud.sync-status'))
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('is_syncing', true)
        ->assertJsonPath('module_syncing.products', true);
});

it('returns has_errors true when selected store has outbox sync failures', function (): void {
    $store = Store::query()->create([
        'name' => 'Store A',
        'server_id' => 101,
    ]);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'active_store_id' => $store->id,
    ]);

    $customerId = (int) DB::table('customers')->insertGetId([
        'store_id' => $store->id,
        'name' => 'Customer A',
        'sync_state' => 'pending',
        'sync_error' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sync_outbox')->insert([
        'entity_type' => 'customers',
        'local_id' => $customerId,
        'operation' => 'upsert',
        'status' => 'failed',
        'attempts' => 1,
        'error' => 'Customer sync failed',
        'payload' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson(route('startup.cloud.sync-status'))
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('is_syncing', false)
        ->assertJsonPath('has_errors', true);
});

it('does not report syncing for stale reserved jobs after a sync queue failure', function (): void {
    $store = Store::query()->create([
        'name' => 'Store A',
        'server_id' => 101,
    ]);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'active_store_id' => $store->id,
    ]);

    $payload = json_encode([
        'displayName' => SyncCloudStoreData::class,
        'data' => [
            'commandName' => SyncCloudStoreData::class,
            'command' => serialize(new SyncCloudStoreData((int) $store->id, 'delta', 'sales')),
        ],
    ]);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => $payload,
        'attempts' => 1,
        'reserved_at' => now()->subMinutes(5)->timestamp,
        'available_at' => now()->timestamp,
        'created_at' => now()->subMinutes(5)->timestamp,
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $payload,
        'exception' => 'database is locked',
        'failed_at' => now(),
    ]);

    $this->getJson(route('startup.cloud.sync-status'))
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('is_syncing', false)
        ->assertJsonPath('has_errors', true)
        ->assertJsonPath('module_syncing.sales', false);
});
