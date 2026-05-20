<?php

use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use App\Services\CloudSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SmartTill\Core\Models\Customer;

uses(RefreshDatabase::class);

it('persists unresolvable inbound rows for later retry instead of dropping them', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }

        if (str_contains($url, '/delta/ack')) {
            return Http::response(['message' => 'ok'], 200);
        }

        if (str_contains($url, '/delta')) {
            // Server returns a sale_variation row pointing at a sale_id we
            // don't have locally. With the old code this row would be
            // silently dropped after 5 in-memory retries. With the fix,
            // it should land in pending_inbound_sync_rows for next time.
            return Http::response([
                'data' => [
                    [
                        'resource' => 'sale_variation',
                        'rows' => [[
                            'sale_id' => 9999, // server_id, no local match
                            'variation_id' => 8888,
                            'stock_id' => 7777,
                            'quantity' => 1,
                            'unit_price' => 100,
                            'tax' => 0,
                            'discount' => 0,
                            'discount_type' => 'flat',
                            'total' => 100,
                            'supplier_price' => 50,
                            'supplier_total' => 50,
                            'is_preparable' => 0,
                        ]],
                        'tombstones' => [],
                    ],
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, 'sales');

    expect(DB::table('pending_inbound_sync_rows')->count())->toBe(1);
    expect(DB::table('pending_inbound_sync_rows')->first()->resource)->toBe('sale_variation');
});

it('replays parked inbound rows on the next sync when the missing parent becomes available', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    // Manually park a sale_variation row referencing a sale we don't yet have.
    DB::table('pending_inbound_sync_rows')->insert([
        'resource' => 'sale_variation',
        'store_id' => $store->id,
        'payload' => json_encode([
            'sale_id' => 5001,        // server_id; we'll create local row next
            'variation_id' => 8001,
            'stock_id' => 9001,
            'quantity' => 3,
            'unit_price' => 100,
            'tax' => 0,
            'discount' => 0,
            'discount_type' => 'flat',
            'total' => 300,
            'supplier_price' => 50,
            'supplier_total' => 150,
            'is_preparable' => 0,
        ]),
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Insert the parents locally so the next sync can resolve the FKs.
    $productId = DB::table('products')->insertGetId([
        'store_id' => $store->id, 'server_id' => 7000, 'name' => 'P',
        'status' => 'active', 'has_variations' => 1, 'sync_state' => 'synced',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $variationId = DB::table('variations')->insertGetId([
        'store_id' => $store->id, 'product_id' => $productId, 'server_id' => 8001, 'description' => 'V',
        'price' => 100, 'sync_state' => 'synced', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $stockId = DB::table('stocks')->insertGetId([
        'variation_id' => $variationId, 'server_id' => 9001, 'barcode' => 'X',
        'stock' => 10, 'sync_state' => 'synced', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $saleId = DB::table('sales')->insertGetId([
        'store_id' => $store->id, 'server_id' => 5001, 'local_id' => 'X-1',
        'status' => 'completed', 'payment_status' => 'paid', 'payment_method' => 'cash',
        'discount_type' => 'flat', 'freight_fare' => 0, 'subtotal' => 300,
        'tax' => 0, 'discount' => 0, 'total' => 300,
        'sync_state' => 'synced', 'created_at' => now(), 'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/ack')) {
            return Http::response(['message' => 'ok'], 200);
        }
        if (str_contains($url, '/delta')) {
            return Http::response(['data' => []], 200);
        }

        return Http::response([], 200);
    });

    // Trigger a sync — the parked row should resolve now that all FKs exist.
    app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, 'sales');

    expect(DB::table('pending_inbound_sync_rows')->count())->toBe(0);
    expect(DB::table('sale_variation')->where('sale_id', $saleId)->count())->toBe(1);
    expect((float) DB::table('sale_variation')->where('sale_id', $saleId)->value('quantity'))->toBe(3.0);
});

it('auto-assigns a local_id during push so a fresh customer can still be pushed safely', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    // Brand-new customer with no server_id and no local_id — the common
    // case for an offline-created row. The identifier guard should NOT
    // block this; instead, pushPendingRowsV2 auto-assigns a local_id
    // (via LocalIdentifierService) so the server has a stable anchor.
    $customerId = DB::table('customers')->insertGetId([
        'store_id' => $store->id,
        'name' => 'Fresh Customer',
        'phone' => '5559999',
        'server_id' => null,
        'local_id' => null,
        'sync_state' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/upsert')) {
            return Http::response([
                'resources' => [[
                    'resource' => 'customers',
                    'results' => [['index' => 0, 'status' => 'synced', 'server_id' => 555]],
                ]],
            ], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->runPushOnly('https://cloud.example.test', 'token', $store, null, 'customers');

    $row = DB::table('customers')->find($customerId);
    expect($row->sync_state)->toBe('synced');
    expect($row->local_id)->not->toBeNull(); // auto-assigned by LocalIdentifierService
    expect((int) $row->server_id)->toBe(555); // round-tripped from server response
});

it('drops the concurrent push when the cache lock is already held by another worker', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    // Hold the lock as if another queue worker grabbed it first.
    $lock = Cache::lock("cloud-push-{$store->id}", 60);
    $lock->get();

    Http::fake(function () {
        return Http::response(['id' => 1], 200);
    });

    $result = app(CloudSyncService::class)->runPushOnly('https://cloud.example.test', 'token', $store);

    // ok=true but pushed=0 because the second worker bailed instead of racing.
    expect($result['ok'])->toBeTrue();
    expect($result['pushed'])->toBe(0);

    $lock->release();
});

it('runForceReconcile uses the fast snapshot path when available (Phase 2)', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    $snapshotHit = false;
    $v1Hit = false;

    $snapshotSql = "INSERT OR REPLACE INTO \"customers\" (\"id\",\"name\",\"phone\",\"store_id\",\"created_at\",\"updated_at\",\"server_id\",\"sync_state\",\"synced_at\",\"local_id\",\"sync_error\") VALUES (200,'Snap','5550200',1,'2026-05-20 00:00:00','2026-05-20 00:00:00',200,'synced','2026-05-20 00:00:00',NULL,NULL);\n-- SNAPSHOT_END store=1\n";

    Http::fake(function ($request) use (&$snapshotHit, &$v1Hit, $snapshotSql) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/upsert') || str_contains($url, '/delta/tombstones')) {
            return Http::response(['resources' => []], 200);
        }
        if (str_contains($url, '/api/pos/v2/stores/1/snapshot')) {
            $snapshotHit = true;

            return Http::response(gzencode($snapshotSql), 200, ['Content-Type' => 'application/gzip']);
        }
        // The slow v1 endpoint should NOT be hit when snapshot succeeds.
        if (preg_match('#/api/pos/v1/stores/1/sync/#', $url)) {
            $v1Hit = true;

            return Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->runForceReconcile('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();
    expect($result['mode'])->toBe('reconcile-snapshot');
    expect($snapshotHit)->toBeTrue('snapshot endpoint should have been called');
    expect($v1Hit)->toBeFalse('slow v1 pull should NOT have been used when snapshot succeeded');
});

it('runForceReconcile falls back to the legacy ndjson pull when the snapshot endpoint is unavailable', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    $v1Hit = false;

    Http::fake(function ($request) use (&$v1Hit) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/upsert') || str_contains($url, '/delta/tombstones')) {
            return Http::response(['resources' => []], 200);
        }
        if (str_contains($url, '/api/pos/v2/stores/1/snapshot')) {
            return Http::response(['message' => 'Not deployed yet'], 404);
        }
        if (preg_match('#/api/pos/v1/stores/1/sync/#', $url)) {
            $v1Hit = true;

            return Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->runForceReconcile('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue();
    expect($result['mode'])->toBe('reconcile-ndjson');
    expect($v1Hit)->toBeTrue('legacy pull must run when snapshot fails (404 here)');
});

it('runForceReconcile aborts BEFORE the snapshot wipe when local rows could not be pushed', function (): void {
    // Reproduce the failure mode the safety check protects against: a
    // local customer is pending and the server REJECTS the push. The
    // repair sync must NOT proceed to wipe local data — that would
    // destroy the unpushed edit.
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    DB::table('customers')->insert([
        'store_id' => $store->id,
        'name' => 'Unpushed local',
        'phone' => '5559001',
        'sync_state' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snapshotHit = false;

    Http::fake(function ($request) use (&$snapshotHit) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/upsert')) {
            // Server rejects the push.
            return Http::response(['message' => 'Validation failed'], 422);
        }
        if (str_contains($url, '/snapshot')) {
            $snapshotHit = true;

            return Http::response('', 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->runForceReconcile('https://cloud.example.test', 'token', $store);

    // Aborted, not OK. Local row preserved.
    expect($result['ok'])->toBeFalse();
    expect($result['unpushed'] ?? 0)->toBeGreaterThan(0);
    expect($snapshotHit)->toBeFalse('snapshot must NOT run when there are unpushed local rows');

    // Local row still alive — repair didn't destroy it.
    expect(DB::table('customers')->where('phone', '5559001')->count())->toBe(1);
});

it('reconcile artisan command short-circuits when cloud is not connected', function (): void {
    Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    $exitCode = $this->artisan('pos:cloud:reconcile')->run();

    expect($exitCode)->toBe(1); // FAILURE
});

it('reconcile job action routes to runForceReconcile', function (): void {
    Bus::fake();

    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);
    SyncCloudStoreData::dispatch((int) $store->id, 'reconcile');

    Bus::assertDispatched(SyncCloudStoreData::class, function ($job) use ($store): bool {
        return $job->storeId === $store->id && $job->action === 'reconcile';
    });
});

it('sync job is unique so multiple observer fires within the window collapse to one', function (): void {
    expect(class_implements(SyncCloudStoreData::class))
        ->toContain(ShouldBeUnique::class);

    $job = new SyncCloudStoreData(1, 'push', 'sales');
    expect($job->uniqueFor)->toBe(30);
    expect($job->uniqueId())->toBe('1-push-sales');
});

it('pending_inbound_sync_rows table is created by the migration', function (): void {
    expect(Schema::hasTable('pending_inbound_sync_rows'))->toBeTrue();
    expect(Schema::hasColumns('pending_inbound_sync_rows', [
        'resource', 'store_id', 'payload', 'attempts', 'last_error', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('records a tombstone in sync_outbox when an observed model is deleted', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    // Fake cloud mode so the observer wakes up.
    AppRuntimeState::query()->create([
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'has_completed_onboarding' => true,
    ]);

    $customer = Customer::query()->create([
        'store_id' => $store->id,
        'name' => 'Goodbye',
        'phone' => '5559876',
    ]);
    // server_id may not be mass-assignable on the core model.
    $customer->forceFill(['server_id' => 9001])->saveQuietly();
    $customer->refresh();

    $customer->delete();

    $tombstone = DB::table('sync_outbox')
        ->where('operation', 'delete')
        ->where('entity_type', 'customers')
        ->where('local_id', $customer->id)
        ->first();

    expect($tombstone)->not->toBeNull();
    expect((int) $tombstone->server_id)->toBe(9001);
    expect($tombstone->status)->toBe('pending');
});

it('pushTombstonesV2 sends pending tombstones to the server and clears the outbox on success', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    DB::table('sync_outbox')->insert([
        'entity_type' => 'customers',
        'local_id' => 12,
        'server_id' => 9002,
        'operation' => 'delete',
        'payload' => json_encode(['store_id' => $store->id, 'local_id' => null]),
        'attempts' => 0,
        'status' => 'pending',
        'error' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $captured = null;

    Http::fake(function ($request) use (&$captured) {
        $url = $request->url();
        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/tombstones')) {
            $captured = $request->data();

            return Http::response([
                'message' => 'Delta tombstones applied.',
                'results' => [['index' => 0, 'resource' => 'customers', 'status' => 'tombstoned', 'affected' => 1]],
            ], 200);
        }
        if (str_contains($url, '/delta/upsert')) {
            return Http::response(['resources' => []], 200);
        }

        return Http::response([], 200);
    });

    $result = app(CloudSyncService::class)->runPushOnly('https://cloud.example.test', 'token', $store, null, 'customers');

    expect($result['ok'])->toBeTrue();
    expect($result['tombstoned'])->toBe(1);
    expect($captured['tombstones'][0]['server_id'])->toBe(9002);
    expect($captured['tombstones'][0]['resource'])->toBe('customers');

    expect(DB::table('sync_outbox')->count())->toBe(0);
});

it('pushTombstonesV2 marks the outbox row failed when the server rejects the tombstone', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    DB::table('sync_outbox')->insert([
        'entity_type' => 'customers',
        'local_id' => 22,
        'server_id' => 9003,
        'operation' => 'delete',
        'payload' => json_encode(['store_id' => $store->id, 'local_id' => null]),
        'attempts' => 0,
        'status' => 'pending',
        'error' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();
        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/tombstones')) {
            return Http::response(['message' => 'boom'], 500);
        }
        if (str_contains($url, '/delta/upsert')) {
            return Http::response(['resources' => []], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->runPushOnly('https://cloud.example.test', 'token', $store, null, 'customers');

    $row = DB::table('sync_outbox')->first();
    expect($row->status)->toBe('failed');
    expect($row->attempts)->toBe(1);
    expect($row->error)->toContain('boom');
});

it('shouldSkipPulledRow keeps the local row when local updated_at is newer than server payload', function (): void {
    $store = Store::query()->create(['name' => 'Main', 'server_id' => 1]);

    DB::table('customers')->insert([
        'store_id' => $store->id,
        'name' => 'Local Newer',
        'phone' => '5550000',
        'server_id' => 555,
        'sync_state' => 'synced',
        'synced_at' => now()->subHour(),
        'created_at' => now()->subHour(),
        'updated_at' => now(),                                 // local: now
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/api/pos/v1/stores/1/sync/customers')) {
            return Http::response([
                'data' => [[
                    'id' => 555,
                    'store_id' => 1,
                    'name' => 'Server Stale',
                    'phone' => '5550000',
                    'updated_at' => now()->subHour()->toDateTimeString(),  // server: an hour ago
                ]],
                'meta' => ['current_page' => 1, 'last_page' => 1],
            ], 200);
        }

        return Http::response(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]], 200);
    });

    app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store, 'customers');

    // Local data preserved — server didn't overwrite the newer local copy.
    expect(DB::table('customers')->where('server_id', 555)->value('name'))->toBe('Local Newer');
});
