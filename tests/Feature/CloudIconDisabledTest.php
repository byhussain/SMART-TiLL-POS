<?php

it('renders cloud icon slide-over sync status panel markup', function (): void {
    $contents = file_get_contents(resource_path('views/filament/store/partials/cloud-sync-status.blade.php'));

    expect($contents)
        ->toContain('Cloud Sync Coming Soon')
        ->toContain('x-data="{')
        ->toContain('open: false')
        ->toContain('aria-label="Cloud sync coming soon"')
        ->toContain('group-hover:opacity-100')
        ->toContain('startup.cloud.sync-now')
        ->toContain('startup.cloud.sync-module')
        ->toContain('startup.cloud.sync-log')
        ->not->toContain('aria-label="Open cloud sync status"');
});
