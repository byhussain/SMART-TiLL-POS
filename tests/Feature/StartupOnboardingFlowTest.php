<?php

use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use App\Models\User;
use App\Services\PosSystemUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SmartTill\Core\Models\Country;
use SmartTill\Core\Models\Currency;
use SmartTill\Core\Models\Timezone;

uses(RefreshDatabase::class);

it('shows startup screen on first launch', function (): void {
    $response = $this->get(route('startup.index'));

    $response->assertOk();
    $response->assertSee('Continue as Guest');
    $response->assertSee('Cloud Login Coming Soon');
});

it('shows cloud auth screen', function (): void {
    $response = $this->get(route('startup.cloud.form'));

    $response->assertOk();
    $response->assertSee('Connect POS to Cloud');
    $response->assertSee('Cloud Login Coming Soon');
    $response->assertSee('Desktop cloud authentication and store sync setup will be available in a future release.');
});

it('redirects guest flow to startup setup when no store exists', function (): void {
    $response = $this->post(route('startup.guest'));

    $response->assertRedirect(route('startup.guest.setup'));
    $this->assertDatabaseHas('users', [
        'email' => PosSystemUserService::SYSTEM_EMAIL,
    ]);
});

it('skips startup and redirects to dashboard after onboarding', function (): void {
    $store = Store::query()->create(['name' => 'Demo Store']);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'guest',
        'active_store_id' => $store->id,
    ]);

    $response = $this->get(route('startup.index'));

    $response->assertRedirect(route('filament.store.pages.dashboard', ['tenant' => $store->id]));
});

it('rejects currency and timezone that do not belong to selected country', function (): void {
    $countryA = Country::query()->create(['name' => 'Pakistan', 'code' => 'PK']);
    $countryB = Country::query()->create(['name' => 'United Arab Emirates', 'code' => 'AE']);

    $currencyA = Currency::query()->create(['name' => 'Pakistani Rupee', 'code' => 'PKR', 'decimal_places' => 2]);
    $currencyB = Currency::query()->create(['name' => 'UAE Dirham', 'code' => 'AED', 'decimal_places' => 2]);

    $timezoneA = Timezone::query()->create(['name' => 'Asia/Karachi', 'offset' => '+05:00']);
    $timezoneB = Timezone::query()->create(['name' => 'Asia/Dubai', 'offset' => '+04:00']);

    $countryA->currencies()->attach($currencyA->id);
    $countryA->timezones()->attach($timezoneA->id);
    $countryB->currencies()->attach($currencyB->id);
    $countryB->timezones()->attach($timezoneB->id);

    $response = $this->post(route('startup.guest.setup.store'), [
        'name' => 'Demo Store',
        'country_id' => $countryA->id,
        'currency_id' => $currencyB->id,
        'timezone_id' => $timezoneB->id,
    ]);

    $response->assertSessionHasErrors(['currency_id', 'timezone_id']);
    $this->assertDatabaseMissing('stores', ['name' => 'Demo Store']);
});

it('shows API login error message from cloud server', function (): void {
    config()->set('services.pos_cloud.base_url', 'https://cloud.example.test');

    Http::fake([
        'https://cloud.example.test/api/pos/login' => Http::response([
            'message' => 'Invalid credentials provided.',
        ], 422),
    ]);

    $response = $this->postJson(route('startup.cloud.login'), [
        'email' => 'user@example.com',
        'password' => 'secret',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Invalid credentials provided.');
});

it('shows network connection error when cloud server is unreachable', function (): void {
    config()->set('services.pos_cloud.base_url', 'https://cloud.example.test');

    Http::fake(function () {
        throw new ConnectionException('cURL error 7: Failed to connect');
    });

    $response = $this->postJson(route('startup.cloud.login'), [
        'email' => 'user@example.com',
        'password' => 'secret',
    ]);

    $response->assertStatus(422);
    $response->assertJsonPath('message', 'Unable to connect to cloud server. cURL error 7: Failed to connect');
});

it('logs in first, then shows cloud stores API error on stores step', function (): void {
    config()->set('services.pos_cloud.base_url', 'https://cloud.example.test');

    Http::fake([
        'https://cloud.example.test/api/pos/login' => Http::response([
            'token' => 'test-token',
            'user' => ['id' => 99],
        ], 200),
    ]);

    $response = $this->postJson(route('startup.cloud.login'), [
        'email' => 'user@example.com',
        'password' => 'secret',
    ]);

    $response->assertOk();
    $response->assertJsonPath('redirect', route('startup.cloud.stores'));

    Http::fake([
        'https://cloud.example.test/api/pos/stores' => Http::response([
            'message' => 'Store lookup failed for this user.',
        ], 500),
    ]);

    $storesResponse = $this->getJson(route('startup.cloud.stores'));
    $storesResponse->assertStatus(422);
    $storesResponse->assertJsonPath('message', 'Store lookup failed for this user.');
});

it('returns json response for cloud login when accept header is json', function (): void {
    config()->set('services.pos_cloud.base_url', 'https://cloud.example.test');

    Http::fake([
        'https://cloud.example.test/api/pos/login' => Http::response([
            'token' => 'test-token',
            'user' => ['id' => 99],
        ], 200),
        'https://cloud.example.test/api/pos/stores' => Http::response([
            'data' => [
                ['id' => 1, 'name' => 'Main Store'],
            ],
        ], 200),
    ]);

    $response = $this->postJson(route('startup.cloud.login'), [
        'email' => 'user@example.com',
        'password' => 'secret',
    ]);

    $response->assertOk();
    $response->assertJsonPath('message', 'Cloud login successful.');
    $response->assertJsonPath('redirect', route('startup.cloud.stores'));

    $state = AppRuntimeState::query()->find(1);
    expect($state)->not->toBeNull();
    expect($state?->cloud_token_present)->toBeTrue();
    expect($state?->cloud_token)->toBe('test-token');
    expect($state?->cloud_base_url)->toBe('https://cloud.example.test');
    expect($state?->mode)->toBe('cloud');
});

it('returns json validation errors for cloud login', function (): void {
    config()->set('services.pos_cloud.base_url', 'https://cloud.example.test');

    $response = $this->postJson(route('startup.cloud.login'), [
        'email' => 'not-an-email',
        'password' => '',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email', 'password']);
});

it('returns json stores list after cloud login', function (): void {
    config()->set('services.pos_cloud.base_url', 'https://cloud.example.test');

    Http::fake([
        'https://cloud.example.test/api/pos/login' => Http::response([
            'token' => 'test-token',
            'user' => ['id' => 99],
        ], 200),
        'https://cloud.example.test/api/pos/stores' => Http::response([
            'data' => [
                ['id' => 1, 'name' => 'Main Store'],
                ['id' => 2, 'name' => 'Branch Store'],
            ],
        ], 200),
    ]);

    $loginResponse = $this->postJson(route('startup.cloud.login'), [
        'email' => 'user@example.com',
        'password' => 'secret',
    ]);
    $loginResponse->assertOk();

    $storesResponse = $this->getJson(route('startup.cloud.stores'));
    $storesResponse->assertOk();
    $storesResponse->assertJsonCount(2, 'data');
    $storesResponse->assertJsonPath('data.0.name', 'Main Store');
});

it('selects cloud store via json and returns bootstrap redirect', function (): void {
    config()->set('services.pos_cloud.base_url', 'https://cloud.example.test');

    Queue::fake();

    $this->withSession([
        'pos_cloud_token' => 'test-token',
        'pos_cloud_base_url' => 'https://cloud.example.test',
        'pos_cloud_user_id' => 99,
        'pos_cloud_stores' => [
            ['id' => 77, 'name' => 'Cloud Store 77', 'country_id' => null, 'currency_id' => null, 'timezone_id' => null],
            ['id' => 78, 'name' => 'Cloud Store 78', 'country_id' => null, 'currency_id' => null, 'timezone_id' => null],
        ],
    ]);

    $response = $this->postJson(route('startup.cloud.select-store'), [
        'server_store_id' => 77,
    ]);

    $localStore = Store::query()->where('server_id', 77)->first();
    $anotherLocalStore = Store::query()->where('server_id', 78)->first();
    expect($localStore)->not->toBeNull();
    expect($anotherLocalStore)->not->toBeNull();

    $response->assertOk();
    $response->assertJsonPath('message', 'Cloud store connected successfully. Store data download has started.');
    $response->assertJsonPath('redirect', route('startup.cloud.bootstrap', ['store' => $localStore?->id]));

    $state = AppRuntimeState::query()->find(1);
    expect($state?->has_completed_onboarding)->toBeTrue();
    expect($state?->mode)->toBe('cloud');
    expect($state?->cloud_user_id)->toBe(99);
    expect($state?->active_store_id)->toBe($localStore?->id);

    $systemUser = User::query()->where('email', PosSystemUserService::SYSTEM_EMAIL)->first();
    expect($systemUser)->not->toBeNull();
    expect($systemUser?->stores()->whereIn('stores.id', [$localStore?->id, $anotherLocalStore?->id])->count())->toBe(2);

    Queue::assertPushed(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($localStore): bool {
        return $job->storeId === (int) $localStore?->id;
    });
});

it('redirects startup index to bootstrap screen while a cloud store is still installing', function (): void {
    $store = Store::query()->create([
        'name' => 'Cloud Store',
        'server_id' => 11,
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
                'bootstrap_progress_percent' => 66,
                'bootstrap_progress_label' => 'Installing variations',
                'bootstrap_generation' => 'abc',
                'last_delta_pull_at' => null,
                'last_delta_push_at' => null,
            ],
        ],
    ]);

    $response = $this->get(route('startup.index'));

    $response->assertRedirect(route('startup.cloud.bootstrap', ['store' => $store->id]));
});

it('shows bootstrap progress page while a cloud store is still installing', function (): void {
    $store = Store::query()->create([
        'name' => 'Cloud Store',
        'server_id' => 11,
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
                'bootstrap_progress_percent' => 66,
                'bootstrap_progress_label' => 'Installing variations',
                'bootstrap_generation' => 'abc',
                'last_delta_pull_at' => null,
                'last_delta_push_at' => null,
            ],
        ],
    ]);

    $response = $this->get(route('startup.cloud.bootstrap', ['store' => $store->id]));

    $response->assertOk();
    $response->assertSee('Preparing Cloud Store for offline POS use.', escape: false);
    $response->assertSee('Large stores can take a few minutes on the first load.');
    $response->assertSee('refreshStatus', escape: false);
});
