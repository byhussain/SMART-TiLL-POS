<?php

namespace App\Listeners;

use App\Jobs\SyncCloudStoreData;
use App\Services\RuntimeStateService;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\Notification;

/**
 * Handles menu-bar click events for the desktop app.
 *
 * The NativePHP auto-updater menu entry ("Help → Check for Updates…") was
 * removed because the updater on Windows uninstalled the running app and
 * then failed to install the replacement, leaving clients without a working
 * POS. Until the updater is proven safe, updates are distributed manually.
 *
 * The MENU_ITEM_ID constant is kept so any older menu definition that still
 * references it can short-circuit gracefully.
 */
class AppUpdateListener
{
    public const MENU_ITEM_ID = 'app:check-for-updates';

    public const MENU_ITEM_SYNC_NOW = 'app:cloud-sync-now';

    public function handleMenuClick(MenuItemClicked $event): void
    {
        $id = (string) ($event->item['id'] ?? '');

        if ($id === self::MENU_ITEM_SYNC_NOW) {
            $this->triggerCloudSyncNow();
        }
    }

    /**
     * Manual cloud delta-sync triggered from the menu bar's "Cloud → Sync Now"
     * item. Only acts when signed in to a cloud account.
     */
    private function triggerCloudSyncNow(): void
    {
        /** @var RuntimeStateService $runtimeState */
        $runtimeState = app(RuntimeStateService::class);
        $state = $runtimeState->get();

        $isCloudConnected = $state->mode === 'cloud'
            && (bool) $state->cloud_token_present
            && filled($state->cloud_base_url)
            && filled($state->cloud_token);

        if (! $isCloudConnected) {
            Notification::title('Cloud not connected')
                ->message('Sign in to cloud first to enable sync.')
                ->show();

            return;
        }

        $storeId = (int) ($state->active_store_id ?? 0);
        if ($storeId <= 0) {
            Notification::title('No active store')
                ->message('Select a store before syncing.')
                ->show();

            return;
        }

        SyncCloudStoreData::dispatch($storeId, 'delta');

        Notification::title('Cloud sync started')
            ->message('Pulling the latest data from the cloud server.')
            ->show();
    }
}
