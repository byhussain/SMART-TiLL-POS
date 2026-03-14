<?php

namespace App\Services;

use App\Models\AppRuntimeState;
use App\Models\Store;

class RuntimeStateService
{
    public function get(): AppRuntimeState
    {
        $state = AppRuntimeState::query()->firstOrCreate(
            ['id' => 1],
            [
                'has_completed_onboarding' => false,
                'mode' => null,
                'cloud_token_present' => false,
                'bootstrap_status' => 'not_started',
                'bootstrap_progress_percent' => 0,
                'store_sync_states' => [],
            ]
        );

        if (! is_array($state->store_sync_states)) {
            $state->store_sync_states = [];
        }

        return $state;
    }

    public function completeGuestOnboarding(Store $store): AppRuntimeState
    {
        $state = $this->get();
        $state->fill([
            'has_completed_onboarding' => true,
            'mode' => 'guest',
            'active_store_id' => $store->id,
            'cloud_user_id' => null,
            'cloud_token_present' => false,
            'cloud_token' => null,
            'cloud_base_url' => null,
        ]);

        $this->applyActiveStoreSyncState($state, $store->id, $this->defaultStoreSyncState());
        $state->save();

        return $state;
    }

    public function completeCloudOnboarding(Store $store, int $cloudUserId, string $token, string $baseUrl): AppRuntimeState
    {
        $state = $this->get();
        $state->fill([
            'has_completed_onboarding' => true,
            'mode' => 'cloud',
            'active_store_id' => $store->id,
            'cloud_user_id' => $cloudUserId,
            'cloud_token_present' => true,
            'cloud_token' => $token,
            'cloud_base_url' => $baseUrl,
        ]);

        $this->applyActiveStoreSyncState(
            $state,
            $store->id,
            $this->getStoreSyncStateFromState($state, $store->id)
        );
        $state->save();

        return $state;
    }

    public function persistCloudCredentials(int $cloudUserId, string $token, string $baseUrl): AppRuntimeState
    {
        $state = $this->get();
        $state->fill([
            'mode' => 'cloud',
            'cloud_user_id' => $cloudUserId,
            'cloud_token_present' => true,
            'cloud_token' => $token,
            'cloud_base_url' => $baseUrl,
        ])->save();

        return $state;
    }

    public function markDisconnected(): AppRuntimeState
    {
        $state = $this->get();
        $state->fill([
            'mode' => 'guest',
            'cloud_token_present' => false,
            'cloud_token' => null,
        ])->save();

        return $state;
    }

    public function touchLastSynced(): void
    {
        $state = $this->get();
        $state->last_synced_at = now();
        $state->save();
    }

    public function setActiveStore(Store $store): AppRuntimeState
    {
        $state = $this->get();
        $state->active_store_id = $store->id;
        $this->applyActiveStoreSyncState(
            $state,
            $store->id,
            $this->getStoreSyncStateFromState($state, $store->id)
        );
        $state->save();

        return $state;
    }

    public function getStoreSyncState(int $storeId): array
    {
        return $this->getStoreSyncStateFromState($this->get(), $storeId);
    }

    public function isStoreBootstrapped(int $storeId): bool
    {
        return ($this->getStoreSyncState($storeId)['bootstrap_status'] ?? 'not_started') === 'ready';
    }

    public function markBootstrapStarted(int $storeId, string $generation, string $label = 'Preparing store data download'): AppRuntimeState
    {
        return $this->updateStoreSyncState($storeId, [
            'bootstrap_status' => 'downloading',
            'bootstrap_progress_percent' => 5,
            'bootstrap_progress_label' => $label,
            'bootstrap_generation' => $generation,
        ]);
    }

    public function updateBootstrapProgress(int $storeId, int $percent, string $label): AppRuntimeState
    {
        $currentStatus = (string) ($this->getStoreSyncState($storeId)['bootstrap_status'] ?? 'not_started');

        return $this->updateStoreSyncState($storeId, [
            'bootstrap_status' => $currentStatus === 'installing' ? 'installing' : 'downloading',
            'bootstrap_progress_percent' => max(0, min($percent, 100)),
            'bootstrap_progress_label' => $label,
        ]);
    }

    public function markBootstrapInstalling(int $storeId, string $label = 'Installing downloaded store data'): AppRuntimeState
    {
        return $this->updateStoreSyncState($storeId, [
            'bootstrap_status' => 'installing',
            'bootstrap_progress_percent' => 60,
            'bootstrap_progress_label' => $label,
        ]);
    }

    public function markBootstrapReady(int $storeId): AppRuntimeState
    {
        $state = $this->updateStoreSyncState($storeId, [
            'bootstrap_status' => 'ready',
            'bootstrap_progress_percent' => 100,
            'bootstrap_progress_label' => 'Store data is ready.',
            'last_delta_pull_at' => now()?->toISOString(),
        ]);

        $state->last_synced_at = now();
        $state->save();

        return $state;
    }

    public function markBootstrapFailed(int $storeId, string $label): AppRuntimeState
    {
        return $this->updateStoreSyncState($storeId, [
            'bootstrap_status' => 'failed',
            'bootstrap_progress_label' => $label,
        ]);
    }

    public function markDeltaSyncing(int $storeId, string $label = 'Syncing cloud updates'): AppRuntimeState
    {
        return $this->updateStoreSyncState($storeId, [
            'bootstrap_progress_label' => $label,
        ]);
    }

    public function markDeltaPulled(int $storeId): AppRuntimeState
    {
        return $this->updateStoreSyncState($storeId, [
            'last_delta_pull_at' => now()?->toISOString(),
            'bootstrap_progress_label' => 'Pushing local changes',
        ]);
    }

    public function markDeltaCompleted(int $storeId): AppRuntimeState
    {
        $state = $this->updateStoreSyncState($storeId, [
            'last_delta_pull_at' => now()?->toISOString(),
            'last_delta_push_at' => now()?->toISOString(),
            'bootstrap_progress_label' => 'All synced',
        ]);

        $state->last_synced_at = now();
        $state->save();

        return $state;
    }

    public function updateStoreSyncState(int $storeId, array $updates): AppRuntimeState
    {
        $state = $this->get();
        $storeStates = is_array($state->store_sync_states) ? $state->store_sync_states : [];
        $current = $this->getStoreSyncStateFromState($state, $storeId);
        $next = array_merge($current, $updates);
        $storeStates[(string) $storeId] = $next;
        $state->store_sync_states = $storeStates;

        if ((int) ($state->active_store_id ?? 0) === $storeId) {
            $this->applyActiveStoreSyncState($state, $storeId, $next);
        }

        $state->save();

        return $state;
    }

    private function getStoreSyncStateFromState(AppRuntimeState $state, int $storeId): array
    {
        $storeStates = is_array($state->store_sync_states) ? $state->store_sync_states : [];
        $existing = $storeStates[(string) $storeId] ?? [];

        return array_merge($this->defaultStoreSyncState(), is_array($existing) ? $existing : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultStoreSyncState(): array
    {
        return [
            'bootstrap_status' => 'not_started',
            'bootstrap_progress_percent' => 0,
            'bootstrap_progress_label' => 'Store data has not been downloaded yet.',
            'bootstrap_generation' => null,
            'last_delta_pull_at' => null,
            'last_delta_push_at' => null,
        ];
    }

    private function applyActiveStoreSyncState(AppRuntimeState $state, int $storeId, array $storeSyncState): void
    {
        $state->active_store_id = $storeId;
        $state->bootstrap_status = (string) ($storeSyncState['bootstrap_status'] ?? 'not_started');
        $state->bootstrap_progress_percent = (int) ($storeSyncState['bootstrap_progress_percent'] ?? 0);
        $state->bootstrap_progress_label = $storeSyncState['bootstrap_progress_label'] ?? null;
        $state->bootstrap_generation = $storeSyncState['bootstrap_generation'] ?? null;
        $state->last_delta_pull_at = $storeSyncState['last_delta_pull_at'] ?? null;
        $state->last_delta_push_at = $storeSyncState['last_delta_push_at'] ?? null;
    }
}
