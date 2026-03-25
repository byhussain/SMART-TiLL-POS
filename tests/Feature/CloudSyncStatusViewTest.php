<?php

it('shows module syncing state from the per-module map instead of the global queue flag', function (): void {
    $contents = file_get_contents(resource_path('views/filament/store/partials/cloud-sync-status.blade.php'));

    expect($contents)
        ->toContain(':disabled="isSyncSubmitting || isQueueSyncing || isBootstrapping || !!syncingModules[@js($moduleKey)] || !!moduleQueueSyncing[@js($moduleKey)]"')
        ->toContain('x-show="!!syncingModules[@js($moduleKey)] || !!moduleQueueSyncing[@js($moduleKey)] || isBootstrapping"')
        ->toContain('x-text="(syncingModules[@js($moduleKey)] || moduleQueueSyncing[@js($moduleKey)] || isBootstrapping) ? \'Syncing...\' : \'Sync\'"')
        ->not->toContain('x-show="!!syncingModules[@js($moduleKey)] || !!moduleQueueSyncing[@js($moduleKey)] || isQueueSyncing || isBootstrapping"')
        ->not->toContain("x-text=\"(syncingModules[@js(\$moduleKey)] || moduleQueueSyncing[@js(\$moduleKey)] || isQueueSyncing || isBootstrapping) ? 'Syncing...' : 'Sync'\"");
});
