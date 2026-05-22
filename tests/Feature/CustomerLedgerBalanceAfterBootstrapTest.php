<?php

use App\Models\Store;
use App\Services\CloudSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SmartTill\Core\Models\Customer;

uses(RefreshDatabase::class);

it('stores the bootstrap transactionable_type as the Eloquent morph alias so observer queries match', function (): void {
    // The credit-sale ledger bug: cloud server sends a transaction tied
    // to a customer; bootstrap pulls it; the row's `transactionable_type`
    // MUST equal what the local Eloquent app would serialize for a brand-
    // new Transaction tied to the same customer. Otherwise
    // TransactionObserver's running-balance lookup (which filters by
    // `transactionable_type`) misses the bootstrapped row and restarts
    // the balance from 0 on the next credit sale.
    $store = Store::query()->create(['id' => 7, 'name' => 'Main', 'server_id' => 7]);

    Http::fake([
        'https://cloud.example.test/api/pos/user' => Http::response(['id' => 1], 200),
        'https://cloud.example.test/api/pos/v2/stores/7/delta*' => Http::response([
            'data' => [
                [
                    'resource' => 'customers',
                    'rows' => [[
                        'id' => 555,
                        'store_id' => 7,
                        'name' => 'Walk-in regular',
                        'phone' => '5550000',
                        'created_at' => now()->subDays(10)->toISOString(),
                        'updated_at' => now()->subDays(10)->toISOString(),
                    ]],
                    'tombstones' => [],
                    'cursor' => ['updated_at' => now()->toISOString(), 'id' => 555],
                    'has_more' => false,
                ],
                [
                    'resource' => 'transactions',
                    'rows' => [[
                        'id' => 901,
                        'store_id' => 7,
                        'transactionable_type' => 'App\\Models\\Customer',
                        'transactionable_id' => 555,
                        'type' => 'credit_sale',
                        'amount' => 25000,
                        'amount_balance' => 25000,
                        'note' => 'Cloud-era credit sale',
                        'created_at' => now()->subDays(5)->toISOString(),
                        'updated_at' => now()->subDays(5)->toISOString(),
                    ]],
                    'tombstones' => [],
                    'cursor' => ['updated_at' => now()->toISOString(), 'id' => 901],
                    'has_more' => false,
                ],
            ],
        ], 200),
        'https://cloud.example.test/api/pos/v2/stores/7/delta/upsert' => Http::response(['resources' => []], 200),
        'https://cloud.example.test/api/pos/v2/stores/7/delta/ack' => Http::response(['message' => 'ok'], 200),
    ]);

    app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, null, 'customers');
    app(CloudSyncService::class)->runDeltaSync('https://cloud.example.test', 'token', $store, null, 'transactions');

    $customer = Customer::query()->where('server_id', 555)->firstOrFail();

    // The bootstrapped transaction's type matches what Eloquent will
    // write for a fresh Transaction tied to this customer.
    $eloquentAlias = $customer->getMorphClass();
    $bootstrapped = DB::table('transactions')->where('server_id', 901)->first();

    expect($bootstrapped)->not->toBeNull();
    expect($bootstrapped->transactionable_type)->toBe($eloquentAlias);
    expect((int) $bootstrapped->transactionable_id)->toBe($customer->id);

    // TransactionObserver's running-balance query (the actual production
    // query) finds the bootstrapped row. Before the fix this returned
    // null because the type strings didn't match — and credit-sale
    // balances would restart from 0.
    $lastBalanceCents = (int) DB::table('transactions')
        ->where('transactionable_type', $eloquentAlias)
        ->where('transactionable_id', $customer->id)
        ->latest('id')
        ->value('amount_balance');

    expect($lastBalanceCents)->toBe(25000);
});

it('backfill migration rewrites pre-fix FQCN rows to the morph alias', function (): void {
    // Simulates an existing install that received the buggy bootstrap
    // (transaction stored with the SmartTill\Core FQCN). The migration
    // must normalize it so the next credit sale's balance carries over.
    $store = Store::query()->create(['id' => 9, 'name' => 'Migration test', 'server_id' => 9]);

    DB::table('transactions')->insert([
        'store_id' => $store->id,
        'transactionable_type' => 'SmartTill\\Core\\Models\\Customer',
        'transactionable_id' => 42,
        'type' => 'credit_sale',
        'amount' => 99,
        'amount_balance' => 99,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Run the targeted migration's up() against the seeded buggy row.
    $migration = require base_path('database/migrations/2026_05_20_120000_normalize_polymorphic_morph_types.php');
    $migration->up();

    expect(DB::table('transactions')
        ->where('transactionable_id', 42)
        ->value('transactionable_type'))
        ->toBe('App\\Models\\Customer');
});
