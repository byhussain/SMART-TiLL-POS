<?php

namespace App\Providers;

use App\Listeners\AppUpdateListener;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Events\AutoUpdater\Error as UpdateError;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Artisan::call('native:core:install');

        $this->registerMenuBar();
        $this->registerUpdateListeners();

        Window::open();
    }

    /**
     * Build the system menu bar. Adds a "Help" menu with a "Check for
     * Updates…" item that triggers the AutoUpdater workflow handled by
     * AppUpdateListener.
     */
    private function registerMenuBar(): void
    {
        Menu::create(
            Menu::app(),
            Menu::file(),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
            Menu::label('Cloud')->submenu(
                Menu::label('Sync Now')
                    ->id(AppUpdateListener::MENU_ITEM_SYNC_NOW)
                    ->accelerator('CmdOrCtrl+Shift+S'),
            ),
            Menu::label('Help')->submenu(
                Menu::label('Check for Updates…')
                    ->id(AppUpdateListener::MENU_ITEM_ID),
            ),
        );
    }

    /**
     * Wire AutoUpdater + menu-click events to the AppUpdateListener methods.
     */
    private function registerUpdateListeners(): void
    {
        Event::listen(MenuItemClicked::class, [AppUpdateListener::class, 'handleMenuClick']);
        Event::listen(UpdateAvailable::class, [AppUpdateListener::class, 'handleUpdateAvailable']);
        Event::listen(UpdateNotAvailable::class, [AppUpdateListener::class, 'handleUpdateNotAvailable']);
        Event::listen(UpdateDownloaded::class, [AppUpdateListener::class, 'handleUpdateDownloaded']);
        Event::listen(UpdateError::class, [AppUpdateListener::class, 'handleUpdateError']);
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            // 0 = unlimited. NativePHP's queue worker is a long-running daemon
            // that loops forever; any positive value kills it after that many
            // seconds and floods the log with FatalError entries. Web requests
            // have their own lifecycle, and individual queue jobs reset the
            // timer via Laravel's set_time_limit() per job.
            'max_execution_time' => '0',
            'sqlite3.defensive' => '1',
            // Production realpath cache helps Windows file IO meaningfully.
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => '600',
            // OPcache for the bundled PHP runtime — biggest single win on Win.
            'opcache.enable' => '1',
            'opcache.enable_cli' => '1',
            'opcache.memory_consumption' => '256',
            'opcache.max_accelerated_files' => '20000',
            'opcache.validate_timestamps' => '0',
        ];
    }
}
