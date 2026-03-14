<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\RuntimeStateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreBootstrapReady
{
    public function __construct(
        private readonly RuntimeStateService $runtimeStateService,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');
        $storeId = is_object($tenant) ? (int) ($tenant->id ?? 0) : (int) $tenant;

        if ($storeId <= 0) {
            return $next($request);
        }

        $state = $this->runtimeStateService->get();
        $isCloudStore = $state->mode === 'cloud'
            && (bool) $state->cloud_token_present
            && filled($state->cloud_base_url)
            && filled($state->cloud_token);

        if (! $isCloudStore) {
            return $next($request);
        }

        $store = Store::query()->find($storeId);
        if (! $store || (int) ($store->server_id ?? 0) <= 0) {
            return $next($request);
        }

        $syncState = $this->runtimeStateService->getStoreSyncState($storeId);
        $bootstrapStatus = (string) ($syncState['bootstrap_status'] ?? 'not_started');

        if (! in_array($bootstrapStatus, ['downloading', 'installing'], true)) {
            return $next($request);
        }

        return redirect()->route('startup.cloud.bootstrap', ['store' => $storeId]);
    }
}
