<?php

namespace App\Console\Commands\Pos;

use App\Models\Store;
use App\Services\CloudSyncService;
use App\Services\RuntimeStateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wall-clock benchmark for the two bootstrap paths against a real cloud
 * store. Wipes the local store's data between runs so each path starts
 * from the same blank state.
 *
 * Usage:
 *   php artisan pos:cloud:bootstrap-benchmark               # active store
 *   php artisan pos:cloud:bootstrap-benchmark --store=4     # specific local store id
 *   php artisan pos:cloud:bootstrap-benchmark --only=snapshot  # run only one path
 *
 * Outputs a table of timings:
 *   Path     | Total time | Rows installed
 *   snapshot | 3.2s       | 12450
 *   ndjson   | 41.7s      | 12450
 *
 * DESTRUCTIVE — wipes the local store's data twice. Do NOT run on a
 * device that has unpushed pending edits unless you push them first.
 */
#[Signature('pos:cloud:bootstrap-benchmark {--store= : Local store id (defaults to the runtime active store)} {--only= : Run only one path: snapshot | ndjson}')]
#[Description('Compare wall-clock bootstrap time for the snapshot vs ndjson paths against a real cloud store. Wipes local data between runs.')]
class CloudBootstrapBenchmarkCommand extends Command
{
    /**
     * Tables we wipe between benchmark runs — the same set the snapshot/
     * ndjson installers populate. Order matters: children first so FK
     * cascades don't surprise us on engines that enforce them.
     */
    private const WIPE_ORDER = [
        'model_activities',
        'images',
        'transactions',
        'payments',
        'sale_preparable_items',
        'sale_variation',
        'sales',
        'purchase_order_products',
        'purchase_orders',
        'stocks',
        'variations',
        'product_attributes',
        'products',
        'store_settings',
        'unit_dimensions',
        'units',
        'attributes',
        'categories',
        'brands',
        'suppliers',
        'customers',
    ];

    public function handle(RuntimeStateService $runtimeStateService, CloudSyncService $cloudSyncService): int
    {
        $state = $runtimeStateService->get();

        if ($state->mode !== 'cloud' || ! $state->cloud_token_present || blank($state->cloud_base_url) || blank($state->cloud_token)) {
            $this->error('Cloud is not connected. Sign in to a cloud account first.');

            return self::FAILURE;
        }

        $storeId = (int) ($this->option('store') ?: $state->active_store_id ?? 0);
        if ($storeId <= 0) {
            $this->error('No store selected. Pass --store=<id> or select a store in the POS.');

            return self::FAILURE;
        }

        $store = Store::query()->find($storeId);
        if (! $store || (int) ($store->server_id ?? 0) <= 0) {
            $this->error("Store #{$storeId} not found or has no cloud mapping.");

            return self::FAILURE;
        }

        $only = $this->option('only');
        if ($only !== null && ! in_array($only, ['snapshot', 'ndjson'], true)) {
            $this->error("--only must be 'snapshot' or 'ndjson'");

            return self::FAILURE;
        }

        if (! $this->confirm("This will WIPE local data for store {$store->name} (#{$store->id}) and re-download it twice. Continue?", false)) {
            return self::SUCCESS;
        }

        $results = [];

        if ($only === null || $only === 'snapshot') {
            $this->wipeStoreData($store->id);
            $runtimeStateService->updateStoreSyncState($store->id, ['bootstrap_status' => 'not_started']);

            $this->info('Running snapshot bootstrap…');
            $start = microtime(true);
            $snapshotResult = $cloudSyncService->runSnapshotBootstrap(
                (string) $state->cloud_base_url,
                (string) $state->cloud_token,
                $store,
            );
            $elapsed = microtime(true) - $start;
            $results[] = [
                'path' => 'snapshot',
                'ok' => (bool) ($snapshotResult['ok'] ?? false),
                'elapsed_s' => round($elapsed, 2),
                'rows' => (int) ($snapshotResult['installed'] ?? 0),
                'notes' => $snapshotResult['ok'] ?? false ? '' : (string) ($snapshotResult['message'] ?? 'failed'),
            ];
        }

        if ($only === null || $only === 'ndjson') {
            $this->wipeStoreData($store->id);
            $runtimeStateService->updateStoreSyncState($store->id, ['bootstrap_status' => 'not_started']);

            $this->info('Running ndjson bootstrap…');
            $start = microtime(true);
            $ndjsonResult = $cloudSyncService->runBootstrapSync(
                (string) $state->cloud_base_url,
                (string) $state->cloud_token,
                $store,
            );
            $elapsed = microtime(true) - $start;
            $results[] = [
                'path' => 'ndjson',
                'ok' => (bool) ($ndjsonResult['ok'] ?? false),
                'elapsed_s' => round($elapsed, 2),
                'rows' => (int) ($ndjsonResult['installed'] ?? 0),
                'notes' => $ndjsonResult['ok'] ?? false ? '' : (string) ($ndjsonResult['message'] ?? 'failed'),
            ];
        }

        $this->newLine();
        $this->table(
            ['Path', 'Ok', 'Elapsed', 'Rows installed', 'Notes'],
            collect($results)->map(fn (array $r): array => [
                $r['path'],
                $r['ok'] ? 'yes' : 'no',
                $r['elapsed_s'].'s',
                (string) $r['rows'],
                $r['notes'],
            ])->all(),
        );

        if (count($results) === 2 && $results[0]['ok'] && $results[1]['ok'] && $results[1]['elapsed_s'] > 0) {
            $speedup = round($results[1]['elapsed_s'] / max(0.01, $results[0]['elapsed_s']), 1);
            $this->info("Snapshot is {$speedup}× faster than ndjson on this store.");
        }

        return self::SUCCESS;
    }

    /**
     * Truncate every syncable table for the given store. Uses raw DELETE
     * instead of TRUNCATE because SQLite doesn't support TRUNCATE.
     */
    private function wipeStoreData(int $storeId): void
    {
        foreach (self::WIPE_ORDER as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'store_id')) {
                DB::table($table)->where('store_id', $storeId)->delete();

                continue;
            }

            // Child tables without store_id — scope via parent.
            $deleted = match ($table) {
                'sale_variation', 'sale_preparable_items' => DB::table($table)
                    ->whereIn('sale_id', DB::table('sales')->where('store_id', $storeId)->select('id'))
                    ->delete(),
                'stocks' => DB::table($table)
                    ->whereIn('variation_id', DB::table('variations')->where('store_id', $storeId)->select('id'))
                    ->delete(),
                'product_attributes' => DB::table($table)
                    ->whereIn('product_id', DB::table('products')->where('store_id', $storeId)->select('id'))
                    ->delete(),
                'purchase_order_products' => DB::table($table)
                    ->whereIn('purchase_order_id', DB::table('purchase_orders')->where('store_id', $storeId)->select('id'))
                    ->delete(),
                default => 0,
            };
            unset($deleted);
        }
    }
}
