<?php

use App\Models\AppRuntimeState;
use App\Models\Store;
use App\Services\CloudSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SmartTill\Core\Models\StoreSetting;

uses(RefreshDatabase::class);

it('parks a tombstone when the local row has unpushed pending edits', function (): void {
    // Server admin deletes a customer that the cashier just edited locally
    // and hasn't pushed yet. The user's edit must NOT be silently destroyed —
    // park the tombstone and let push happen first. The next sync re-evaluates.
    $store = Store::query()->create(['id' => 71, 'name' => 'Main', 'server_id' => 71]);

    DB::table('customers')->insert([
        'id' => 1,
        'store_id' => $store->id,
        'name' => 'Locally edited',
        'phone' => '5551111',
        'server_id' => 9001,
        'sync_state' => 'pending',  // crucial — user just edited offline
        'created_at' => now()->subHour(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();
        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/ack') || str_contains($url, '/delta/tombstones')) {
            return Http::response(['message' => 'ok'], 200);
        }
        if (str_contains($url, '/delta/upsert')) {
            return Http::response(['resources' => []], 200);
        }
        if (str_contains($url, '/delta')) {
            return Http::response([
                'data' => [[
                    'resource' => 'customers',
                    'rows' => [],
                    'tombstones' => [[
                        'id' => 9001,
                        'server_id' => 9001,
                        'deleted_at' => now()->toDateTimeString(),
                    ]],
                    'cursor' => ['updated_at' => now()->toDateTimeString(), 'id' => 9001],
                    'has_more' => false,
                ]],
                'meta' => ['has_more' => false],
            ], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, null, 'customers');

    // Local row still alive — user's edit preserved.
    $row = DB::table('customers')->where('id', 1)->first();
    expect($row)->not->toBeNull();
    expect($row->deleted_at)->toBeNull();

    // And the tombstone was parked for replay after push.
    $parked = DB::table('pending_inbound_sync_rows')
        ->where('resource', 'customers')
        ->where('store_id', $store->id)
        ->first();

    expect($parked)->not->toBeNull();
    $payload = json_decode((string) $parked->payload, true);
    expect($payload['__kind'] ?? null)->toBe('tombstone');
});

it('applies a tombstone unconditionally when the local row is already synced', function (): void {
    // For a synced local row, the server's delete is authoritative — apply
    // it even when local updated_at is newer (e.g. clock skew between
    // devices). The user explicitly deleted on the server; respect that.
    $store = Store::query()->create(['id' => 72, 'name' => 'Main', 'server_id' => 72]);

    DB::table('customers')->insert([
        'id' => 2,
        'store_id' => $store->id,
        'name' => 'Already synced',
        'phone' => '5552222',
        'server_id' => 9002,
        'sync_state' => 'synced',
        'created_at' => now()->subHour(),
        'updated_at' => now(),
    ]);

    Http::fake(function ($request) {
        $url = $request->url();
        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/ack') || str_contains($url, '/delta/tombstones')) {
            return Http::response(['message' => 'ok'], 200);
        }
        if (str_contains($url, '/delta/upsert')) {
            return Http::response(['resources' => []], 200);
        }
        if (str_contains($url, '/delta')) {
            return Http::response([
                'data' => [[
                    'resource' => 'customers',
                    'rows' => [],
                    'tombstones' => [[
                        'id' => 9002,
                        'server_id' => 9002,
                        'deleted_at' => '2026-05-19 00:00:00',
                    ]],
                    'cursor' => ['updated_at' => '2026-05-19 00:00:00', 'id' => 9002],
                    'has_more' => false,
                ]],
                'meta' => ['has_more' => false],
            ], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, null, 'customers');

    // Local row is now soft-deleted — tombstone applied.
    $row = DB::table('customers')->where('id', 2)->first();
    expect($row->deleted_at)->not->toBeNull();
});

it('flips a pushed row to synced only when updated_at has not moved since the snapshot', function (): void {
    // CAS guard: if the user re-edits the same row between the push job's
    // SELECT and the post-success status update, the row must STAY pending
    // so the next push round ships the new version. Without this, the
    // local edit gets clobbered to 'synced' and silently dies.
    $store = Store::query()->create(['id' => 73, 'name' => 'Main', 'server_id' => 73]);

    DB::table('customers')->insert([
        'id' => 3,
        'store_id' => $store->id,
        'name' => 'First edit',
        'phone' => '5553333',
        'server_id' => null,
        'local_id' => null,
        'sync_state' => 'pending',
        'created_at' => now()->subMinutes(10),
        'updated_at' => '2026-05-19 09:00:00',
    ]);

    Http::fake(function ($request) {
        $url = $request->url();
        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/delta/upsert')) {
            // Server accepted, but BEFORE we process the response, the user
            // edited the customer again. Simulate that by advancing the row's
            // updated_at so the CAS guard sees a different value.
            DB::table('customers')->where('id', 3)->update([
                'name' => 'Re-edited mid-push',
                'updated_at' => '2026-05-19 09:05:00',
            ]);

            return Http::response([
                'resources' => [[
                    'resource' => 'customers',
                    'results' => [['index' => 0, 'status' => 'synced', 'server_id' => 9003]],
                ]],
            ], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->runPushOnly('https://cloud.example.test', 'token', $store, null, 'customers');

    $row = DB::table('customers')->where('id', 3)->first();
    // Row stayed pending — the next push will ship the re-edit.
    expect($row->sync_state)->toBe('pending');
    expect($row->name)->toBe('Re-edited mid-push');
});

it('observer flags a StoreSetting edit as pending so the push job ships it', function (): void {
    // Before this fix, resolveResource(StoreSetting) returned null and the
    // observer silently dropped the edit — the cloud version then clobbered
    // it on every pull.
    $store = Store::query()->create(['id' => 74, 'name' => 'Main', 'server_id' => 74]);

    AppRuntimeState::query()->create([
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'has_completed_onboarding' => true,
    ]);

    $setting = StoreSetting::query()->create([
        'store_id' => $store->id,
        'key' => 'receipt_footer',
        'value' => 'Thank you',
        'type' => 'string',
    ]);

    // Re-load and confirm the observer flipped sync_state to 'pending'.
    expect(DB::table('store_settings')->where('id', $setting->id)->value('sync_state'))
        ->toBe('pending');
});
