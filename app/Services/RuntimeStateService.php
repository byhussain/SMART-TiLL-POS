<?php

namespace App\Services;

use App\Models\AppRuntimeState;
use App\Models\Store;

class RuntimeStateService
{
    public function get(): AppRuntimeState
    {
        return AppRuntimeState::query()->firstOrCreate(
            ['id' => 1],
            [
                'has_completed_onboarding' => false,
                'mode' => null,
                'cloud_token_present' => false,
            ]
        );
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
        ])->save();

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
        ])->save();

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
        $state->save();

        return $state;
    }
}
