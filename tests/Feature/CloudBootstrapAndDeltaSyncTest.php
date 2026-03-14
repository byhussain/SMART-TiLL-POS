<?php

use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use App\Services\CloudSyncService;
use App\Services\RuntimeStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues bootstrap sync when the active store has not been bootstrapped yet', function (): void {
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
        'active_store_id' => $store->id,
        'bootstrap_status' => 'not_started',
        'bootstrap_progress_percent' => 0,
        'store_sync_states' => [],
    ]);

    Queue::fake();

    $this->postJson(route('startup.cloud.sync-now'))
        ->assertOk()
        ->assertJsonPath('action', 'bootstrap');

    Queue::assertPushed(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === (int) $store->id && $job->action === 'bootstrap';
    });
});

it('queues delta sync when the active store is already bootstrapped', function (): void {
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
        'active_store_id' => $store->id,
        'bootstrap_status' => 'ready',
        'bootstrap_progress_percent' => 100,
        'store_sync_states' => [
            (string) $store->id => [
                'bootstrap_status' => 'ready',
                'bootstrap_progress_percent' => 100,
                'bootstrap_progress_label' => 'Store data is ready.',
                'bootstrap_generation' => 'generation-1',
                'last_delta_pull_at' => null,
                'last_delta_push_at' => null,
            ],
        ],
    ]);

    Queue::fake();

    $this->postJson(route('startup.cloud.sync-now'))
        ->assertOk()
        ->assertJsonPath('action', 'delta');

    Queue::assertPushed(SyncCloudStoreData::class, function (SyncCloudStoreData $job) use ($store): bool {
        return $job->storeId === (int) $store->id && $job->action === 'delta';
    });
});

it('keeps local pending rows when delta pull contains a newer cloud version', function (): void {
    $store = Store::query()->create([
        'name' => 'Cloud Store',
        'server_id' => 11,
    ]);

    DB::table('customers')->insert([
        'store_id' => $store->id,
        'server_id' => 501,
        'local_id' => 'ABC123-1',
        'name' => 'Local Pending Name',
        'phone' => '03000000111',
        'sync_state' => 'pending',
        'created_at' => now()->subHour(),
        'updated_at' => now()->subMinute(),
    ]);

    app(RuntimeStateService::class)->updateStoreSyncState((int) $store->id, [
        'bootstrap_status' => 'ready',
        'last_delta_pull_at' => now()->subHours(2)->toISOString(),
    ]);

    Http::fake([
        'https://cloud.example.test/api/pos/user' => Http::response(['id' => 1], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta*' => Http::response([
            'data' => [[
                'resource' => 'customers',
                'rows' => [[
                    'id' => 900,
                    'server_id' => 501,
                    'local_id' => 'ABC123-1',
                    'store_id' => 11,
                    'name' => 'Cloud Newer Name',
                    'phone' => '03000000111',
                    'updated_at' => now()->toISOString(),
                    'created_at' => now()->subHour()->toISOString(),
                ]],
                'tombstones' => [],
                'cursor' => [
                    'updated_at' => now()->toISOString(),
                    'id' => 900,
                ],
                'has_more' => false,
            ]],
        ], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta/upsert' => Http::response([
            'resources' => [[
                'resource' => 'customers',
                'results' => [[
                    'index' => 0,
                    'status' => 'synced',
                ]],
            ]],
        ], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta/ack' => Http::response(['message' => 'ok'], 200),
    ]);

    $result = app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, null, 'customers');

    expect($result['ok'])->toBeTrue();
    $this->assertDatabaseHas('customers', [
        'store_id' => $store->id,
        'server_id' => 501,
        'name' => 'Local Pending Name',
        'sync_state' => 'synced',
    ]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/pos/v2/stores/11/delta/upsert'));
});

it('retries deferred sale variation rows after dependent sales are imported', function (): void {
    $store = Store::query()->create([
        'id' => 3,
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
        'active_store_id' => $store->id,
        'bootstrap_status' => 'ready',
        'bootstrap_progress_percent' => 100,
        'store_sync_states' => [
            (string) $store->id => [
                'bootstrap_status' => 'ready',
                'bootstrap_progress_percent' => 100,
                'bootstrap_progress_label' => 'Store data is ready.',
                'bootstrap_generation' => 'generation-1',
                'last_delta_pull_at' => now()->subHours(2)->toISOString(),
                'last_delta_push_at' => null,
            ],
        ],
    ]);

    DB::table('products')->insert([
        'id' => 1,
        'store_id' => $store->id,
        'server_id' => 201,
        'name' => 'Test Product',
        'status' => 'active',
        'has_variations' => 1,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    DB::table('variations')->insert([
        'id' => 1,
        'store_id' => $store->id,
        'product_id' => 1,
        'server_id' => 301,
        'description' => 'Test Variation',
        'price' => 10000,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    DB::table('stocks')->insert([
        'id' => 1,
        'variation_id' => 1,
        'server_id' => 401,
        'barcode' => 'BOOTSTRAP-STOCK-1',
        'stock' => 5,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    Http::fake([
        'https://cloud.example.test/api/pos/user' => Http::response(['id' => 1], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta*' => Http::response([
            'data' => [
                [
                    'resource' => 'sale_variation',
                    'rows' => [[
                        'sale_id' => 701,
                        'variation_id' => 301,
                        'stock_id' => 401,
                        'description' => 'Test Variation',
                        'quantity' => 2,
                        'unit_price' => 10000,
                        'tax' => 0,
                        'discount' => 0,
                        'total' => 20000,
                        'supplier_price' => 5000,
                        'supplier_total' => 10000,
                        'is_preparable' => 0,
                        'created_at' => now()->subHour()->toISOString(),
                        'updated_at' => now()->toISOString(),
                    ]],
                    'tombstones' => [],
                    'cursor' => [
                        'updated_at' => now()->toISOString(),
                        'id' => null,
                    ],
                    'has_more' => false,
                ],
                [
                    'resource' => 'sales',
                    'rows' => [[
                        'id' => 701,
                        'store_id' => 11,
                        'customer_id' => null,
                        'subtotal' => 20000,
                        'tax' => 0,
                        'discount' => 0,
                        'discount_type' => 'flat',
                        'freight_fare' => 0,
                        'total' => 20000,
                        'status' => 'completed',
                        'payment_status' => 'paid',
                        'payment_method' => 'cash',
                        'use_fbr' => 0,
                        'paid_at' => now()->subHour()->toISOString(),
                        'created_at' => now()->subHour()->toISOString(),
                        'updated_at' => now()->toISOString(),
                    ]],
                    'tombstones' => [],
                    'cursor' => [
                        'updated_at' => now()->toISOString(),
                        'id' => 701,
                    ],
                    'has_more' => false,
                ],
            ],
        ], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta/upsert' => Http::response([
            'resources' => [],
        ], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta/ack' => Http::response(['message' => 'ok'], 200),
    ]);

    $result = app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();

    $localSaleId = DB::table('sales')
        ->where('server_id', 701)
        ->value('id');

    expect($localSaleId)->not()->toBeNull();

    $this->assertDatabaseHas('sale_variation', [
        'sale_id' => (int) $localSaleId,
        'variation_id' => 1,
        'stock_id' => 1,
        'supplier_total' => 10000,
    ]);
});

it('maps variation transaction morph ids and types to local values during delta pull', function (): void {
    $store = Store::query()->create([
        'id' => 3,
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
        'active_store_id' => $store->id,
        'bootstrap_status' => 'ready',
        'bootstrap_progress_percent' => 100,
        'store_sync_states' => [
            (string) $store->id => [
                'bootstrap_status' => 'ready',
                'bootstrap_progress_percent' => 100,
                'bootstrap_progress_label' => 'Store data is ready.',
                'bootstrap_generation' => 'generation-1',
                'last_delta_pull_at' => now()->subHours(2)->toISOString(),
                'last_delta_push_at' => null,
            ],
        ],
    ]);

    DB::table('products')->insert([
        'id' => 1,
        'store_id' => $store->id,
        'server_id' => 201,
        'name' => 'Test Product',
        'status' => 'active',
        'has_variations' => 1,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    DB::table('variations')->insert([
        'id' => 1,
        'store_id' => $store->id,
        'product_id' => 1,
        'server_id' => 301,
        'description' => 'Test Variation',
        'price' => 10000,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    Http::fake([
        'https://cloud.example.test/api/pos/user' => Http::response(['id' => 1], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta*' => Http::response([
            'data' => [[
                'resource' => 'transactions',
                'rows' => [[
                    'id' => 901,
                    'store_id' => 11,
                    'transactionable_type' => 'App\\Models\\Variation',
                    'transactionable_id' => 301,
                    'referenceable_type' => null,
                    'referenceable_id' => null,
                    'type' => 'opening_stock',
                    'amount' => 0,
                    'amount_balance' => 0,
                    'quantity' => 5,
                    'quantity_balance' => 5,
                    'note' => 'Imported variation transaction',
                    'meta' => null,
                    'created_at' => now()->subHour()->toISOString(),
                    'updated_at' => now()->toISOString(),
                ]],
                'tombstones' => [],
                'cursor' => [
                    'updated_at' => now()->toISOString(),
                    'id' => 901,
                ],
                'has_more' => false,
            ]],
        ], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta/upsert' => Http::response([
            'resources' => [],
        ], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta/ack' => Http::response(['message' => 'ok'], 200),
    ]);

    $result = app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, null, 'transactions');

    expect($result['ok'])->toBeTrue();

    $this->assertDatabaseHas('transactions', [
        'server_id' => 901,
        'transactionable_type' => 'SmartTill\\Core\\Models\\Variation',
        'transactionable_id' => 1,
        'note' => 'Imported variation transaction',
    ]);
});

it('backfills full sales resources when syncing the sales module', function (): void {
    $store = Store::query()->create([
        'id' => 3,
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
        'active_store_id' => $store->id,
        'bootstrap_status' => 'ready',
        'bootstrap_progress_percent' => 100,
        'store_sync_states' => [
            (string) $store->id => [
                'bootstrap_status' => 'ready',
                'bootstrap_progress_percent' => 100,
                'bootstrap_progress_label' => 'Store data is ready.',
                'bootstrap_generation' => 'generation-1',
                'last_delta_pull_at' => now()->subHours(2)->toISOString(),
                'last_delta_push_at' => null,
            ],
        ],
    ]);

    DB::table('products')->insert([
        'id' => 1,
        'store_id' => $store->id,
        'server_id' => 201,
        'name' => 'Test Product',
        'status' => 'active',
        'has_variations' => 1,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    DB::table('variations')->insert([
        'id' => 1,
        'store_id' => $store->id,
        'product_id' => 1,
        'server_id' => 301,
        'description' => 'Test Variation',
        'price' => 10000,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    DB::table('stocks')->insert([
        'id' => 1,
        'variation_id' => 1,
        'server_id' => 401,
        'barcode' => 'SYNC-STOCK-1',
        'stock' => 5,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    Http::fake([
        'https://cloud.example.test/api/pos/user' => Http::response(['id' => 1], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta*' => Http::response([
            'data' => [],
        ], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta/upsert' => Http::response([
            'resources' => [],
        ], 200),
        'https://cloud.example.test/api/pos/v2/stores/11/delta/ack' => Http::response(['message' => 'ok'], 200),
        'https://cloud.example.test/api/pos/v1/stores/11/sync/sales*' => Http::response([
            'data' => [[
                'id' => 701,
                'store_id' => 11,
                'customer_id' => null,
                'subtotal' => 20000,
                'tax' => 0,
                'discount' => 0,
                'discount_type' => 'flat',
                'freight_fare' => 0,
                'total' => 20000,
                'status' => 'completed',
                'payment_status' => 'paid',
                'payment_method' => 'cash',
                'use_fbr' => 0,
                'paid_at' => now()->subHour()->toISOString(),
                'created_at' => now()->subHour()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
        'https://cloud.example.test/api/pos/v1/stores/11/sync/sale_variation*' => Http::response([
            'data' => [[
                'sale_id' => 701,
                'variation_id' => 301,
                'stock_id' => 401,
                'description' => 'Test Variation',
                'quantity' => 2,
                'unit_price' => 10000,
                'tax' => 0,
                'discount' => 0,
                'total' => 20000,
                'supplier_price' => 5000,
                'supplier_total' => 10000,
                'is_preparable' => 0,
                'created_at' => now()->subHour()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
        'https://cloud.example.test/api/pos/v1/stores/11/sync/sale_preparable_items*' => Http::response([
            'data' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
        'https://cloud.example.test/api/pos/v1/stores/11/sync/customers*' => Http::response([
            'data' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
        'https://cloud.example.test/api/pos/v1/stores/11/sync/products*' => Http::response([
            'data' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
        'https://cloud.example.test/api/pos/v1/stores/11/sync/variations*' => Http::response([
            'data' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
        'https://cloud.example.test/api/pos/v1/stores/11/sync/stocks*' => Http::response([
            'data' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
        'https://cloud.example.test/api/pos/v1/stores/11/sync/transactions*' => Http::response([
            'data' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
    ]);

    $result = app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, 'sales');

    expect($result['ok'])->toBeTrue();
    expect($result['backfilled'])->toBeGreaterThan(0);

    $localSaleId = DB::table('sales')->where('server_id', 701)->value('id');

    $this->assertDatabaseHas('sale_variation', [
        'sale_id' => (int) $localSaleId,
        'variation_id' => 1,
        'stock_id' => 1,
        'supplier_total' => 10000,
    ]);
});

it('retries deferred sales child rows during full resource pulls', function (): void {
    $store = Store::query()->create([
        'id' => 3,
        'name' => 'Cloud Store',
        'server_id' => 11,
    ]);

    DB::table('products')->insert([
        'id' => 1,
        'store_id' => $store->id,
        'server_id' => 201,
        'name' => 'Test Product',
        'status' => 'active',
        'has_variations' => 1,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    DB::table('variations')->insert([
        'id' => 1,
        'store_id' => $store->id,
        'product_id' => 1,
        'server_id' => 301,
        'description' => 'Test Variation',
        'price' => 10000,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    DB::table('stocks')->insert([
        'id' => 1,
        'variation_id' => 1,
        'server_id' => 401,
        'barcode' => 'SYNC-STOCK-1',
        'stock' => 5,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
        'sync_state' => 'synced',
    ]);

    Http::fake([
        'https://cloud.example.test/api/pos/v1/stores/11/sync/sale_variation*' => Http::response([
            'data' => [[
                'sale_id' => 701,
                'variation_id' => 301,
                'stock_id' => 401,
                'description' => 'Deferred Variation',
                'quantity' => 2,
                'unit_price' => 10000,
                'tax' => 0,
                'discount' => 0,
                'total' => 20000,
                'supplier_price' => 5000,
                'supplier_total' => 10000,
                'is_preparable' => 0,
                'created_at' => now()->subHour()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
        'https://cloud.example.test/api/pos/v1/stores/11/sync/sales*' => Http::response([
            'data' => [[
                'id' => 701,
                'store_id' => 11,
                'customer_id' => null,
                'subtotal' => 20000,
                'tax' => 0,
                'discount' => 0,
                'discount_type' => 'flat',
                'freight_fare' => 0,
                'total' => 20000,
                'status' => 'completed',
                'payment_status' => 'paid',
                'payment_method' => 'cash',
                'use_fbr' => 0,
                'paid_at' => now()->subHour()->toISOString(),
                'created_at' => now()->subHour()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]],
            'meta' => ['current_page' => 1, 'last_page' => 1],
        ], 200),
    ]);

    $pulled = invade(app(CloudSyncService::class))->pullAllResources(
        'https://cloud.example.test',
        'token',
        11,
        $store->id,
        ['sale_variation', 'sales'],
    );

    expect($pulled)->toBe(2);

    $localSaleId = DB::table('sales')->where('server_id', 701)->value('id');

    $this->assertDatabaseHas('sale_variation', [
        'sale_id' => (int) $localSaleId,
        'variation_id' => 1,
        'stock_id' => 1,
        'description' => 'Deferred Variation',
    ]);
});
