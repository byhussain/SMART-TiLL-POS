<?php

use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use App\Models\SyncOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('queues manual cloud sync in background instead of running inline', function (): void {
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

    Queue::fake();

    $response = $this->post(route('startup.cloud.sync-now'));

    $response->assertRedirect();
    Queue::assertPushed(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === (int) $store->id;
    });
});

it('returns json without redirect for async manual sync', function (): void {
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

    Queue::fake();

    $response = $this->postJson(route('startup.cloud.sync-now'));

    $response
        ->assertOk()
        ->assertJson([
            'queued' => true,
            'store_id' => (int) $store->id,
        ]);

    Queue::assertPushed(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === (int) $store->id;
    });
});

it('clears previous sync failures before queuing a new manual sync', function (): void {
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

    $customerId = DB::table('customers')->insertGetId([
        'store_id' => $store->id,
        'name' => 'Failed Customer',
        'sync_state' => 'failed',
        'sync_error' => 'Previous sync failure',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    SyncOutbox::query()->create([
        'entity_type' => 'customers',
        'local_id' => $customerId,
        'operation' => 'upsert',
        'status' => 'failed',
        'attempts' => 2,
        'error' => 'Outbox failed',
        'payload' => json_encode(['id' => $customerId]),
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SyncCloudStoreData']),
        'exception' => 'Previous failure',
        'failed_at' => now(),
    ]);

    Queue::fake();

    $response = $this->post(route('startup.cloud.sync-now'));

    $response->assertRedirect();
    Queue::assertPushed(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === (int) $store->id;
    });

    expect(DB::table('failed_jobs')->count())->toBe(0);

    $customer = DB::table('customers')->where('id', $customerId)->first(['sync_state', 'sync_error']);
    expect($customer)->not->toBeNull();
    expect($customer->sync_state)->toBe('pending');
    expect($customer->sync_error)->toBeNull();

    $outbox = SyncOutbox::query()->first();
    expect($outbox)->not->toBeNull();
    expect($outbox->status)->toBe('pending');
    expect($outbox->error)->toBeNull();
    expect((int) $outbox->attempts)->toBe(0);
});
