<?php

use App\Models\Store;
use App\Services\CloudSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns sync errors only for the selected store', function (): void {
    $storeA = Store::query()->create(['name' => 'Store A', 'server_id' => 11]);
    $storeB = Store::query()->create(['name' => 'Store B', 'server_id' => 22]);

    DB::table('customers')->insert([
        [
            'store_id' => $storeA->id,
            'name' => 'A Customer',
            'sync_state' => 'synced',
            'sync_error' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'store_id' => $storeB->id,
            'name' => 'B Customer',
            'sync_state' => 'failed',
            'sync_error' => 'Customer sync failed for store B',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $overviewA = app(CloudSyncService::class)->getSyncErrorOverview($storeA->id);
    $overviewB = app(CloudSyncService::class)->getSyncErrorOverview($storeB->id);

    expect($overviewA['has_sync_errors'])->toBeFalse();
    expect($overviewA['total_sync_errors'])->toBe(0);
    expect($overviewA['module_errors']['customers'])->toBe(0);

    expect($overviewB['has_sync_errors'])->toBeTrue();
    expect($overviewB['total_sync_errors'])->toBeGreaterThan(0);
    expect($overviewB['module_errors']['customers'])->toBeGreaterThan(0);
    expect(collect($overviewB['error_records'])->pluck('sync_error')->implode(' '))
        ->toContain('Customer sync failed for store B');
});

it('groups product related sync errors under products module', function (): void {
    $store = Store::query()->create(['name' => 'Store A', 'server_id' => 11]);

    DB::table('products')->insert([
        'store_id' => $store->id,
        'name' => 'Paint',
        'sync_state' => 'failed',
        'sync_error' => 'Product sync failed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = (int) DB::table('products')
        ->where('store_id', $store->id)
        ->where('name', 'Paint')
        ->value('id');

    DB::table('variations')->insert([
        'store_id' => $store->id,
        'product_id' => $productId,
        'description' => '1L',
        'price' => 100,
        'sync_state' => 'failed',
        'sync_error' => 'Variation sync failed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $overview = app(CloudSyncService::class)->getSyncErrorOverview($store->id);

    expect($overview['has_sync_errors'])->toBeTrue();
    expect($overview['module_errors']['products'])->toBeGreaterThanOrEqual(2);
    expect(collect($overview['error_records'])->pluck('table')->all())
        ->toContain('products')
        ->toContain('variations');
});

it('returns outbox failure details only for selected store', function (): void {
    $storeA = Store::query()->create(['name' => 'Store A', 'server_id' => 11]);
    $storeB = Store::query()->create(['name' => 'Store B', 'server_id' => 22]);

    $customerAId = (int) DB::table('customers')->insertGetId([
        'store_id' => $storeA->id,
        'name' => 'A Customer',
        'sync_state' => 'pending',
        'sync_error' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customerBId = (int) DB::table('customers')->insertGetId([
        'store_id' => $storeB->id,
        'name' => 'B Customer',
        'sync_state' => 'pending',
        'sync_error' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('sync_outbox')->insert([
        [
            'entity_type' => 'customers',
            'local_id' => $customerAId,
            'operation' => 'upsert',
            'status' => 'failed',
            'attempts' => 2,
            'error' => 'Customer A outbox failed',
            'payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'entity_type' => 'customers',
            'local_id' => $customerBId,
            'operation' => 'upsert',
            'status' => 'failed',
            'attempts' => 1,
            'error' => 'Customer B outbox failed',
            'payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $outboxA = app(CloudSyncService::class)->getOutboxErrorOverviewForStore((int) $storeA->id);
    $outboxB = app(CloudSyncService::class)->getOutboxErrorOverviewForStore((int) $storeB->id);

    expect($outboxA['total_failed'])->toBe(1);
    expect(collect($outboxA['records'])->pluck('error')->implode(' '))
        ->toContain('Customer A outbox failed')
        ->not->toContain('Customer B outbox failed');

    expect($outboxB['total_failed'])->toBe(1);
    expect(collect($outboxB['records'])->pluck('error')->implode(' '))
        ->toContain('Customer B outbox failed')
        ->not->toContain('Customer A outbox failed');
});
