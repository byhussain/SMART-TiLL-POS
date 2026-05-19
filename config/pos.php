<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloud bootstrap settings
    |--------------------------------------------------------------------------
    |
    | Controls how the POS pulls a store's initial dataset from the cloud.
    | Two paths are available:
    |
    |   - snapshot: server streams a gzipped, SQLite-compatible SQL dump
    |     that the POS imports in one transaction. ~10–50× faster than the
    |     legacy ndjson path on typical stores.
    |
    |   - ndjson (legacy): row-by-row pull through a manifest + paginated
    |     download. Always available as a fallback.
    |
    | The snapshot path is on by default. Set CLOUD_BOOTSTRAP_USE_SNAPSHOT=false
    | in your .env to disable it remotely — useful as a kill switch if a
    | snapshot install ever misbehaves in production.
    |
    */

    'bootstrap' => [
        'use_snapshot' => env('CLOUD_BOOTSTRAP_USE_SNAPSHOT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store-switch settings
    |--------------------------------------------------------------------------
    |
    | When a user picks a different store in the Filament UI, the middleware
    | pushes any unpushed pending edits for the leaving store before tearing
    | down its local data. The push is synchronous (blocks the request) so
    | edits aren't lost in transit — but it's bounded by this wall-clock
    | budget so a slow or offline server can never freeze the UI forever.
    | When the budget is exhausted the switch proceeds; the leaving store's
    | pending rows simply stay pending and ship on the next online visit.
    |
    */

    'switch' => [
        'push_timeout_seconds' => (int) env('CLOUD_SWITCH_PUSH_TIMEOUT', 15),
    ],

];
