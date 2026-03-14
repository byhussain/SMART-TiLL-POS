<?php

it('uses bootstrap and delta orchestration in cloud sync job', function (): void {
    $contents = file_get_contents(app_path('Jobs/SyncCloudStoreData.php'));

    expect($contents)
        ->toContain('public string $action;')
        ->toContain('public ?string $resource;')
        ->toContain("\$action = \$runtimeStateService->isStoreBootstrapped(\$this->storeId) ? 'delta' : 'bootstrap';")
        ->toContain('->runBootstrapSync(')
        ->toContain('->runDeltaSync(');
});

it('supports bootstrap staging and delta endpoints in cloud sync service', function (): void {
    $contents = file_get_contents(app_path('Services/CloudSyncService.php'));

    expect($contents)
        ->toContain('public function runBootstrapSync(')
        ->toContain('public function runDeltaSync(')
        ->toContain('private function installBootstrapSnapshot(')
        ->toContain('private function pushPendingRowsV2(')
        ->toContain('/api/pos/v2/stores/');
});
