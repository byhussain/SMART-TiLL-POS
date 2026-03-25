<?php

use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use App\Observers\DispatchCloudSyncObserver;
use App\Services\CloudSyncService;
use App\Services\DeviceIdentifierService;
use App\Services\LocalIdentifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SmartTill\Core\Models\Customer;
use SmartTill\Core\Models\Sale;
use SmartTill\Core\Models\StoreSetting;

uses(RefreshDatabase::class);

it('dispatches cloud sync job for cloud-connected local model changes', function (): void {
    app()->instance(
        DeviceIdentifierService::class,
        new class extends DeviceIdentifierService
        {
            public function getPrefix(): string
            {
                return 'ABC123';
            }
        }
    );

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

    Bus::fake();

    $customer = Customer::query()->create([
        'store_id' => $store->id,
        'name' => 'Walk-in Customer',
    ]);

    app(DispatchCloudSyncObserver::class)->created($customer);

    Bus::assertDispatched(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === (int) $store->id
            && $job->action === 'delta'
            && $job->afterCommit === true
            && $job->resource === 'customers';
    });

    $createdCustomer = Customer::query()->findOrFail($customer->id);
    expect((string) $createdCustomer->local_id)->toBe('ABC123-1');
    expect($createdCustomer->reference)->toBeNull();

    $secondCustomer = Customer::query()->create([
        'store_id' => $store->id,
        'name' => 'Walk-in Customer 2',
    ]);

    app(DispatchCloudSyncObserver::class)->created($secondCustomer);

    $secondCustomer = Customer::query()->findOrFail($secondCustomer->id);
    expect((string) $secondCustomer->local_id)->toBe('ABC123-2');
    expect($secondCustomer->reference)->toBeNull();
});

it('dispatches the sales module sync when a local sale changes', function (): void {
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

    Bus::fake();

    $sale = Sale::query()->create([
        'store_id' => $store->id,
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'discount_type' => 'flat',
        'freight_fare' => 0,
        'subtotal' => 1000,
        'tax' => 0,
        'discount' => 0,
        'total' => 1000,
    ]);

    app(DispatchCloudSyncObserver::class)->created($sale);

    Bus::assertDispatched(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === (int) $store->id
            && $job->action === 'delta'
            && $job->module === 'sales'
            && $job->afterCommit === true
            && $job->resource === null;
    });
});

it('resets local id suffix per store for the same device prefix', function (): void {
    app()->instance(
        DeviceIdentifierService::class,
        new class extends DeviceIdentifierService
        {
            public function getPrefix(): string
            {
                return 'ABC123';
            }
        }
    );

    $storeA = Store::query()->create(['name' => 'Store A', 'server_id' => 101]);
    $storeB = Store::query()->create(['name' => 'Store B', 'server_id' => 102]);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'active_store_id' => $storeA->id,
    ]);

    Bus::fake();

    $customerA = Customer::query()->create(['store_id' => $storeA->id, 'name' => 'A']);
    $customerB = Customer::query()->create(['store_id' => $storeB->id, 'name' => 'B']);

    app(DispatchCloudSyncObserver::class)->created($customerA);
    app(DispatchCloudSyncObserver::class)->created($customerB);

    $customerA = Customer::query()->findOrFail($customerA->id);
    $customerB = Customer::query()->findOrFail($customerB->id);

    expect((string) $customerA->local_id)->toBe('ABC123-1');
    expect((string) $customerB->local_id)->toBe('ABC123-1');
});

it('recreates local id sequence table at runtime when missing', function (): void {
    app()->instance(
        DeviceIdentifierService::class,
        new class extends DeviceIdentifierService
        {
            public function getPrefix(): string
            {
                return 'ABC123';
            }
        }
    );

    Schema::dropIfExists('local_id_sequences');

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

    Bus::fake();

    $customer = Customer::query()->create([
        'store_id' => $store->id,
        'name' => 'Runtime Table Restore',
    ]);

    app(DispatchCloudSyncObserver::class)->created($customer);

    $customer = Customer::query()->findOrFail($customer->id);

    expect(Schema::hasTable('local_id_sequences'))->toBeTrue()
        ->and((string) $customer->local_id)->toBe('ABC123-1');
});

it('maintains local id counters per table for the same store and device', function (): void {
    app()->instance(
        DeviceIdentifierService::class,
        new class extends DeviceIdentifierService
        {
            public function getPrefix(): string
            {
                return 'ABC123';
            }
        }
    );

    $service = app(LocalIdentifierService::class);

    expect($service->makeForTable('sales', 1))->toBe('ABC123-1');
    expect($service->makeForTable('customers', 1))->toBe('ABC123-1');
    expect($service->makeForTable('sales', 1))->toBe('ABC123-2');
});

it('does not dispatch full-store sync for settings changes', function (): void {
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

    Bus::fake();

    $setting = StoreSetting::query()->create([
        'store_id' => $store->id,
        'key' => 'TEST_AUTO_SYNC_IGNORE',
        'value' => 'a4',
        'type' => 'string',
    ]);

    app(DispatchCloudSyncObserver::class)->created($setting);

    Bus::assertNotDispatched(SyncCloudStoreData::class);
});

it('pushes pending table rows even when sync outbox is empty', function (): void {
    $store = Store::query()->create([
        'name' => 'Store A',
        'server_id' => 101,
    ]);

    $customerId = DB::table('customers')->insertGetId([
        'store_id' => $store->id,
        'name' => 'Pending Customer',
        'sync_state' => 'pending',
        'sync_error' => 'Old error',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v2/stores/101/delta/upsert')) {
            return Http::response([
                'message' => 'Delta upsert completed.',
                'resources' => [[
                    'resource' => 'customers',
                    'results' => [
                        ['index' => 0, 'status' => 'synced'],
                    ],
                ]],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v2/stores/101/delta/ack')) {
            return Http::response(['message' => 'ok'], 200);
        }

        if (str_contains($url, '/api/pos/v2/stores/101/delta')) {
            return Http::response([
                'data' => [],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/101/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store, 'customers');

    expect($result['ok'])->toBeTrue();
    $this->assertDatabaseHas('customers', [
        'id' => $customerId,
        'sync_state' => 'synced',
        'sync_error' => null,
    ]);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/pos/v2/stores/101/delta/upsert')) {
            return false;
        }

        $resources = $request->data()['resources'] ?? [];
        $rows = $resources[0]['rows'] ?? [];

        return count($rows) === 1
            && ! array_key_exists('id', $rows[0])
            && is_string($rows[0]['local_id'] ?? null)
            && preg_match('/^[A-Z0-9]{6}-\d+$/', (string) $rows[0]['local_id']) === 1
            && ! filled($rows[0]['reference'] ?? null);
    });
});
