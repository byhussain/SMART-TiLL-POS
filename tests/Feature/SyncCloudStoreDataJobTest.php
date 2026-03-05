<?php

use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use App\Models\SyncOutbox;
use App\Services\CloudSyncService;
use App\Services\RuntimeStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;

uses(RefreshDatabase::class);

it('records a failed sync_outbox row when background sync returns not ok', function (): void {
    $store = Store::query()->create([
        'name' => 'Cloud Store',
        'server_id' => 99,
    ]);

    $state = new AppRuntimeState;
    $state->mode = 'cloud';
    $state->cloud_token_present = true;
    $state->cloud_token = 'token';
    $state->cloud_base_url = 'https://cloud.example.test';
    $state->active_store_id = $store->id;

    $runtimeStateService = \Mockery::mock(RuntimeStateService::class);
    $runtimeStateService->shouldReceive('get')->once()->andReturn($state);

    $mock = \Mockery::mock(CloudSyncService::class);
    $mock->shouldReceive('syncNow')
        ->once()
        ->andReturn([
            'ok' => false,
            'message' => 'Unable to sync store.',
        ]);
    app()->instance(CloudSyncService::class, $mock);

    $job = new SyncCloudStoreData($store->id);
    $job->handle($runtimeStateService, app(CloudSyncService::class));

    $this->assertDatabaseHas('sync_outbox', [
        'entity_type' => 'cloud_store_sync',
        'local_id' => $store->id,
        'operation' => 'pull',
        'status' => 'failed',
        'error' => 'Unable to sync store.',
    ]);

    expect(SyncOutbox::query()->count())->toBe(1);
});

it('uses long-running queue settings and overlap lock for the same store', function (): void {
    $job = new SyncCloudStoreData(123);

    expect($job->tries)->toBe(3);
    expect($job->timeout)->toBe(1200);
    expect($job->backoff)->toBe(30);

    $middleware = $job->middleware();
    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
    expect($middleware[0]->releaseAfter)->toBeNull();
});
