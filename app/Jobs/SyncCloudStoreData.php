<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\SyncOutbox;
use App\Services\CloudSyncService;
use App\Services\RuntimeStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncCloudStoreData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1200;

    public int $backoff = 30;

    public int $storeId;

    public string $action;

    public ?string $module;

    public ?string $resource;

    public function __construct(
        int $storeId,
        string $action = 'auto',
        ?string $module = null,
        ?string $resource = null,
    ) {
        $this->storeId = $storeId;

        if (! in_array($action, ['auto', 'bootstrap', 'delta'], true) && $module === null && $resource === null) {
            $this->action = 'delta';
            $this->module = $action;
            $this->resource = null;

            return;
        }

        $this->action = $action;
        $this->module = $module;
        $this->resource = $resource;
    }

    public function handle(RuntimeStateService $runtimeStateService, CloudSyncService $cloudSyncService): void
    {
        try {
            $store = Store::query()->find($this->storeId);
            if (! $store || (int) ($store->server_id ?? 0) <= 0) {
                return;
            }

            $state = $runtimeStateService->get();
            if ($state->mode !== 'cloud' || ! $state->cloud_token_present || blank($state->cloud_base_url) || blank($state->cloud_token)) {
                return;
            }

            $action = $this->action;
            if ($action === 'auto') {
                $action = $runtimeStateService->isStoreBootstrapped($this->storeId) ? 'delta' : 'bootstrap';
            }

            $result = match ($action) {
                'bootstrap' => $cloudSyncService->runBootstrapSync(
                    (string) $state->cloud_base_url,
                    (string) $state->cloud_token,
                    $store
                ),
                default => $cloudSyncService->runDeltaSync(
                    (string) $state->cloud_base_url,
                    (string) $state->cloud_token,
                    $store,
                    $this->module,
                    $this->resource
                ),
            };

            if (($result['ok'] ?? false) === true) {
                $runtimeStateService->touchLastSynced();

                return;
            }

            $message = (string) ($result['message'] ?? 'Background sync failed.');
            if ($action === 'bootstrap') {
                $runtimeStateService->markBootstrapFailed($this->storeId, $message);
            } else {
                $runtimeStateService->updateStoreSyncState($this->storeId, [
                    'bootstrap_progress_label' => $message,
                ]);
            }

            $this->recordFailure($this->storeId, $message, $action);
        } catch (Throwable $throwable) {
            report($throwable);
            if ($this->action === 'bootstrap') {
                $runtimeStateService->markBootstrapFailed($this->storeId, $throwable->getMessage());
            }
            $this->recordFailure($this->storeId, $throwable->getMessage(), $this->action);
        }
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        $scope = implode('-', array_filter([
            'sync-cloud-store',
            $this->storeId,
            $this->action,
            $this->module,
            $this->resource,
        ]));

        return [
            (new WithoutOverlapping($scope))
                ->dontRelease()
                ->expireAfter(1800),
        ];
    }

    private function recordFailure(int $storeId, string $error, string $action): void
    {
        SyncOutbox::query()->create([
            'entity_type' => 'cloud_store_sync',
            'local_id' => $storeId,
            'operation' => $action,
            'status' => 'failed',
            'error' => trim($error) !== '' ? $error : 'Background sync failed.',
        ]);
    }
}
