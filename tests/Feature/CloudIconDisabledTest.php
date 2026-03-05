<?php

it('renders cloud icon slide-over sync status panel markup', function (): void {
    $contents = file_get_contents(resource_path('views/filament/store/partials/cloud-sync-status.blade.php'));

    expect($contents)
        ->toContain('Cloud Sync Status')
        ->toContain('x-data="{')
        ->toContain('open: false')
        ->toContain('dismissedErrors')
        ->toContain('x-show="hasRuntimeErrors && !dismissedErrors"')
        ->toContain('Open cloud sync status')
        ->toContain('startup.cloud.sync-now')
        ->toContain('startup.cloud.sync-module')
        ->toContain('startup.cloud.sync-log')
        ->not->toContain('Coming soon...');
});
