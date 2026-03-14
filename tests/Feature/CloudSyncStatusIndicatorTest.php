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
            'bootstrap_status' => 'downloading',
            'bootstrap_progress_percent' => 12,
            'bootstrap_progress_label' => 'Downloading products',
        ]
    );

    $contents = view('filament.store.partials.cloud-sync-status')->render();

    expect($contents)
        ->toContain('Downloading store data')
        ->toContain('The POS is blocked until this first store install finishes.');
});

it('shows installing copy while bootstrap is installing', function (): void {
    AppRuntimeState::query()->updateOrCreate(
        ['id' => 1],
        [
            'mode' => 'cloud',
            'cloud_token_present' => true,
            'cloud_token' => 'token',
            'cloud_base_url' => 'https://cloud.example.test',
            'bootstrap_status' => 'installing',
            'bootstrap_progress_percent' => 72,
            'bootstrap_progress_label' => 'Installing variations',
            'store_sync_states' => [
                '3' => [
                    'bootstrap_status' => 'installing',
                    'bootstrap_progress_percent' => 72,
                    'bootstrap_progress_label' => 'Installing variations',
                    'bootstrap_generation' => 'abc',
                    'last_delta_pull_at' => null,
                    'last_delta_push_at' => null,
                ],
            ],
            'active_store_id' => 3,
        ]
    );

    $contents = view('filament.store.partials.cloud-sync-status')->render();

    expect($contents)
        ->toContain('Installing store data')
        ->toContain('The POS is blocked until this first store install finishes.');
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
        ->toContain('Sync has errors');
});
