<?php

use App\Models\Store;
use App\Models\SyncOutbox;
use App\Services\CloudSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SmartTill\Core\Models\Country;
use SmartTill\Core\Models\Currency;
use SmartTill\Core\Models\Timezone;

uses(RefreshDatabase::class);

it('includes sale dependency resources when syncing sales module', function (): void {
    $modules = app(CloudSyncService::class)->getSyncModules();

    expect($modules['sales']['resources'])->toBe([
        'customers',
        'products',
        'variations',
        'stocks',
        'sales',
        'sale_variation',
        'sale_preparable_items',
    ]);
});

it('includes transactions in customer supplier and product module sync resources', function (): void {
    $modules = app(CloudSyncService::class)->getSyncModules();

    expect($modules['customers']['resources'])->toContain('transactions');
    expect($modules['suppliers']['resources'])->toContain('transactions');
    expect($modules['products']['resources'])->toContain('transactions');
});

it('maps linked store country currency and timezone by stable values from cloud payload', function (): void {
    $country = Country::query()->create([
        'name' => 'Pakistan',
        'code' => 'PK',
        'code3' => 'PAK',
    ]);
    $currency = Currency::query()->create([
        'name' => 'Pakistani Rupee',
        'code' => 'PKR',
        'decimal_places' => 2,
    ]);
    $timezone = Timezone::query()->create([
        'name' => 'Asia/Karachi',
        'offset' => '+05:00',
    ]);

    $localStore = app(CloudSyncService::class)->ensureLocalStoreFromServer([
        'id' => 99,
        'name' => 'Cloud Store',
        'country' => ['code' => 'PK', 'name' => 'Pakistan'],
        'currency' => ['code' => 'PKR'],
        'timezone' => ['name' => 'Asia/Karachi'],
    ]);

    expect($localStore)->not->toBeNull();
    expect((int) $localStore->country_id)->toBe((int) $country->id);
    expect((int) $localStore->currency_id)->toBe((int) $currency->id);
    expect((int) $localStore->timezone_id)->toBe((int) $timezone->id);
});

it('does not overwrite local store region ids with server numeric ids during store pull sync', function (): void {
    $country = Country::query()->create([
        'name' => 'Pakistan',
        'code' => 'PK',
        'code3' => 'PAK',
    ]);
    $currency = Currency::query()->create([
        'name' => 'Pakistani Rupee',
        'code' => 'PKR',
        'decimal_places' => 2,
    ]);
    $timezone = Timezone::query()->create([
        'name' => 'Asia/Karachi',
        'offset' => '+05:00',
    ]);

    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone_id' => $timezone->id,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/stores')) {
            return Http::response([
                'data' => [[
                    'id' => 1,
                    'name' => 'Main Store Updated',
                    'country_id' => 133,
                    'currency_id' => 109,
                    'timezone_id' => 244,
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();
    $this->assertDatabaseHas('stores', [
        'id' => $store->id,
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone_id' => $timezone->id,
    ]);
});

it('does not map pull foreign keys by raw local ids when related table has no server_id', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
    ]);

    DB::table('users')->insert([
        'id' => 1,
        'name' => 'Local User',
        'email' => 'local@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/model_activities')) {
            return Http::response([
                'data' => [[
                    'id' => 900,
                    'activityable_type' => 'SmartTill\\Core\\Models\\Sale',
                    'activityable_id' => 55,
                    'created_by' => 1,
                    'updated_by' => 1,
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();
    $this->assertDatabaseHas('model_activities', [
        'server_id' => 900,
        'activityable_type' => 'SmartTill\\Core\\Models\\Sale',
        'activityable_id' => 55,
        'created_by' => null,
        'updated_by' => null,
    ]);
});

it('does not push local region foreign key ids for store sync payloads', function (): void {
    $country = Country::query()->create([
        'name' => 'Pakistan',
        'code' => 'PK',
        'code3' => 'PAK',
    ]);
    $currency = Currency::query()->create([
        'name' => 'Pakistani Rupee',
        'code' => 'PKR',
        'decimal_places' => 2,
    ]);
    $timezone = Timezone::query()->create([
        'name' => 'Asia/Karachi',
        'offset' => '+05:00',
    ]);

    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone_id' => $timezone->id,
        'sync_state' => 'pending',
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/stores/upsert')) {
            return Http::response(['results' => [['index' => 0, 'status' => 'synced']]], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/api/pos/v1/stores/1/sync/stores/upsert')) {
            return false;
        }

        $rows = $request->data()['rows'] ?? [];
        if (count($rows) !== 1) {
            return false;
        }

        return array_key_exists('country_id', $rows[0])
            && array_key_exists('currency_id', $rows[0])
            && array_key_exists('timezone_id', $rows[0])
            && $rows[0]['country_id'] === null
            && $rows[0]['currency_id'] === null
            && $rows[0]['timezone_id'] === null;
    });
});

it('never pushes cloud_store_sync outbox rows as sync resources', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
    ]);

    SyncOutbox::query()->create([
        'entity_type' => 'cloud_store_sync',
        'local_id' => (int) $store->id,
        'operation' => 'pull',
        'payload' => null,
        'status' => 'pending',
        'attempts' => 0,
        'error' => null,
    ]);

    Http::fake(fn () => Http::response([
        'data' => [],
        'meta' => ['current_page' => 1, 'last_page' => 1],
    ], 200));

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/sync/cloud_store_sync/upsert');
    });
});

it('hides internal cloud_store_sync failures from outbox error overview', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
    ]);

    SyncOutbox::query()->create([
        'entity_type' => 'cloud_store_sync',
        'local_id' => (int) $store->id,
        'operation' => 'pull',
        'payload' => null,
        'status' => 'failed',
        'attempts' => 1,
        'error' => 'Unsupported resource.',
    ]);

    $overview = app(CloudSyncService::class)->getOutboxErrorOverviewForStore((int) $store->id);

    expect($overview['total_failed'])->toBe(0);
    expect($overview['records'])->toBeArray()->toHaveCount(0);
});

it('merges unit dimensions by unique name while syncing', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
    ]);

    DB::table('unit_dimensions')->insert([
        'name' => 'Mass',
        'store_id' => null,
        'base_unit_id' => null,
        'server_id' => null,
        'sync_state' => 'pending',
        'synced_at' => null,
        'sync_error' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/unit_dimensions')) {
            return Http::response([
                'data' => [
                    [
                        'id' => 1,
                        'name' => 'Mass',
                        'base_unit_id' => 85,
                    ],
                ],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                ],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/')) {
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

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();
    $this->assertDatabaseHas('unit_dimensions', [
        'name' => 'Mass',
        'store_id' => $store->id,
        'server_id' => 1,
        'sync_state' => 'synced',
    ]);
});

it('stores same unit dimension name separately per store', function (): void {
    $storeA = Store::query()->create([
        'name' => 'Store A',
        'server_id' => 1,
    ]);

    $storeB = Store::query()->create([
        'name' => 'Store B',
        'server_id' => 2,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/unit_dimensions')) {
            return Http::response([
                'data' => [[
                    'id' => 1,
                    'name' => 'Mass',
                    'base_unit_id' => 85,
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/2/sync/unit_dimensions')) {
            return Http::response([
                'data' => [[
                    'id' => 1,
                    'name' => 'Mass',
                    'base_unit_id' => 85,
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $service = app(CloudSyncService::class);
    $resultA = $service->syncNow('https://cloud.example.test', 'token', $storeA);
    $resultB = $service->syncNow('https://cloud.example.test', 'token', $storeB);

    expect($resultA['ok'])->toBeTrue();
    expect($resultB['ok'])->toBeTrue();
    expect(DB::table('unit_dimensions')->where('name', 'Mass')->count())->toBe(2);
    $this->assertDatabaseHas('unit_dimensions', ['name' => 'Mass', 'store_id' => $storeA->id]);
    $this->assertDatabaseHas('unit_dimensions', ['name' => 'Mass', 'store_id' => $storeB->id]);
});

it('merges customers by store and phone and prevents unique conflicts during pull', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
    ]);

    DB::table('customers')->insert([
        [
            'store_id' => $store->id,
            'name' => 'Server Linked Customer',
            'phone' => '99887766',
            'server_id' => 67,
            'sync_state' => 'synced',
            'synced_at' => now(),
            'sync_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'store_id' => $store->id,
            'name' => 'Local Customer',
            'phone' => '87123456',
            'server_id' => null,
            'sync_state' => 'pending',
            'synced_at' => null,
            'sync_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/customers')) {
            return Http::response([
                'data' => [[
                    'id' => 67,
                    'store_id' => 1,
                    'name' => 'jjjj',
                    'phone' => '87123456',
                    'email' => null,
                    'status' => 'active',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();
    expect(DB::table('customers')->where('store_id', $store->id)->where('phone', '87123456')->count())->toBe(1);
    expect(DB::table('customers')->where('server_id', 67)->count())->toBe(1);

    $this->assertDatabaseHas('customers', [
        'store_id' => $store->id,
        'server_id' => 67,
        'phone' => '87123456',
        'name' => 'jjjj',
    ]);
});

it('maps foreign keys from server ids to local ids during pull sync', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/brands')) {
            return Http::response([
                'data' => [[
                    'id' => 157,
                    'store_id' => 1,
                    'name' => 'Brand A',
                    'status' => 'active',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/categories')) {
            return Http::response([
                'data' => [[
                    'id' => 44,
                    'store_id' => 1,
                    'name' => 'Category A',
                    'status' => 'active',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/products')) {
            return Http::response([
                'data' => [[
                    'id' => 59115,
                    'store_id' => 1,
                    'brand_id' => 157,
                    'category_id' => 44,
                    'name' => 'Super Emulsion',
                    'status' => 'active',
                    'has_variations' => 1,
                    'is_preparable' => 0,
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();

    $brandId = DB::table('brands')->where('server_id', 157)->value('id');
    $categoryId = DB::table('categories')->where('server_id', 44)->value('id');

    expect($brandId)->not->toBeNull();
    expect($categoryId)->not->toBeNull();

    $this->assertDatabaseHas('products', [
        'server_id' => 59115,
        'store_id' => $store->id,
        'brand_id' => (int) $brandId,
        'category_id' => (int) $categoryId,
        'name' => 'Super Emulsion',
    ]);
});

it('merges attributes by store and name while syncing', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 3,
    ]);

    DB::table('attributes')->insert([
        'store_id' => $store->id,
        'name' => 'Color',
        'deleted_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        'server_id' => null,
        'sync_state' => 'pending',
        'synced_at' => null,
        'sync_error' => null,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/3/sync/attributes')) {
            return Http::response([
                'data' => [[
                    'id' => 1558,
                    'store_id' => 3,
                    'name' => 'Color',
                    'deleted_at' => null,
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/3/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();
    expect(DB::table('attributes')->where('store_id', $store->id)->where('name', 'Color')->count())->toBe(1);
    $this->assertDatabaseHas('attributes', [
        'store_id' => $store->id,
        'name' => 'Color',
        'server_id' => 1558,
        'sync_state' => 'synced',
    ]);
});

it('maps product attributes foreign keys to local ids while syncing', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 3,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/3/sync/products')) {
            return Http::response([
                'data' => [[
                    'id' => 59115,
                    'store_id' => 3,
                    'name' => 'Super Emulsion',
                    'has_variations' => 1,
                    'is_preparable' => 0,
                    'status' => 'active',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/3/sync/attributes')) {
            return Http::response([
                'data' => [[
                    'id' => 1554,
                    'store_id' => 3,
                    'name' => 'Color',
                    'deleted_at' => null,
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/3/sync/product_attributes')) {
            return Http::response([
                'data' => [[
                    'id' => 15704,
                    'product_id' => 59115,
                    'attribute_id' => 1554,
                    'values' => json_encode(['White', 'Off White']),
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/3/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();

    $localProductId = DB::table('products')->where('server_id', 59115)->value('id');
    $localAttributeId = DB::table('attributes')->where('server_id', 1554)->value('id');

    expect($localProductId)->not->toBeNull();
    expect($localAttributeId)->not->toBeNull();

    $this->assertDatabaseHas('product_attributes', [
        'server_id' => 15704,
        'product_id' => (int) $localProductId,
        'attribute_id' => (int) $localAttributeId,
    ]);
});

it('does not sync roles and permissions entities in pos', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
    ]);

    SyncOutbox::query()->create([
        'entity_type' => 'permissions',
        'operation' => 'upsert',
        'payload' => json_encode(['name' => 'View Sales']),
        'status' => 'pending',
        'attempts' => 0,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/permissions') || str_contains($url, '/api/pos/v1/stores/1/sync/roles')) {
            return Http::response(['data' => [['id' => 1, 'name' => 'View Sales']]], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/sync/permissions')
            || str_contains($request->url(), '/sync/roles')
            || str_contains($request->url(), '/sync/user_role')
            || str_contains($request->url(), '/sync/role_has_permissions')
            || str_contains($request->url(), '/sync/invitations');
    });

    $this->assertDatabaseHas('sync_outbox', [
        'entity_type' => 'permissions',
        'status' => 'synced',
    ]);
});

it('maps sale preparable item foreign keys to local ids while syncing', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 5,
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/5/sync/sales')) {
            return Http::response([
                'data' => [[
                    'id' => 230,
                    'store_id' => 5,
                    'reference' => '230',
                    'status' => 'completed',
                    'payment_status' => 'paid',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/5/sync/variations')) {
            return Http::response([
                'data' => [
                    ['id' => 654, 'store_id' => 5, 'product_id' => 9001, 'description' => 'Var A', 'unit_id' => null],
                    ['id' => 142462, 'store_id' => 5, 'product_id' => 9001, 'description' => 'Prep Var', 'unit_id' => null],
                ],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/5/sync/products')) {
            return Http::response([
                'data' => [[
                    'id' => 9001,
                    'store_id' => 5,
                    'name' => 'Product A',
                    'has_variations' => 1,
                    'is_preparable' => 0,
                    'status' => 'active',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/5/sync/stocks')) {
            return Http::response([
                'data' => [[
                    'id' => 654,
                    'store_id' => 5,
                    'variation_id' => 654,
                    'barcode' => 'STK-654',
                    'quantity' => 100,
                    'supplier_price' => 45000,
                    'tax_percentage' => 0,
                    'status' => 'active',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/5/sync/sale_preparable_items')) {
            return Http::response([
                'data' => [[
                    'id' => 1,
                    'sale_id' => 230,
                    'sequence' => 0,
                    'preparable_variation_id' => 142462,
                    'variation_id' => 654,
                    'stock_id' => 654,
                    'quantity' => 3,
                    'unit_price' => 50000,
                    'tax' => 0,
                    'discount' => 0,
                    'discount_type' => null,
                    'discount_percentage' => null,
                    'total' => 150000,
                    'supplier_price' => 45000,
                    'supplier_total' => 135000,
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/5/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);
    expect($result['ok'])->toBeTrue();

    $localSaleId = DB::table('sales')->where('server_id', 230)->value('id');
    $localVariationId = DB::table('variations')->where('server_id', 654)->value('id');
    $localPreparableVariationId = DB::table('variations')->where('server_id', 142462)->value('id');
    $localStockId = DB::table('stocks')->where('server_id', 654)->value('id');

    expect($localSaleId)->not->toBeNull();
    expect($localVariationId)->not->toBeNull();
    expect($localPreparableVariationId)->not->toBeNull();
    expect($localStockId)->not->toBeNull();

    $this->assertDatabaseHas('sale_preparable_items', [
        'server_id' => 1,
        'sale_id' => (int) $localSaleId,
        'variation_id' => (int) $localVariationId,
        'preparable_variation_id' => (int) $localPreparableVariationId,
        'stock_id' => (int) $localStockId,
    ]);
});

it('pull syncs sale variation rows when the payload has no id fields', function (): void {
    $store = Store::query()->create([
        'name' => 'Main Store',
        'server_id' => 1,
    ]);

    $localSaleId = DB::table('sales')->insertGetId([
        'store_id' => $store->id,
        'server_id' => 10,
        'local_id' => 'DEV001-1',
        'status' => 'completed',
        'payment_status' => 'paid',
        'payment_method' => 'cash',
        'discount_type' => 'flat',
        'freight_fare' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/sale_variation')) {
            return Http::response([
                'data' => [[
                    'sale_id' => 10,
                    'variation_id' => null,
                    'stock_id' => null,
                    'description' => 'Pulled line item',
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'tax' => 0,
                    'discount' => 0,
                    'discount_type' => 'flat',
                    'total' => 1000,
                    'is_preparable' => 0,
                    'local_id' => 'DEV001-2',
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        if (str_contains($url, '/api/pos/v1/stores/1/sync/')) {
            return Http::response([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store, 'sales');

    expect($result['ok'])->toBeTrue();
    $this->assertDatabaseHas('sale_variation', [
        'sale_id' => $localSaleId,
        'local_id' => 'DEV001-2',
        'description' => 'Pulled line item',
        'unit_price' => 1000,
    ]);
});
