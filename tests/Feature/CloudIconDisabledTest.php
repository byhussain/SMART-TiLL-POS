<?php

it('renders cloud icon slide-over sync status panel markup', function (): void {
    $contents = file_get_contents(resource_path('views/filament/store/partials/cloud-sync-status.blade.php'));

    // The icon now opens the drawer (previously it was a "Coming Soon"
    // stub). The tooltip text is computed from the runtime connection state.
    expect($contents)
        ->toContain('x-data="{')
        ->toContain('open: false')
        ->toContain('@click="open = ! open"')
        ->toContain('$headerTooltip')
        ->toContain('Cloud — not connected')
        ->toContain('Cloud connected — click for sync options')
        ->toContain('startup.cloud.sync-now')
        ->toContain('startup.cloud.sync-module')
        ->toContain('startup.cloud.sync-log')
        ->toContain('startup.cloud.reconcile');
});
