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

    public ?string $module = null;

    public ?string $resource = null;

    public int $page = 1;

    public function __construct(
        int $storeId,
        ?string $module = null,
        ?string $resource = null,
        int $page = 1,
    ) {
        $this->storeId = $storeId;
        $this->module = $module;
        $this->resource = $resource;
        $this->page = max(1, $page);
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

            if ($this->module === null && $this->resource === null && $this->page === 1) {
                foreach ($cloudSyncService->getSyncModuleKeys() as $moduleKey) {
                    self::dispatch($this->storeId, (string) $moduleKey);
                }

                return;
            }

            $result = $cloudSyncService->syncChunk(
                (string) $state->cloud_base_url,
                (string) $state->cloud_token,
                $store,
                $this->module,
                $this->resource,
                $this->page,
            );

            if (($result['ok'] ?? false) === true) {
                $nextResource = $result['next']['resource'] ?? null;
                $nextPage = (int) ($result['next']['page'] ?? 0);
                if (is_string($nextResource) && $nextResource !== '' && $nextPage > 0) {
                    self::dispatch($this->storeId, $this->module, $nextResource, $nextPage);

                    return;
                }

                $runtimeStateService->touchLastSynced();

                return;
            }

            $this->recordFailure($store->id, (string) ($result['message'] ?? 'Background sync failed.'));
        } catch (Throwable $throwable) {
            report($throwable);
            $this->recordFailure($this->storeId, $throwable->getMessage());
        }
    }

    /**
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        $syncScope = $this->module
            ?? ($this->resource !== null ? 'resource-'.$this->resource : 'all');

        return [
            (new WithoutOverlapping('sync-cloud-store-'.$this->storeId.'-'.$syncScope))
                ->dontRelease()
                ->expireAfter(1800),
        ];
    }

    private function recordFailure(int $storeId, string $error): void
    {
        SyncOutbox::query()->create([
            'entity_type' => 'cloud_store_sync',
            'local_id' => $storeId,
            'operation' => 'pull',
            'status' => 'failed',
            'error' => trim($error) !== '' ? $error : 'Background sync failed.',
        ]);
    }
}
