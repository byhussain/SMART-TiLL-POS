<?php

use App\Models\Store;
use App\Services\CloudSyncService;
use App\Services\RuntimeStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('paginates the delta endpoint when the server reports has_more=true', function (): void {
    $store = Store::query()->create(['id' => 42, 'name' => 'Main', 'server_id' => 42]);

    $callCount = 0;
    $pagesRequested = [];

    Http::fake(function ($request) use (&$callCount, &$pagesRequested) {
        $url = $request->url();
        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/ack')) {
            return Http::response(['message' => 'ok'], 200);
        }
        if (str_contains($url, '/delta')) {
            $callCount++;
            $pagesRequested[] = $request->data();

            // First page returns one customer with has_more=true.
            // Second page returns another customer with has_more=false.
            // Without the fix, the second customer would be lost forever
            // because last_delta_pull_at would advance past it.
            if ($callCount === 1) {
                return Http::response([
                    'data' => [[
                        'resource' => 'customers',
                        'rows' => [[
                            'id' => 555,
                            'store_id' => 42,
                            'name' => 'Page 1 Customer',
                            'phone' => '5550001',
                            'created_at' => '2026-05-10 10:00:00',
                            'updated_at' => '2026-05-10 10:00:00',
                        ]],
                        'tombstones' => [],
                        'cursor' => ['updated_at' => '2026-05-10 10:00:00', 'id' => 555],
                        'has_more' => true,
                    ]],
                    'meta' => ['has_more' => true],
                ], 200);
            }

            return Http::response([
                'data' => [[
                    'resource' => 'customers',
                    'rows' => [[
                        'id' => 556,
                        'store_id' => 42,
                        'name' => 'Page 2 Customer',
                        'phone' => '5550002',
                        'created_at' => '2026-05-11 11:00:00',
                        'updated_at' => '2026-05-11 11:00:00',
                    ]],
                    'tombstones' => [],
                    'cursor' => ['updated_at' => '2026-05-11 11:00:00', 'id' => 556],
                    'has_more' => false,
                ]],
                'meta' => ['has_more' => false],
            ], 200);
        }
        if (str_contains($url, '/delta/upsert') || str_contains($url, '/delta/tombstones')) {
            return Http::response(['resources' => []], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, null, 'customers');

    // Both customers must be present locally — pagination did its job.
    expect(DB::table('customers')->where('server_id', 555)->count())->toBe(1);
    expect(DB::table('customers')->where('server_id', 556)->count())->toBe(1);

    // And we called /delta exactly twice, not once.
    expect($callCount)->toBe(2);

    // The second request used the first page's cursor as `since` — that's
    // what tells the server where to resume.
    expect($pagesRequested[1]['since'] ?? null)->toBe('2026-05-10 10:00:00');
    expect((int) ($pagesRequested[1]['since_id'] ?? 0))->toBe(555);
});

it('persists the newest seen cursor as last_delta_pull_at so the next sync skips already-pulled rows', function (): void {
    $store = Store::query()->create(['id' => 43, 'name' => 'Main', 'server_id' => 43]);

    Http::fake(function ($request) {
        $url = $request->url();
        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/ack')) {
            return Http::response(['message' => 'ok'], 200);
        }
        if (str_contains($url, '/delta/upsert') || str_contains($url, '/delta/tombstones')) {
            return Http::response(['resources' => []], 200);
        }
        if (str_contains($url, '/delta')) {
            return Http::response([
                'data' => [[
                    'resource' => 'customers',
                    'rows' => [[
                        'id' => 999,
                        'store_id' => 43,
                        'name' => 'Newer than last sync',
                        'phone' => '5559999',
                        'created_at' => '2026-05-15 12:34:56',
                        'updated_at' => '2026-05-15 12:34:56',
                    ]],
                    'tombstones' => [],
                    'cursor' => ['updated_at' => '2026-05-15 12:34:56', 'id' => 999],
                    'has_more' => false,
                ]],
                'meta' => ['has_more' => false],
            ], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, null, 'customers');

    $state = app(RuntimeStateService::class)->getStoreSyncState($store->id);
    // The cursor matches the SERVER's most recent updated_at — NOT just `now()`.
    // Without this, the next delta would have re-pulled (waste) OR — combined
    // with pagination — could re-advance past rows that arrived since.
    expect($state['last_delta_pull_at'])->toBe('2026-05-15 12:34:56');
});

it('pushes pending tombstones during a regular delta sync, not only during reconcile', function (): void {
    $store = Store::query()->create(['id' => 44, 'name' => 'Main', 'server_id' => 44]);

    // Pre-seed one pending tombstone.
    DB::table('sync_outbox')->insert([
        'entity_type' => 'customers',
        'local_id' => 7,
        'server_id' => 7777,
        'operation' => 'delete',
        'payload' => json_encode(['store_id' => $store->id, 'local_id' => null]),
        'attempts' => 0,
        'status' => 'pending',
        'error' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tombstoneCalled = false;

    Http::fake(function ($request) use (&$tombstoneCalled) {
        $url = $request->url();
        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/ack')) {
            return Http::response(['message' => 'ok'], 200);
        }
        if (str_contains($url, '/delta/tombstones')) {
            $tombstoneCalled = true;

            return Http::response([
                'message' => 'ok',
                'results' => [['index' => 0, 'resource' => 'customers', 'status' => 'tombstoned', 'affected' => 1]],
            ], 200);
        }
        if (str_contains($url, '/delta/upsert')) {
            return Http::response(['resources' => []], 200);
        }
        if (str_contains($url, '/delta')) {
            return Http::response([
                'data' => [],
                'meta' => ['has_more' => false],
            ], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, null, 'customers');

    expect($tombstoneCalled)->toBeTrue();
    expect(DB::table('sync_outbox')->count())->toBe(0);
});
