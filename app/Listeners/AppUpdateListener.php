<?php

namespace App\Listeners;

use App\Jobs\SyncCloudStoreData;
use App\Services\RuntimeStateService;
use Native\Desktop\Events\AutoUpdater\Error as UpdateError;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\AutoUpdater;
use Native\Desktop\Facades\Notification;

/**
 * Thin wrapper around NativePHP's official AutoUpdater. The menu bar has a
 * single "Check for Updates…" item; everything else (download, install on
 * next launch) is handled by Electron's built-in electron-updater.
 *
 * Flow:
 *   1. User picks Help → Check for Updates…
 *   2. AutoUpdater::checkForUpdates() pings GitHub releases.
 *   3. Electron downloads any newer version in the background (default
 *      auto-download behaviour of electron-updater).
 *   4. We surface 4 lightweight OS notifications so the user knows what is
 *      happening — no modal dialogs, no custom UI to maintain.
 *   5. On the next normal app restart electron-updater installs the staged
 *      update automatically.
 */
class AppUpdateListener
{
    public const MENU_ITEM_ID = 'app:check-for-updates';

    public const MENU_ITEM_SYNC_NOW = 'app:cloud-sync-now';

    public function handleMenuClick(MenuItemClicked $event): void
    {
        $id = (string) ($event->item['id'] ?? '');

        if ($id === self::MENU_ITEM_ID) {
            Notification::title('SMART TiLL POS')
                ->message('Checking for updates…')
                ->show();

            AutoUpdater::checkForUpdates();

            return;
        }

        if ($id === self::MENU_ITEM_SYNC_NOW) {
            $this->triggerCloudSyncNow();
        }
    }

    public function handleUpdateAvailable(UpdateAvailable $event): void
    {
        Notification::title('Update available')
            ->message("Downloading version {$event->version} in the background.")
            ->show();
    }

    public function handleUpdateNotAvailable(UpdateNotAvailable $event): void
    {
        Notification::title('Up to date')
            ->message("You're running the latest version ({$event->version}).")
            ->show();
    }

    public function handleUpdateDownloaded(UpdateDownloaded $event): void
    {
        Notification::title('Update ready')
            ->message("Version {$event->version} will be installed the next time you restart SMART TiLL POS.")
            ->show();
    }

    public function handleUpdateError(UpdateError $event): void
    {
        $message = trim((string) ($event->message ?? '')) !== ''
            ? (string) $event->message
            : 'Unknown error';

        Notification::title('Update check failed')
            ->message($message)
            ->show();
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
