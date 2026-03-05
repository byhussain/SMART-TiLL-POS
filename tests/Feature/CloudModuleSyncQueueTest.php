<?php

use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues an individual module sync job', function (): void {
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

    $response = $this->post(route('startup.cloud.sync-module'), [
        'module' => 'sales',
    ]);

    $response->assertRedirect();
    Queue::assertPushed(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === (int) $store->id && $job->module === 'sales';
    });
});

it('returns json without redirect for async module sync', function (): void {
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

    $response = $this->postJson(route('startup.cloud.sync-module'), [
        'module' => 'sales',
    ]);

    $response
        ->assertOk()
        ->assertJson([
            'queued' => true,
            'store_id' => (int) $store->id,
            'module' => 'sales',
        ]);

    Queue::assertPushed(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === (int) $store->id && $job->module === 'sales';
    });
});
