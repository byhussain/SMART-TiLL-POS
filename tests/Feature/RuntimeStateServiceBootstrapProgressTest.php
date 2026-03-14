<?php

use App\Models\AppRuntimeState;
use App\Services\RuntimeStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps install status when bootstrap progress updates during installation', function (): void {
    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'active_store_id' => 3,
        'bootstrap_status' => 'not_started',
        'bootstrap_progress_percent' => 0,
        'store_sync_states' => [],
    ]);

    $service = app(RuntimeStateService::class);
    $service->markBootstrapStarted(3, 'generation-1', 'Downloading store data');
    $service->markBootstrapInstalling(3, 'Installing stocks');
    $service->updateBootstrapProgress(3, 70, 'Installing stocks (14897/58021)');

    $state = AppRuntimeState::query()->findOrFail(1);

    expect($state->bootstrap_status)->toBe('installing');
    expect($state->bootstrap_progress_percent)->toBe(70);
    expect($state->bootstrap_progress_label)->toBe('Installing stocks (14897/58021)');
    expect(data_get($state->store_sync_states, '3.bootstrap_status'))->toBe('installing');
});
