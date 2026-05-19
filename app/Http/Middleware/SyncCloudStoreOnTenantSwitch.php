<?php

namespace App\Http\Middleware;

use App\Jobs\SyncCloudStoreData;
use App\Models\Store;
use App\Services\CloudSyncService;
use App\Services\RuntimeStateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncCloudStoreOnTenantSwitch
{
    public function __construct(
        private readonly RuntimeStateService $runtimeStateService,
        private readonly CloudSyncService $cloudSyncService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (int) ($request->route('tenant') ?? 0);
        if ($tenantId <= 0) {
            return $next($request);
        }

        $store = Store::query()->find($tenantId);
        if (! $store) {
            return $next($request);
        }

        $previousActiveStoreId = (int) ($this->runtimeStateService->get()->active_store_id ?? 0);
        $this->runtimeStateService->setActiveStore($store);

        $state = $this->runtimeStateService->get();
        $lastDispatchedTenantId = $request->hasSession()
            ? (int) $request->session()->get('pos_last_sync_dispatched_tenant_id', 0)
            : 0;

        $shouldSync = $state->mode === 'cloud'
            && (bool) $state->cloud_token_present
            && filled($state->cloud_base_url)
            && filled($state->cloud_token)
            && (int) ($store->server_id ?? 0) > 0
            && $lastDispatchedTenantId !== (int) $store->id;

        if ($shouldSync) {
            $hasSwitchedStore = $previousActiveStoreId > 0 && $previousActiveStoreId !== (int) $store->id;

            if ($hasSwitchedStore) {
                // Per product requirement: every store switch re-downloads
                // the snapshot for the new store. Push leaving-store
                // pending edits SYNCHRONOUSLY first — the snapshot wipe
                // is destructive and an async push could lose them. If
                // the push fails (offline, server down), we still proceed
                // but log; the leaving-store rows stay pending and will
                // ship the next time we visit that store online.
                if ($previousActiveStoreId > 0) {
                    $previousStore = Store::query()->find($previousActiveStoreId);
                    if ($previousStore && (int) ($previousStore->server_id ?? 0) > 0) {
                        // Wall-clock cap on the push (default 15s; tunable
                        // via CLOUD_SWITCH_PUSH_TIMEOUT). Bounded so a
                        // slow/offline server can never freeze the UI on
                        // store switch. If the budget is exhausted, the
                        // switch proceeds; the leaving store's pending
                        // rows simply stay pending and ship next visit.
                        $pushTimeoutSeconds = max(1, (int) config('pos.switch.push_timeout_seconds', 15));
                        try {
                            $this->cloudSyncService->runPushOnly(
                                (string) $state->cloud_base_url,
                                (string) $state->cloud_token,
                                $previousStore,
                                null,
                                null,
                                $pushTimeoutSeconds,
                            );
                        } catch (\Throwable $exception) {
                            report($exception);
                            // Swallow — the leaving store's pending edits
                            // remain `sync_state=pending` and will ship on
                            // the next visit. Better to allow the switch
                            // than to lock the user out.
                        }
                    }
                }

                $this->runtimeStateService->updateStoreSyncState((int) $store->id, [
                    'bootstrap_status' => 'not_started',
                    'bootstrap_progress_percent' => 0,
                    'bootstrap_progress_label' => 'Preparing to download fresh store data',
                ]);
            }

            $action = $this->runtimeStateService->isStoreBootstrapped((int) $store->id) ? 'delta' : 'bootstrap';
            SyncCloudStoreData::dispatch((int) $store->id, $action);

            if ($request->hasSession()) {
                $request->session()->put('pos_last_sync_dispatched_tenant_id', (int) $store->id);
            }
        }

        return $next($request);
    }
}
