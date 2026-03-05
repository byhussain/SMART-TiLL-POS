<?php

namespace App\Http\Middleware;

use App\Jobs\SyncCloudStoreData;
use App\Models\Store;
use App\Services\RuntimeStateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncCloudStoreOnTenantSwitch
{
    public function __construct(
        private readonly RuntimeStateService $runtimeStateService,
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
            SyncCloudStoreData::dispatch((int) $store->id);

            if ($request->hasSession()) {
                $request->session()->put('pos_last_sync_dispatched_tenant_id', (int) $store->id);
            }
        }

        return $next($request);
    }
}
