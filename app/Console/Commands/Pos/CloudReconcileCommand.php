<?php

namespace App\Console\Commands\Pos;

use App\Models\Store;
use App\Services\CloudSyncService;
use App\Services\RuntimeStateService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One-shot repair for desktop installs that drifted from the cloud during
 * the period when sync had bugs (line items dropped, edits silenced by
 * withoutEvents). Pushes anything still pending locally, then re-pulls
 * every resource fresh so the cloud's authoritative view is restored.
 *
 * Usage:
 *   php artisan pos:cloud:reconcile               # active store
 *   php artisan pos:cloud:reconcile --store=4     # specific store id
 */
#[Signature('pos:cloud:reconcile {--store= : Local store id (defaults to the runtime active store)}')]
#[Description('Push pending local rows and re-pull every cloud resource fresh to repair drifted local data.')]
class CloudReconcileCommand extends Command
{
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
        if (! $store) {
            $this->error("Store #{$storeId} not found locally.");

            return self::FAILURE;
        }

        if ((int) ($store->server_id ?? 0) <= 0) {
            $this->error("Store #{$storeId} has no cloud mapping (server_id is missing).");

            return self::FAILURE;
        }

        $this->info("Reconciling store {$store->name} (local #{$store->id}, cloud #{$store->server_id})…");
        $this->line('  → Pushing pending local rows');
        $this->line('  → Re-pulling every cloud resource fresh (this may take a while)');

        $result = $cloudSyncService->runForceReconcile(
            (string) $state->cloud_base_url,
            (string) $state->cloud_token,
            $store
        );

        if (($result['ok'] ?? false) !== true) {
            $this->error('Reconcile failed: '.($result['message'] ?? 'Unknown error.'));

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Reconcile complete.');
        $this->table(['Phase', 'Rows'], [
            ['Pushed to cloud', (int) ($result['pushed'] ?? 0)],
            ['Pulled from cloud', (int) ($result['pulled'] ?? 0)],
        ]);

        return self::SUCCESS;
    }
}
