<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Native\Desktop\Contracts\ProvidesPhpIni;
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

        Window::open();
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            // Bumped from the 30s default. NativePHP's bundled PHP runs every
            // request in a single-process, single-thread server; if anything
            // takes >30s the request dies mid-flight and floods the log.
            'max_execution_time' => '120',
            // SQLite + multiple Native sub-processes (web request + queue
            // workers) racing on the same DB file cause "database is locked"
            // unless we wait. 60s busy timeout matches our env DB_BUSY_TIMEOUT.
            'sqlite3.defensive' => '1',
            // Production realpath cache helps Windows file IO meaningfully.
            'realpath_cache_size' => '4096K',
            'realpath_cache_ttl' => '600',
            // OPcache for the bundled PHP runtime — biggest single win on Win.
            'opcache.enable' => '1',
            'opcache.enable_cli' => '0',
            'opcache.memory_consumption' => '256',
            'opcache.max_accelerated_files' => '20000',
            'opcache.validate_timestamps' => '0',
        ];
    }
}
