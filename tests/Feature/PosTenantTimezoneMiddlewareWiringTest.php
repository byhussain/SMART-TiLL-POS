<?php

it('wires tenant timezone middleware for store panel', function (): void {
    $providerContents = file_get_contents(base_path('app/Providers/Filament/StorePanelProvider.php'));

    expect($providerContents)
        ->toContain('use SmartTill\Core\Http\Middleware\SetTenantTimezone as CoreSetTenantTimezone;')
        ->toContain('->tenantMiddleware([')
        ->toContain('CoreSetTenantTimezone::class')
        ->toContain('isPersistent: true');
});
