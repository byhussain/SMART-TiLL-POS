<?php

use App\Models\AppRuntimeState;
use App\Models\SyncOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows green cloud icon when cloud is connected and all data is synced', function (): void {
    AppRuntimeState::query()->updateOrCreate(
        ['id' => 1],
        [
            'mode' => 'cloud',
            'cloud_token_present' => true,
            'cloud_token' => 'token',
            'cloud_base_url' => 'https://cloud.example.test',
        ]
    );

    $contents = view('filament.store.partials.cloud-sync-status')->render();

    expect($contents)
        ->toContain('h-6 w-6 text-emerald-600')
        ->toContain('All synced');
});

it('shows warning cloud icon when sync is in progress', function (): void {
    AppRuntimeState::query()->updateOrCreate(
        ['id' => 1],
        [
            'mode' => 'cloud',
            'cloud_token_present' => true,
            'cloud_token' => 'token',
            'cloud_base_url' => 'https://cloud.example.test',
        ]
    );

    SyncOutbox::query()->create([
        'entity_type' => 'products',
        'local_id' => 1,
        'operation' => 'upsert',
        'status' => 'pending',
    ]);

    $contents = view('filament.store.partials.cloud-sync-status')->render();

    expect($contents)
        ->toContain('h-6 w-6 text-amber-500')
        ->toContain('Sync in progress');
});

it('shows error cloud icon when sync has failures', function (): void {
    AppRuntimeState::query()->updateOrCreate(
        ['id' => 1],
        [
            'mode' => 'cloud',
            'cloud_token_present' => true,
            'cloud_token' => 'token',
            'cloud_base_url' => 'https://cloud.example.test',
        ]
    );

    SyncOutbox::query()->create([
        'entity_type' => 'products',
        'local_id' => 1,
        'operation' => 'upsert',
        'status' => 'failed',
        'error' => 'sync failed',
    ]);

    $contents = view('filament.store.partials.cloud-sync-status')->render();

    expect($contents)
        ->toContain('h-6 w-6 text-red-600')
        ->toContain('Sync has errors')
        ->toContain('Recent sync errors')
        ->toContain('sync failed');
});
