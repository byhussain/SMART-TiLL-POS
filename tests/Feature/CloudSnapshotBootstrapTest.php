<?php

use App\Http\Middleware\SyncCloudStoreOnTenantSwitch;
use App\Jobs\SyncCloudStoreData;
use App\Models\AppRuntimeState;
use App\Models\Store;
use App\Services\CloudSyncService;
use App\Services\RuntimeStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

it('imports a snapshot file in one transaction, populates server_id/sync_state, and resets sqlite_sequence', function (): void {
    // id isn't fillable on Store, so set it explicitly via raw insert
    // so the customer rows' FK to stores.id is satisfied. Under
    // RefreshDatabase Pest wraps each test in a transaction which makes
    // `PRAGMA foreign_keys=OFF` a no-op — meaning FK is enforced even
    // though production turns it off for the import.
    DB::table('stores')->insert([
        'id' => 81, 'name' => 'Snap test', 'server_id' => 81,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $store = Store::query()->find(81);

    // Craft a SQLite-compatible SQL dump exactly as the server would emit it.
    // The dump uses "" for identifiers and '' for SQL-92 escaped strings.
    $sql = <<<'SQL'
PRAGMA foreign_keys=OFF;
BEGIN;
INSERT INTO "customers" ("id","name","phone","store_id","created_at","updated_at","server_id","sync_state","synced_at","local_id","sync_error") VALUES
(101,'Alice','5550101',81,'2026-05-19 00:00:00','2026-05-19 00:00:00',101,'synced','2026-05-19 12:00:00',NULL,NULL),
(102,'Bob''s Bakery',NULL,81,'2026-05-19 00:00:00','2026-05-19 00:00:00',102,'synced','2026-05-19 12:00:00',NULL,NULL);
COMMIT;
PRAGMA foreign_keys=ON;
SQL;
    $snapshotPath = storage_path('app/cloud-sync/test-snapshot.sql.gz');
    @mkdir(dirname($snapshotPath), 0777, true);
    file_put_contents($snapshotPath, gzencode($sql));

    Http::fake([
        '*/api/pos/user' => Http::response(['id' => 1], 200),
        '*/api/pos/v2/stores/81/snapshot' => Http::response(file_get_contents($snapshotPath), 200, [
            'Content-Type' => 'application/gzip',
        ]),
    ]);

    // Sink path can't be controlled with Http::fake (it fakes the response
    // body directly), so call the importer through the service's public
    // entry point — the fake's response body lands at the sink location.
    $result = app(CloudSyncService::class)->runSnapshotBootstrap('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue($result['message'] ?? 'snapshot bootstrap failed');
    expect($result['mode'])->toBe('snapshot');

    // Both rows landed, identifiers preserved, sync_state stamped 'synced',
    // server_id populated (so the next push knows the canonical PK).
    $alice = DB::table('customers')->where('id', 101)->first();
    expect($alice)->not->toBeNull();
    expect($alice->name)->toBe('Alice');
    expect((int) $alice->server_id)->toBe(101);
    expect($alice->sync_state)->toBe('synced');

    // SQL-92 escaped apostrophe round-tripped cleanly into the row.
    $bob = DB::table('customers')->where('id', 102)->first();
    expect($bob->name)->toBe("Bob's Bakery");

    // sqlite_sequence has been bumped to MAX(id) so the next local insert
    // doesn't collide with id=102.
    $seq = (int) DB::table('sqlite_sequence')->where('name', 'customers')->value('seq');
    expect($seq)->toBe(102);
});

it('falls back to the ndjson bootstrap when the snapshot endpoint returns 404', function (): void {
    $store = Store::query()->create(['id' => 82, 'name' => 'Snap fallback', 'server_id' => 82]);

    Http::fake([
        '*/api/pos/user' => Http::response(['id' => 1], 200),
        // Snapshot endpoint not deployed on this older server.
        '*/api/pos/v2/stores/82/snapshot' => Http::response(['message' => 'Not found'], 404),
    ]);

    $result = app(CloudSyncService::class)->runSnapshotBootstrap('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeFalse();
    expect($result['fallback'] ?? null)->toBe('ndjson');
});

it('store-switch middleware pushes leaving store pending edits synchronously and resets the new store bootstrap', function (): void {
    $storeA = Store::query()->create(['id' => 91, 'name' => 'Store A', 'server_id' => 91]);
    $storeB = Store::query()->create(['id' => 92, 'name' => 'Store B', 'server_id' => 92]);

    $runtimeState = app(RuntimeStateService::class);
    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'active_store_id' => $storeA->id,
    ]);
    // Pre-mark store B as bootstrapped — without the switch reset we'd just
    // delta-sync it instead of snapshot-bootstrapping fresh.
    $runtimeState->updateStoreSyncState($storeB->id, ['bootstrap_status' => 'ready']);

    // Mock CloudSyncService::runPushOnly so we can assert it was called
    // synchronously for the leaving store (not just dispatched as a job).
    $cloudSync = Mockery::mock(CloudSyncService::class);
    $cloudSync->shouldReceive('runPushOnly')
        ->once()
        ->withArgs(fn (string $url, string $token, Store $s) => $s->id === $storeA->id)
        ->andReturn(['ok' => true, 'pushed' => 0]);
    app()->instance(CloudSyncService::class, $cloudSync);

    Bus::fake();

    $request = Request::create('/test', 'GET');
    $request->setRouteResolver(function () use ($storeB) {
        $route = new Route(['GET'], '/', []);
        $route->parameters = ['tenant' => $storeB->id];

        return $route;
    });
    $session = new Illuminate\Session\Store('test', new ArraySessionHandler(120));
    $session->setRequestOnHandler($request);
    $request->setLaravelSession($session);

    app(SyncCloudStoreOnTenantSwitch::class)
        ->handle($request, fn () => new Response('ok'));

    // New store is no longer marked "ready" — the next sync triggers a
    // fresh snapshot bootstrap instead of a delta.
    $stateAfter = $runtimeState->getStoreSyncState($storeB->id);
    expect($stateAfter['bootstrap_status'] ?? null)->toBe('not_started');

    // And a bootstrap job is dispatched for the new store. (The leaving
    // store's push was synchronous, so it doesn't show up as a dispatched
    // job — that's verified above via the runPushOnly mock expectation.)
    Bus::assertDispatched(SyncCloudStoreData::class, function ($job) use ($storeB): bool {
        return $job->storeId === $storeB->id && $job->action === 'bootstrap';
    });
});

it('wipes all syncable tables before importing so stale rows from a prior store cannot collide with snapshot ids', function (): void {
    // Reproduce the multi-store ID collision risk: store A's locally-
    // pushed variation lives at id=999 with server_id=500. Store B's
    // snapshot includes a row with id=999 (server's globally-allocated
    // id for a store-B variation). Without the wipe, INSERT OR REPLACE
    // would clobber store A's row silently. With the wipe, all stale
    // rows are gone before the snapshot lands and the collision is
    // impossible — the local DB ends up containing only the snapshot.
    // id isn't fillable on Store, so set explicit ids via raw insert so
    // the customer's store_id FK is satisfied.
    DB::table('stores')->insert([
        ['id' => 95, 'name' => 'Snap wipe', 'server_id' => 95, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 50, 'name' => 'Prior store', 'server_id' => 50, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $store = Store::query()->find(95);

    DB::table('customers')->insert([
        'id' => 999,
        'store_id' => 50,         // different store entirely — should be wiped
        'name' => 'Stale row from another store',
        'phone' => '5559990',
        'server_id' => 500,
        'sync_state' => 'synced',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sql = <<<'SQL'
INSERT OR REPLACE INTO "customers" ("id","name","phone","store_id","created_at","updated_at","server_id","sync_state","synced_at","local_id","sync_error") VALUES
(999,'Fresh from snapshot','5550999',95,'2026-05-19 00:00:00','2026-05-19 00:00:00',999,'synced','2026-05-19 12:00:00',NULL,NULL);
SQL;

    Http::fake([
        '*/api/pos/user' => Http::response(['id' => 1], 200),
        '*/api/pos/v2/stores/95/snapshot' => Http::response(gzencode($sql), 200, [
            'Content-Type' => 'application/gzip',
        ]),
    ]);

    $result = app(CloudSyncService::class)->runSnapshotBootstrap('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue($result['message'] ?? 'snapshot bootstrap failed');

    // Stale store-50 row was wiped. Only the snapshot row remains.
    $count = DB::table('customers')->count();
    expect($count)->toBe(1);

    $row = DB::table('customers')->where('id', 999)->first();
    expect($row->name)->toBe('Fresh from snapshot');
    expect((int) $row->store_id)->toBe(95);
});

it('refuses to import a snapshot when another import is already holding the lock for the same store', function (): void {
    $store = Store::query()->create(['id' => 96, 'name' => 'Snap lock', 'server_id' => 96]);

    // Hold the lock as if another worker process started a snapshot install.
    $lock = Cache::lock("snapshot-bootstrap-{$store->id}", 60);
    expect($lock->get())->toBeTrue();

    try {
        $sql = "INSERT OR REPLACE INTO \"customers\" (\"id\",\"name\",\"phone\",\"store_id\",\"created_at\",\"updated_at\",\"server_id\",\"sync_state\",\"synced_at\",\"local_id\",\"sync_error\") VALUES (1,'X','111',96,'2026-05-19','2026-05-19',1,'synced','2026-05-19',NULL,NULL);";

        Http::fake([
            '*/api/pos/user' => Http::response(['id' => 1], 200),
            "*/api/pos/v2/stores/{$store->id}/snapshot" => Http::response(gzencode($sql), 200, [
                'Content-Type' => 'application/gzip',
            ]),
        ]);

        $result = app(CloudSyncService::class)->runSnapshotBootstrap('https://cloud.example.test', 'token', $store);

        // The second import bailed out via the lock guard — falls back to
        // ndjson rather than blocking forever or racing.
        expect($result['ok'])->toBeFalse();
        expect($result['fallback'] ?? null)->toBe('ndjson');
        // No row should have been imported because the lock prevented it.
        expect(DB::table('customers')->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('skips the snapshot path entirely when CLOUD_BOOTSTRAP_USE_SNAPSHOT=false (kill switch)', function (): void {
    // Kill switch lets ops disable the new bootstrap path remotely via
    // env, without a re-release. When off, syncNow must NOT call the
    // snapshot endpoint — it goes straight to the legacy ndjson path.
    config(['pos.bootstrap.use_snapshot' => false]);

    DB::table('stores')->insert([
        'id' => 71, 'name' => 'Kill switch', 'server_id' => 71,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $store = Store::query()->find(71);

    // Mark active store + un-bootstrapped so syncNow takes the bootstrap branch.
    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'active_store_id' => $store->id,
    ]);

    $snapshotCalled = false;
    $bootstrapCalled = false;

    Http::fake(function ($request) use (&$snapshotCalled, &$bootstrapCalled) {
        $url = $request->url();
        if (str_ends_with($url, '/api/pos/user')) {
            return Http::response(['id' => 1], 200);
        }
        if (str_contains($url, '/snapshot')) {
            $snapshotCalled = true;

            return Http::response('should never be called', 200);
        }
        if (str_contains($url, '/bootstrap')) {
            $bootstrapCalled = true;
            // Return a minimal manifest + empty ndjson so the legacy path completes.
            if (str_contains($url, '/download')) {
                return Http::response("\n", 200);
            }
            if (preg_match('#/bootstrap/\w+$#', $url)) {
                return Http::response(['status' => 'ready'], 200);
            }

            return Http::response([
                'status' => 'ready',
                'generation' => 'gen-1',
                'manifest' => ['resources' => [], 'store_id' => $request->url()],
            ], 200);
        }

        return Http::response([], 200);
    });

    app(CloudSyncService::class)->syncNow('https://cloud.example.test', 'token', $store);

    expect($snapshotCalled)->toBeFalse('snapshot endpoint must not be called when the kill switch is off');
    expect($bootstrapCalled)->toBeTrue('legacy ndjson bootstrap should run when the kill switch is off');
});

it('switch middleware caps the synchronous push with a wall-clock budget so the UI never freezes', function (): void {
    $storeA = Store::query()->create(['id' => 81, 'name' => 'Store A', 'server_id' => 81]);
    $storeB = Store::query()->create(['id' => 82, 'name' => 'Store B', 'server_id' => 82]);

    AppRuntimeState::query()->create([
        'id' => 1,
        'has_completed_onboarding' => true,
        'mode' => 'cloud',
        'cloud_token_present' => true,
        'cloud_token' => 'token',
        'cloud_base_url' => 'https://cloud.example.test',
        'active_store_id' => $storeA->id,
    ]);

    // Drop the budget to 1 second so the test runs fast — verifies the
    // mechanism, not the exact 15s default.
    config(['pos.switch.push_timeout_seconds' => 1]);

    // Capture the timeout the middleware passed into runPushOnly. If the
    // wiring is wrong, $capturedTimeout stays null.
    $capturedTimeout = null;
    $cloudSync = Mockery::mock(CloudSyncService::class);
    $cloudSync->shouldReceive('runPushOnly')
        ->once()
        ->withArgs(function ($url, $token, $store, $module, $resource, $timeout) use (&$capturedTimeout) {
            $capturedTimeout = $timeout;

            return true;
        })
        ->andReturn(['ok' => true, 'pushed' => 0]);
    app()->instance(CloudSyncService::class, $cloudSync);

    Bus::fake();

    $request = Request::create('/test', 'GET');
    $request->setRouteResolver(function () use ($storeB) {
        $route = new Route(['GET'], '/', []);
        $route->parameters = ['tenant' => $storeB->id];

        return $route;
    });
    $session = new Illuminate\Session\Store('test', new ArraySessionHandler(120));
    $session->setRequestOnHandler($request);
    $request->setLaravelSession($session);

    app(SyncCloudStoreOnTenantSwitch::class)
        ->handle($request, fn () => new Response('ok'));

    expect($capturedTimeout)->toBe(1);
});

it('preserves the local store country_id/currency_id/timezone_id across the snapshot import', function (): void {
    // POS's countries/currencies/timezones tables have their own
    // auto-increment ids that don't line up with the server's. The login
    // flow (ensureLocalStoreFromServer) resolved this store's region by
    // code/name when it was first created — that mapping must survive
    // the snapshot import. Without preservation the snapshot's
    // INSERT OR REPLACE on `stores` would overwrite our correctly-resolved
    // local region ids with the server's ids, which on POS point at the
    // WRONG rows (server's currency_id=50 = PKR but POS's id=50 = RUB).
    DB::table('currencies')->insert([
        ['id' => 7, 'name' => 'Pakistani Rupee', 'code' => 'PKR', 'decimal_places' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 50, 'name' => 'Russian Ruble', 'code' => 'RUB', 'decimal_places' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('stores')->insert([
        'id' => 90,
        'name' => 'PKR store',
        'server_id' => 90,
        // Resolved by login flow: local PKR id is 7.
        'currency_id' => 7,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $store = Store::query()->find(90);

    // Server dump emits the store row with the SERVER's currency_id (50,
    // which is PKR on the server). Without our preservation step, POS
    // would write 50 verbatim and start displaying "Russian Ruble"
    // because POS's id=50 is RUB.
    $sql = "INSERT OR REPLACE INTO \"stores\" (\"id\",\"name\",\"server_id\",\"currency_id\",\"created_at\",\"updated_at\") VALUES (90,'PKR store',90,50,'2026-05-20 00:00:00','2026-05-20 00:00:00');";

    Http::fake([
        '*/api/pos/user' => Http::response(['id' => 1], 200),
        '*/api/pos/v2/stores/90/snapshot' => Http::response(gzencode($sql), 200, [
            'Content-Type' => 'application/gzip',
        ]),
    ]);

    app(CloudSyncService::class)->runSnapshotBootstrap('https://cloud.example.test', 'token', $store);

    // Local currency_id is still 7 (PKR), NOT 50 (RUB which is server's PKR id).
    $localStore = DB::table('stores')->where('id', 90)->first();
    expect((int) $localStore->currency_id)->toBe(7);
});

it('issues PRAGMA foreign_keys=OFF before the import transaction and PRAGMA foreign_keys=ON after', function (): void {
    // Production failure mode this guards against: server dumps sometimes
    // contain a row that references a parent that ends up missing locally
    // (stale id, dump ordering edge case, deleted parent server-side).
    // With FK enforcement on, COMMIT throws "FOREIGN KEY constraint
    // failed" and the device falls back to ndjson. Disabling FK
    // enforcement just for the import window lets the snapshot land; the
    // next delta sync converges any stale refs.
    //
    // SQLite's `PRAGMA foreign_keys` is a no-op INSIDE a transaction
    // (which Pest's RefreshDatabase wraps every test in), so we can't
    // observe the actual FK off/on toggle from a test. Instead, capture
    // the sequence of statements the importer issues and assert the
    // pragmas appear in the right order around the transaction.
    DB::table('stores')->insert([
        'id' => 89, 'name' => 'FK-OFF guard', 'server_id' => 89,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $store = Store::query()->find(89);

    $sql = "INSERT OR REPLACE INTO \"customers\" (\"id\",\"name\",\"phone\",\"store_id\",\"created_at\",\"updated_at\",\"server_id\",\"sync_state\",\"synced_at\",\"local_id\",\"sync_error\") VALUES (901,'Alice','5550901',89,'2026-05-20 00:00:00','2026-05-20 00:00:00',901,'synced','2026-05-20 00:00:00',NULL,NULL);";

    Http::fake([
        '*/api/pos/user' => Http::response(['id' => 1], 200),
        '*/api/pos/v2/stores/89/snapshot' => Http::response(gzencode($sql), 200, [
            'Content-Type' => 'application/gzip',
        ]),
    ]);

    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $result = app(CloudSyncService::class)->runSnapshotBootstrap('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeTrue($result['message'] ?? '');

    $foreignKeysOff = array_search('PRAGMA foreign_keys = OFF', $statements, true);
    $foreignKeysOn = array_search('PRAGMA foreign_keys = ON', $statements, true);

    expect($foreignKeysOff)->not->toBeFalse('importer must issue PRAGMA foreign_keys = OFF');
    expect($foreignKeysOn)->not->toBeFalse('importer must issue PRAGMA foreign_keys = ON after the import');
    expect($foreignKeysOn)->toBeGreaterThan($foreignKeysOff, 'ON must come after OFF');
});

it('falls back to ndjson when the snapshot file is corrupted or empty', function (): void {
    $store = Store::query()->create(['id' => 83, 'name' => 'Snap corrupt', 'server_id' => 83]);

    Http::fake([
        '*/api/pos/user' => Http::response(['id' => 1], 200),
        // Server returns 200 but garbage body — gzdecode will fail.
        '*/api/pos/v2/stores/83/snapshot' => Http::response('not actually gzipped', 200),
    ]);

    $result = app(CloudSyncService::class)->runSnapshotBootstrap('https://cloud.example.test', 'token', $store);

    expect($result['ok'])->toBeFalse();
    expect($result['fallback'] ?? null)->toBe('ndjson');
});
