<?php

namespace App\Listeners;

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

    public function handleMenuClick(MenuItemClicked $event): void
    {
        $id = (string) ($event->item['id'] ?? '');
        if ($id !== self::MENU_ITEM_ID) {
            return;
        }

        Notification::title('SMART TiLL POS')
            ->message('Checking for updates…')
            ->show();

        AutoUpdater::checkForUpdates();
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
