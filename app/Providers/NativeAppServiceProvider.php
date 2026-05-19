<?php

namespace App\Providers;

use App\Listeners\AppUpdateListener;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Native\Desktop\Contracts\ProvidesPhpIni;
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
        $this->registerMenuClickListener();

        Window::open();
    }

    /**
     * Build the system menu bar. The "Check for Updates…" item used to live
     * here, but the NativePHP auto-updater on Windows uninstalled the running
     * app and then failed to install the replacement, leaving clients with a
     * removed POS. The menu entry, its click handler, and the underlying
     * AutoUpdater event listeners have all been removed until the updater is
     * proven safe on Windows. Updates are now distributed manually.
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
        );
    }

    /**
     * Wire menu-click events to the listener. The listener still routes the
     * Sync Now menu item; the auto-updater wiring was removed because the
     * NativePHP updater on Windows uninstalled the app without successfully
     * reinstalling, leaving clients without a working POS.
     */
    private function registerMenuClickListener(): void
    {
        Event::listen(MenuItemClicked::class, [AppUpdateListener::class, 'handleMenuClick']);
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
