<?php

namespace App\Listeners;

use App\Jobs\SyncCloudStoreData;
use App\Services\RuntimeStateService;
use Native\Desktop\Events\AutoUpdater\Error as UpdateError;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\Alert;
use Native\Desktop\Facades\AutoUpdater;
use Native\Desktop\Facades\Notification;

/**
 * Handles the desktop "Check for Updates…" menu item and the corresponding
 * AutoUpdater events. The flow is:
 *
 *  1. User picks Help → Check for Updates… in the system menu bar.
 *  2. NativePHP fires MenuItemClicked → we trigger AutoUpdater::checkForUpdates().
 *  3. The Electron auto-updater contacts GitHub releases and fires either
 *     UpdateAvailable (with version) or UpdateNotAvailable.
 *  4. If available, we ask the user "Download now?". If they confirm, we call
 *     AutoUpdater::downloadUpdate().
 *  5. When the download finishes, NativePHP fires UpdateDownloaded. We ask
 *     "Restart and install?". If they confirm, we call quitAndInstall().
 *
 * Every step requires explicit user confirmation — nothing happens behind
 * the user's back.
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

            return;
        }
    }

    /**
     * Manually fire a cloud delta-sync job. Only acts when the user is logged
     * in with a cloud account; otherwise shows a friendly notification.
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

    public function handleUpdateAvailable(UpdateAvailable $event): void
    {
        $choice = Alert::new()
            ->type('question')
            ->title('Update available')
            ->detail("A newer version ({$event->version}) is available. Would you like to download it now?")
            ->buttons(['Download', 'Later'])
            ->defaultId(0)
            ->cancelId(1)
            ->show("Update {$event->version} is ready to download.");

        if ($choice === 0) {
            Notification::title('Downloading update')
                ->message("Version {$event->version} is downloading in the background.")
                ->show();

            AutoUpdater::downloadUpdate();
        }
    }

    public function handleUpdateNotAvailable(UpdateNotAvailable $event): void
    {
        Notification::title('Up to date')
            ->message("You're running the latest version ({$event->version}).")
            ->show();
    }

    public function handleUpdateDownloaded(UpdateDownloaded $event): void
    {
        $choice = Alert::new()
            ->type('question')
            ->title('Update ready to install')
            ->detail("Version {$event->version} has been downloaded. The app will close and reopen to finish installing.")
            ->buttons(['Restart and Install', 'Later'])
            ->defaultId(0)
            ->cancelId(1)
            ->show("Install update {$event->version} now?");

        if ($choice === 0) {
            AutoUpdater::quitAndInstall();
        }
    }

    public function handleUpdateError(UpdateError $event): void
    {
        $message = (string) ($event->message ?? 'Unknown error');

        Alert::error('Update failed', "Couldn't check for updates: {$message}");
    }
}
