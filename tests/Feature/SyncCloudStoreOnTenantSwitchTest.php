<?php

use App\Http\Middleware\SyncCloudStoreOnTenantSwitch;
use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('queues switched tenant store sync and updates active store in cloud mode', function (): void {
    $store = Store::query()->create([
        'name' => 'Cloud Store',
        'server_id' => 11,
    ]);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'active_store_id' => null,
    ]);

    Queue::fake();

    Route::middleware([StartSession::class, SyncCloudStoreOnTenantSwitch::class])
        ->get('/_test/switch/{tenant}', fn () => response()->json(['ok' => true]));

    $response = $this->get("/_test/switch/{$store->id}");

    $response->assertOk();
    $this->assertDatabaseHas('app_runtime_state', [
        'id' => 1,
        'active_store_id' => $store->id,
    ]);
    expect(session('pos_last_sync_dispatched_tenant_id'))->toBe($store->id);
    Queue::assertPushed(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === $store->id;
    });
});
