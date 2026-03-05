<?php

it('uses chunk cursor in cloud sync job for large first syncs', function (): void {
    $contents = file_get_contents(app_path('Jobs/SyncCloudStoreData.php'));

    expect($contents)
        ->toContain('public ?string $resource = null;')
        ->toContain('public int $page = 1;')
        ->toContain('foreach ($cloudSyncService->getSyncModuleKeys() as $moduleKey)')
        ->toContain('self::dispatch($this->storeId, (string) $moduleKey);')
        ->toContain('->syncChunk(')
        ->toContain('self::dispatch($this->storeId, $this->module, $nextResource, $nextPage);');
});

it('processes cloud pulls in fixed page chunks and returns continuation cursor', function (): void {
    $contents = file_get_contents(app_path('Services/CloudSyncService.php'));

    expect($contents)
        ->toContain('private const MAX_PAGES_PER_CHUNK = 3;')
        ->toContain('public function getSyncModuleKeys(): array')
        ->toContain('private function pullResourceChunk(')
        ->toContain("str_contains(strtolower(\$message), 'unsupported resource')")
        ->toContain("'next_resource'")
        ->toContain("'next_page'");
});
