<?php

use App\Models\Store;
use App\Services\CloudSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('builds module stats only for selected store', function (): void {
    $storeA = Store::query()->create(['name' => 'Store A', 'server_id' => 11]);
    $storeB = Store::query()->create(['name' => 'Store B', 'server_id' => 22]);

    DB::table('customers')->insert([
        ['store_id' => $storeA->id, 'name' => 'A Customer', 'created_at' => now(), 'updated_at' => now(), 'sync_state' => 'synced'],
        ['store_id' => $storeB->id, 'name' => 'B Customer', 'created_at' => now(), 'updated_at' => now(), 'sync_state' => 'failed'],
    ]);

    $statsA = app(CloudSyncService::class)->getModuleStats($storeA->id);
    $statsB = app(CloudSyncService::class)->getModuleStats($storeB->id);

    expect($statsA)->not->toHaveKey('stores');
    expect($statsA['customers']['total'])->toBe(1);
    expect($statsA['customers']['synced'])->toBe(1);
    expect($statsA['customers']['errors'])->toBe(0);

    expect($statsB)->not->toHaveKey('stores');
    expect($statsB['customers']['total'])->toBe(1);
    expect($statsB['customers']['synced'])->toBe(0);
    expect($statsB['customers']['errors'])->toBeGreaterThan(0);
});

it('counts products module by products table only', function (): void {
    $storeA = Store::query()->create(['name' => 'Store A', 'server_id' => 11]);
    $storeB = Store::query()->create(['name' => 'Store B', 'server_id' => 22]);

    DB::table('products')->insert([
        ['store_id' => $storeA->id, 'name' => 'P1', 'created_at' => now(), 'updated_at' => now(), 'sync_state' => 'synced'],
        ['store_id' => $storeA->id, 'name' => 'P2', 'created_at' => now(), 'updated_at' => now(), 'sync_state' => 'pending'],
        ['store_id' => $storeB->id, 'name' => 'P3', 'created_at' => now(), 'updated_at' => now(), 'sync_state' => 'synced'],
    ]);

    $service = app(CloudSyncService::class);
    $statsA = $service->getModuleStats($storeA->id);
    $modules = $service->getSyncModules();

    expect($statsA['products']['total'])->toBe(2);
    expect($statsA['products']['synced'])->toBe(1);
    expect($modules['products']['resources'])->toContain('variations');
    expect($modules['products']['resources'])->toContain('stocks');
});
