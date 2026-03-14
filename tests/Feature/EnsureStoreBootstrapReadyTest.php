<?php

use App\Http\Middleware\EnsurePosSystemUserAuthenticated;
use App\Http\Middleware\EnsureStoreBootstrapReady;
use App\Models\AppRuntimeState;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

it('redirects store panel requests to bootstrap page while the first cloud install is running', function (): void {
    $store = Store::query()->create([
        'name' => 'Cloud Store',
        'server_id' => 33,
    ]);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'active_store_id' => $store->id,
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'store_sync_states' => [
            (string) $store->id => [
                'bootstrap_status' => 'installing',
                'bootstrap_progress_percent' => 73,
                'bootstrap_progress_label' => 'Installing variations',
                'bootstrap_generation' => 'abc',
                'last_delta_pull_at' => null,
                'last_delta_push_at' => null,
            ],
        ],
    ]);

    Route::middleware([StartSession::class, EnsurePosSystemUserAuthenticated::class, EnsureStoreBootstrapReady::class])
        ->get('/_test/bootstrap-guard/{tenant}', fn () => response('ok'))
        ->name('test.bootstrap.guard');

    $response = $this->get("/_test/bootstrap-guard/{$store->id}");

    $response->assertRedirect(route('startup.cloud.bootstrap', ['store' => $store->id]));
});

it('allows store panel requests after bootstrap is ready', function (): void {
    $store = Store::query()->create([
        'name' => 'Cloud Store',
        'server_id' => 33,
    ]);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'active_store_id' => $store->id,
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'store_sync_states' => [
            (string) $store->id => [
                'bootstrap_status' => 'ready',
                'bootstrap_progress_percent' => 100,
                'bootstrap_progress_label' => 'Store data is ready.',
                'bootstrap_generation' => 'abc',
                'last_delta_pull_at' => now()->toISOString(),
                'last_delta_push_at' => now()->toISOString(),
            ],
        ],
    ]);

    Route::middleware([StartSession::class, EnsurePosSystemUserAuthenticated::class, EnsureStoreBootstrapReady::class])
        ->get('/_test/bootstrap-ready/{tenant}', fn () => response('ok'))
        ->name('test.bootstrap.ready');

    $response = $this->get("/_test/bootstrap-ready/{$store->id}");

    $response->assertOk();
    $response->assertSee('ok');
});
